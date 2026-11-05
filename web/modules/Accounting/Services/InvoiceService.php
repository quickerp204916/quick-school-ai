<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Academic\Support\Numbering;
use Modules\Academic\Support\Rules;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\FinancialYear;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Models\TdsSection;
use Modules\Accounting\Models\Voucher;
use Modules\Accounting\Support\Amount;

/**
 * Sales and purchase invoices, credit and debit notes, and expenses, with GST and TDS.
 *
 * GST: CGST + SGST (half each) when the place of supply is the institution's own state, IGST otherwise.
 * TDS on purchases and expenses: from the vendor's section, on the value before GST, when the bill or the
 * year's total with that vendor crosses the section's threshold; the no-PAN rate applies without a PAN.
 * Each document posts one voucher through the posting engine, so approvals, locks and reversals apply.
 */
final class InvoiceService
{
    public function __construct(private readonly VoucherService $vouchers, private readonly CurrentMember $member)
    {
    }

    public function save(string $kind, array $data, ?Invoice $invoice = null): Invoice
    {
        $this->member->authorize('accounts.post');
        if (! in_array($kind, Invoice::KINDS, true)) {
            throw new AccountingException('Unknown document type.');
        }
        if ($invoice && ! in_array($invoice->status, ['draft'], true)) {
            throw new AccountingException('Only drafts can be edited. Reverse the posted voucher to cancel it.');
        }
        $salesSide = in_array($kind, ['sales', 'credit_note'], true);
        $d = Rules::check($data, [
            'party_ledger_id' => ['required', Rules::exists('ledgers', ['is_active' => true])],
            'date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'branch_id' => ['nullable', Rules::exists('branches')],
            'place_of_supply' => ['nullable', 'string', 'size:2'],
            'party_invoice_no' => [in_array($kind, ['purchase'], true) ? 'required' : 'nullable', 'string', 'max:40'],
            'against_invoice_id' => [in_array($kind, ['credit_note', 'debit_note'], true) ? 'required' : 'nullable', Rules::exists('invoices', ['status' => 'posted'])],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ledger_id' => ['required', Rules::exists('ledgers', ['is_active' => true])],
            'lines.*.description' => ['required', 'string', 'max:200'],
            'lines.*.hsn_sac' => ['nullable', 'string', 'max:10'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'lines.*.rate' => ['required'],
            'lines.*.gst_bp' => ['nullable', 'integer', Rule::in(array_map(fn ($r) => $r[1], config('accounting.gst_rates')))],
            'lines.*.cost_centre_id' => ['nullable', Rules::exists('cost_centres', ['is_active' => true])],
        ], ['party_invoice_no.required' => 'Enter the supplier’s bill number.', 'against_invoice_id.required' => 'Choose the posted invoice this note adjusts.',
            'lines.*.description.required' => 'Describe each line.', 'lines.*.quantity.gt' => 'Quantity must be more than zero.']);
        $party = Ledger::with('group')->findOrFail($d['party_ledger_id']);
        if (! in_array($party->type, $salesSide ? ['customer', 'student'] : ['vendor', 'employee'], true)) {
            throw new AccountingException($salesSide ? 'Choose a customer ledger.' : 'Choose a vendor ledger.', 'party_ledger_id');
        }
        $this->vouchers->checkPeriodOpen($d['date']);
        if ($kind === 'purchase' && Invoice::where('kind', 'purchase')->where('party_ledger_id', $party->id)->where('party_invoice_no', $d['party_invoice_no'])
            ->where('status', '!=', 'cancelled')->when($invoice, fn ($q) => $q->whereKeyNot($invoice->id))->exists()) {
            throw new AccountingException("Bill {$d['party_invoice_no']} from {$party->name} is already entered.", 'party_invoice_no');
        }

        $settings = AccountingSetting::current();
        $pos = $d['place_of_supply'] ?? $party->state_code ?? $settings->state_code;
        $intra = ! $pos || ! $settings->state_code || $pos === $settings->state_code;
        $lines = [];
        $sub = $cgst = $sgst = $igst = 0;
        foreach ($d['lines'] as $i => $l) {
            try {
                $rate = Amount::parse($l['rate']);
            } catch (\InvalidArgumentException) {
                throw new AccountingException('Line '.($i + 1).': enter the rate like 1500 or 1500.50.', "lines.{$i}.rate");
            }
            $qty = (string) $l['quantity'];
            $amount = (int) round($rate * (float) $qty);
            if ($amount <= 0) {
                throw new AccountingException('Line '.($i + 1).': the amount must be more than zero.', "lines.{$i}.rate");
            }
            $bp = (int) ($l['gst_bp'] ?? 0);
            $tax = intdiv($amount * $bp + 5000, 10000);
            if ($intra) {
                $c = intdiv($tax, 2);
                $cgst += $c;
                $sgst += $tax - $c;
            } else {
                $igst += $tax;
            }
            $sub += $amount;
            $lines[] = ['ledger_id' => $l['ledger_id'], 'description' => $l['description'], 'hsn_sac' => $l['hsn_sac'] ?? null, 'quantity' => $qty,
                'rate' => $rate, 'amount' => $amount, 'gst_bp' => $bp, 'cost_centre_id' => $l['cost_centre_id'] ?? null];
        }
        $total = $sub + $cgst + $sgst + $igst;

        $against = null;
        if (in_array($kind, ['credit_note', 'debit_note'], true)) {
            $against = Invoice::findOrFail($d['against_invoice_id']);
            $wanted = $kind === 'credit_note' ? 'sales' : ['purchase', 'expense'];
            if (! in_array($against->kind, (array) $wanted, true) || $against->party_ledger_id !== $party->id) {
                throw new AccountingException($kind === 'credit_note' ? 'A credit note adjusts a sales invoice of the same customer.' : 'A debit note adjusts a purchase of the same vendor.', 'against_invoice_id');
            }
            $used = Invoice::where('against_invoice_id', $against->id)->whereIn('status', ['posted', 'pending'])->when($invoice, fn ($q) => $q->whereKeyNot($invoice->id))->sum('total');
            if ($total > $against->total - $used) {
                throw new AccountingException('The note is more than what is left on '.$against->number.' ('.Amount::format($against->total - $used).').', 'lines');
            }
        }

        [$section, $tds] = in_array($kind, ['purchase', 'expense'], true) ? $this->tds($party, $sub, $d['date'], $invoice) : [null, 0];

        return DB::transaction(function () use ($kind, $d, $invoice, $party, $pos, $lines, $sub, $cgst, $sgst, $igst, $total, $section, $tds) {
            $invoice ??= new Invoice(['kind' => $kind, 'status' => 'draft', 'created_by' => $this->member->userId()]);
            $invoice->fill(['party_ledger_id' => $party->id, 'date' => $d['date'], 'due_date' => $d['due_date'] ?? null, 'branch_id' => $d['branch_id'] ?? null,
                'place_of_supply' => $pos, 'party_invoice_no' => $d['party_invoice_no'] ?? null, 'against_invoice_id' => $d['against_invoice_id'] ?? null,
                'notes' => $d['notes'] ?? null, 'subtotal' => $sub, 'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst, 'total' => $total,
                'tds_section_id' => $section?->id, 'tds' => $tds, 'net' => $total - $tds])->save();
            $invoice->lines()->delete();
            foreach ($lines as $l) {
                $invoice->lines()->create($l);
            }
            Audit::record('invoice.saved', $invoice, ['kind' => $kind, 'total' => Amount::format($total)]);

            return $invoice->load('lines');
        });
    }

    /** @return array{0: ?TdsSection, 1: int} */
    public function tds(Ledger $party, int $subtotal, string $date, ?Invoice $except = null): array
    {
        $code = $party->tdsSection?->code;
        $section = $code ? TdsSection::inForce($code, $date) : null;
        if (! $section) {
            return [null, 0];
        }
        $fy = FinancialYear::for($date);
        $yearSoFar = (int) Invoice::whereIn('kind', ['purchase', 'expense'])->where('party_ledger_id', $party->id)->whereIn('status', ['posted', 'pending'])
            ->whereBetween('date', [$fy->starts_on->format('Y-m-d'), $fy->ends_on->format('Y-m-d')])->when($except, fn ($q) => $q->whereKeyNot($except->id))->sum('subtotal');
        $applies = ($section->threshold_single > 0 && $subtotal > $section->threshold_single)
            || ($section->threshold_annual > 0 && $yearSoFar + $subtotal > $section->threshold_annual);
        if (! $applies) {
            return [$section, 0];
        }
        $rate = $party->pan ? $section->rate_bp : $section->rate_no_pan_bp;

        return [$section, intdiv($subtotal * $rate + 5000, 10000)];
    }

    /** Builds the voucher and sends it through approval; posted at once below the threshold. */
    public function submit(Invoice $invoice): Invoice
    {
        $this->member->authorize('accounts.post');
        if ($invoice->status !== 'draft') {
            throw new AccountingException("This document is {$invoice->status}.");
        }

        return DB::transaction(function () use ($invoice) {
            $v = Voucher::create(['type' => $invoice->kind, 'date' => $invoice->date->format('Y-m-d'), 'branch_id' => $invoice->branch_id,
                'narration' => $invoice->kindLabel().' · '.$invoice->party->name.($invoice->party_invoice_no ? ' · bill '.$invoice->party_invoice_no : ''),
                'reference' => $invoice->party_invoice_no, 'lines' => $this->voucherLines($invoice), 'total' => 0, 'source_type' => 'invoice', 'source_id' => $invoice->id,
                'idempotency_key' => "invoice:{$invoice->id}", 'financial_year_id' => FinancialYear::for($invoice->date)->id, 'status' => 'draft', 'created_by' => $this->member->userId()]);
            $v->update(['total' => array_sum(array_column($v->lines, 'debit'))]);
            $invoice->update(['voucher_id' => $v->id, 'status' => 'pending']);
            $this->vouchers->submit($v);

            return $invoice->fresh();
        });
    }

    public function voucherLines(Invoice $inv): array
    {
        $inv->loadMissing('lines', 'against');
        $sales = $inv->isSalesSide();
        $ref = $inv->kind === 'credit_note' || $inv->kind === 'debit_note' ? $inv->against->number : null;
        $tax = fn (string $side) => array_values(array_filter([
            ['key' => "gst_{$side}_cgst", 'amount' => $inv->cgst], ['key' => "gst_{$side}_sgst", 'amount' => $inv->sgst], ['key' => "gst_{$side}_igst", 'amount' => $inv->igst],
        ], fn ($t) => $t['amount'] > 0));
        $lines = [];
        $party = fn (int $amount, bool $debit) => ['ledger_id' => $inv->party_ledger_id, 'debit' => $debit ? $amount : 0, 'credit' => $debit ? 0 : $amount, 'bill_ref' => $ref, 'narration' => $inv->kindLabel()];
        // "Normal" direction: sales credit income, purchases debit expense. Notes flip it.
        $normal = in_array($inv->kind, ['sales', 'purchase', 'expense'], true);
        $itemDebit = $sales ? ! $normal : $normal;
        foreach ($inv->lines as $l) {
            $lines[] = ['ledger_id' => $l->ledger_id, 'debit' => $itemDebit ? $l->amount : 0, 'credit' => $itemDebit ? 0 : $l->amount, 'cost_centre_id' => $l->cost_centre_id, 'narration' => $l->description];
        }
        foreach ($tax($sales ? 'output' : 'input') as $t) {
            $lines[] = ['ledger_id' => Ledger::system($t['key'])->id, 'debit' => $itemDebit ? $t['amount'] : 0, 'credit' => $itemDebit ? 0 : $t['amount']];
        }
        $lines[] = $party($inv->net, ! $itemDebit);
        if ($inv->tds > 0) {
            $lines[] = ['ledger_id' => Ledger::system('tds_payable')->id, 'debit' => 0, 'credit' => $inv->tds, 'narration' => 'TDS u/s '.$inv->tdsSection?->code];
        }

        return $this->vouchers->normalise($lines);
    }

    /** Called by the posting engine when the document's voucher posts: the document gets its own gapless number. */
    public function onPosted(Voucher $v): void
    {
        $inv = Invoice::find($v->source_id);
        if (! $inv || $inv->status === 'posted') {
            return;
        }
        $fy = FinancialYear::for($inv->date);
        $series = config("accounting.invoice_series.{$inv->kind}");
        $inv->update(['status' => 'posted', 'number' => sprintf('%s/%s/%05d', $series, $fy->name, Numbering::next('INV-'.$series, $fy->name))]);
    }

    public function onReversed(Voucher $v): void
    {
        Invoice::whereKey($v->source_id)->update(['status' => 'cancelled']);
    }

    /** What is still owed on a posted document: net less receipts or payments that name it, less notes against it. */
    public function outstanding(Invoice $inv): int
    {
        if ($inv->status !== 'posted' || in_array($inv->kind, ['credit_note', 'debit_note'], true)) {
            return 0;
        }
        $settled = LedgerEntry::where('ledger_id', $inv->party_ledger_id)->where('bill_ref', $inv->number)
            ->whereHas('voucher', fn ($q) => $q->whereIn('status', ['posted', 'reversed']))->get();
        $sum = $inv->isSalesSide() ? $settled->sum('credit') - $settled->sum('debit') : $settled->sum('debit') - $settled->sum('credit');

        return max(0, $inv->net - $sum);
    }
}
