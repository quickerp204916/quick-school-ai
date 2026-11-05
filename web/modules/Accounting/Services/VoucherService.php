<?php

namespace Modules\Accounting\Services;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Academic\Support\Numbering;
use Modules\Academic\Support\Rules;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\CostCentre;
use Modules\Accounting\Models\FinancialYear;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Models\Voucher;
use Modules\Accounting\Support\Amount;

/**
 * The posting engine. Every rule of the books is enforced here:
 *  - double entry: at least two lines, each line one-sided, debits equal credits;
 *  - posted vouchers are immutable (also enforced by the database), corrections are reversals;
 *  - nothing is posted into a closed year or on or before the lock date;
 *  - vouchers at or above the approval threshold need a second person (maker-checker);
 *  - an idempotency key or source document is posted at most once;
 *  - numbers are gapless per voucher type and financial year, given at posting.
 */
final class VoucherService
{
    public function __construct(private readonly CurrentMember $member)
    {
    }

    /* ---------------- drafts and approval ---------------- */

    /** Manual voucher (payment, receipt, contra, journal) saved as a draft. Lines: [ledger_id, debit, credit, cost_centre_id?, narration?, bill_ref?] in rupees or paise. */
    public function saveDraft(array $data, ?Voucher $voucher = null): Voucher
    {
        $this->member->authorize('accounts.post');
        $d = Rules::check($data, [
            'type' => ['required', Rule::in(config('accounting.manual_types'))],
            'date' => ['required', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', Rules::exists('branches')],
            'narration' => ['nullable', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:60'],
            'lines' => ['required', 'array', 'min:2'],
        ], ['lines.min' => 'A voucher needs at least one debit and one credit line.']);
        if ($voucher && $voucher->status !== 'draft' && $voucher->status !== 'rejected') {
            throw new AccountingException('Only draft or rejected vouchers can be edited.');
        }
        $lines = $this->normalise($data['lines']);
        $this->validate($d['type'], $d['date'], $lines);

        $voucher ??= new Voucher(['created_by' => $this->member->userId()]);
        $voucher->fill(['type' => $d['type'], 'date' => $d['date'], 'branch_id' => $d['branch_id'] ?? null, 'narration' => $d['narration'] ?? null,
            'reference' => $d['reference'] ?? null, 'lines' => $lines, 'total' => array_sum(array_column($lines, 'debit')),
            'financial_year_id' => FinancialYear::for($d['date'])->id, 'status' => 'draft', 'rejected_reason' => null])->save();
        Audit::record('voucher.saved', $voucher, ['type' => $voucher->type, 'total' => Amount::format($voucher->total)]);

        return $voucher;
    }

    /** Sends the draft on: posted at once below the approval threshold, otherwise waiting for approval. */
    public function submit(Voucher $voucher): Voucher
    {
        $this->member->authorize('accounts.post');
        if (! in_array($voucher->status, ['draft', 'rejected'], true)) {
            throw new AccountingException("This voucher is {$voucher->status}.");
        }
        $this->validate($voucher->type, $voucher->date->format('Y-m-d'), $voucher->lines);
        if ($this->needsApproval($voucher)) {
            $voucher->update(['status' => 'pending', 'submitted_at' => now()]);
            Audit::record('voucher.submitted', $voucher, ['total' => Amount::format($voucher->total)]);

            return $voucher;
        }

        return $this->post($voucher);
    }

    public function needsApproval(Voucher $voucher): bool
    {
        $threshold = (AccountingSetting::current()->approval_thresholds ?? [])[$voucher->type] ?? null;

        return $threshold !== null && $voucher->total >= (int) $threshold;
    }

    public function approve(Voucher $voucher): Voucher
    {
        $this->member->authorize('accounts.approve');
        if ($voucher->status !== 'pending') {
            throw new AccountingException('Only vouchers waiting for approval can be approved.');
        }
        if ($voucher->created_by === $this->member->userId() && ! AccountingSetting::current()->allow_self_approval) {
            throw new AccountingException('You entered this voucher, so someone else must approve it.');
        }
        $voucher->forceFill(['approved_by' => $this->member->userId(), 'approved_at' => now()]);

        return $this->post($voucher);
    }

    public function reject(Voucher $voucher, string $reason): Voucher
    {
        $this->member->authorize('accounts.approve');
        if ($voucher->status !== 'pending') {
            throw new AccountingException('Only vouchers waiting for approval can be rejected.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new AccountingException('Give the reason for rejecting, so the person who entered it can fix it.', 'reason');
        }
        $voucher->update(['status' => 'rejected', 'rejected_reason' => trim($reason)]);
        Audit::record('voucher.rejected', $voucher, ['reason' => $reason]);

        return $voucher;
    }

    /* ---------------- automatic postings from other modules ---------------- */

    /**
     * Posts a voucher for a source document (fee payment, payroll run, settlement, depreciation, invoice) exactly once.
     * The source must already be approved in its own module. Repeating the call returns the first voucher;
     * a repeat with different amounts is refused as a conflict.
     *
     * @param  list<array{ledger_id?: string, key?: string, debit?: int, credit?: int, cost_centre_id?: ?string, narration?: ?string, bill_ref?: ?string}>  $lines  amounts in paise
     */
    public function record(string $type, string $date, array $lines, string $sourceType, string $sourceId, ?string $narration = null,
        ?string $branchId = null, ?string $reference = null, ?string $idempotencyKey = null): Voucher
    {
        $key = $idempotencyKey ?? "{$sourceType}:{$sourceId}";
        $lines = $this->normalise(array_map(fn ($l) => isset($l['key']) ? ['ledger_id' => Ledger::system($l['key'])->id] + $l : $l, $lines));
        $total = array_sum(array_column($lines, 'debit'));

        return DB::transaction(function () use ($type, $date, $lines, $sourceType, $sourceId, $narration, $branchId, $reference, $key, $total) {
            if ($existing = Voucher::where('idempotency_key', $key)->lockForUpdate()->first()) {
                if ($existing->total !== $total) {
                    throw new AccountingException("Already posted as {$existing->number} with a different amount (".Amount::format($existing->total).'). Reverse it first.');
                }

                return $existing;
            }
            $this->validate($type, $date, $lines, manual: false);
            $v = Voucher::create(['type' => $type, 'date' => $date, 'branch_id' => $branchId, 'narration' => $narration, 'reference' => $reference,
                'lines' => $lines, 'total' => $total, 'source_type' => $sourceType, 'source_id' => $sourceId, 'idempotency_key' => $key,
                'financial_year_id' => FinancialYear::for($date)->id, 'status' => 'draft', 'created_by' => $this->member->userId()]);

            return $this->post($v);
        });
    }

    /* ---------------- reversal ---------------- */

    /** The only way to correct a posted voucher: a new voucher with every line on the other side. */
    public function reverse(Voucher $voucher, string $reason, ?string $date = null): Voucher
    {
        $this->member->authorize('accounts.approve');
        if ($voucher->status !== 'posted') {
            throw new AccountingException($voucher->status === 'reversed' ? 'This voucher has already been reversed.' : 'Only posted vouchers can be reversed.');
        }
        if ($voucher->type === 'reversal') {
            throw new AccountingException('A reversal cannot itself be reversed. Post a new voucher instead.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new AccountingException('Give the reason for the reversal.', 'reason');
        }
        $date ??= today()->format('Y-m-d');
        if ($date < $voucher->date->format('Y-m-d')) {
            throw new AccountingException('The reversal cannot be dated before the original voucher.', 'date');
        }

        return DB::transaction(function () use ($voucher, $reason, $date) {
            $locked = Voucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'posted') {
                throw new AccountingException('This voucher has already been reversed.');
            }
            $lines = $locked->entries->map(fn (LedgerEntry $e) => ['ledger_id' => $e->ledger_id, 'debit' => $e->credit, 'credit' => $e->debit,
                'cost_centre_id' => $e->cost_centre_id, 'narration' => $e->narration, 'bill_ref' => $e->bill_ref])->all();
            $this->validate('reversal', $date, $lines, manual: false);
            $rev = Voucher::create(['type' => 'reversal', 'date' => $date, 'branch_id' => $locked->branch_id, 'reversal_of_id' => $locked->id,
                'narration' => "Reversal of {$locked->number}: ".trim($reason), 'lines' => $lines, 'total' => $locked->total,
                'source_type' => $locked->source_type, 'source_id' => $locked->source_id, 'idempotency_key' => "reversal:{$locked->id}",
                'financial_year_id' => FinancialYear::for($date)->id, 'status' => 'draft', 'created_by' => $this->member->userId(),
                'approved_by' => $this->member->userId(), 'approved_at' => now()]);
            $rev = $this->post($rev);
            $locked->update(['status' => 'reversed', 'reversed_by_id' => $rev->id]);
            if ($locked->source_type === 'invoice') {
                app(InvoiceService::class)->onReversed($locked);
            }
            Audit::record('voucher.reversed', $locked, ['reversal' => $rev->number, 'reason' => $reason]);

            return $rev;
        });
    }

    /* ---------------- posting ---------------- */

    private function post(Voucher $voucher): Voucher
    {
        return DB::transaction(function () use ($voucher) {
            $v = Voucher::whereKey($voucher->id)->lockForUpdate()->first() ?? $voucher;
            if ($v->isPosted()) {
                throw new AccountingException("Already posted as {$v->number}.");
            }
            $date = $v->date->format('Y-m-d');
            $this->validate($v->type, $date, $v->lines, manual: in_array($v->type, config('accounting.manual_types'), true));
            $fy = FinancialYear::for($date);
            $prefix = config("accounting.voucher_types.{$v->type}.prefix");
            $number = sprintf('%s/%s/%05d', $prefix, $fy->name, Numbering::next('V-'.$prefix, $fy->name));

            $rows = [];
            foreach (array_values($v->lines) as $i => $l) {
                $rows[] = LedgerEntry::create(['voucher_id' => $v->id, 'ledger_id' => $l['ledger_id'], 'branch_id' => $v->branch_id, 'cost_centre_id' => $l['cost_centre_id'] ?? null,
                    'date' => $date, 'line_no' => $i + 1, 'debit' => $l['debit'], 'credit' => $l['credit'], 'narration' => $l['narration'] ?? null, 'bill_ref' => $l['bill_ref'] ?? null]);
            }
            $hash = hash('sha256', $number.'|'.$date.'|'.collect($rows)->map(fn ($e) => "{$e->ledger_id}:{$e->debit}:{$e->credit}")->implode(';'));
            $v->forceFill(['number' => $number, 'financial_year_id' => $fy->id, 'status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->member->userId(),
                'approved_by' => $voucher->approved_by ?? $v->approved_by, 'approved_at' => $voucher->approved_at ?? $v->approved_at, 'entries_hash' => $hash])->save();
            if ($v->source_type === 'invoice') {
                app(InvoiceService::class)->onPosted($v);
            }
            Audit::record('voucher.posted', $v, ['number' => $number, 'total' => Amount::format($v->total)]);

            return $v;
        });
    }

    /* ---------------- rules ---------------- */

    /** @return list<array{ledger_id: string, debit: int, credit: int, cost_centre_id: ?string, narration: ?string, bill_ref: ?string}> */
    public function normalise(array $lines): array
    {
        $out = [];
        foreach (array_values($lines) as $i => $l) {
            $n = $i + 1;
            if (empty($l['ledger_id']) && Amount::parse($l['debit'] ?? 0) === 0 && Amount::parse($l['credit'] ?? 0) === 0) {
                continue;   // empty row in the form
            }
            try {
                $debit = Amount::parse($l['debit'] ?? 0);
                $credit = Amount::parse($l['credit'] ?? 0);
            } catch (\InvalidArgumentException) {
                throw new AccountingException("Line {$n}: enter amounts like 1500 or 1500.50.", "lines.{$i}");
            }
            $out[] = ['ledger_id' => (string) ($l['ledger_id'] ?? ''), 'debit' => $debit, 'credit' => $credit,
                'cost_centre_id' => ($l['cost_centre_id'] ?? null) ?: null, 'narration' => ($l['narration'] ?? null) ?: null, 'bill_ref' => ($l['bill_ref'] ?? null) ?: null];
        }

        return $out;
    }

    public function validate(string $type, string $date, array $lines, bool $manual = true): void
    {
        if (! array_key_exists($type, config('accounting.voucher_types'))) {
            throw new AccountingException('Unknown voucher type.', 'type');
        }
        if (count($lines) < 2) {
            throw new AccountingException('A voucher needs at least one debit and one credit line.', 'lines');
        }
        $ledgers = Ledger::whereIn('id', array_filter(array_column($lines, 'ledger_id'), fn ($id) => \Illuminate\Support\Str::isUuid((string) $id)))->get()->keyBy('id');
        $dr = $cr = 0;
        $cashDr = $cashCr = $other = 0;
        foreach ($lines as $i => $l) {
            $n = $i + 1;
            $ledger = $ledgers[$l['ledger_id']] ?? null;
            if (! $ledger) {
                throw new AccountingException("Line {$n}: choose a ledger.", "lines.{$i}");
            }
            if (! $ledger->is_active) {
                throw new AccountingException("Line {$n}: {$ledger->name} is inactive.", "lines.{$i}");
            }
            if ($l['debit'] < 0 || $l['credit'] < 0 || ($l['debit'] > 0) === ($l['credit'] > 0)) {
                throw new AccountingException("Line {$n}: enter either a debit or a credit, above zero.", "lines.{$i}");
            }
            if (! empty($l['cost_centre_id']) && ! CostCentre::whereKey($l['cost_centre_id'])->where('is_active', true)->exists()) {
                throw new AccountingException("Line {$n}: choose an active cost centre.", "lines.{$i}");
            }
            $dr += $l['debit'];
            $cr += $l['credit'];
            if ($ledger->isCashOrBank()) {
                $cashDr += $l['debit'];
                $cashCr += $l['credit'];
            } else {
                $other++;
            }
        }
        if ($dr !== $cr) {
            throw new AccountingException('Debits ('.Amount::format($dr).') and credits ('.Amount::format($cr).') must be equal. Difference: '.Amount::format(abs($dr - $cr)).'.', 'lines');
        }
        if ($manual) {
            match ($type) {
                'payment' => $cashCr > 0 && $other > 0 ?: throw new AccountingException('A payment voucher pays out of cash or bank: credit a cash or bank ledger and debit the expense or party.', 'lines'),
                'receipt' => $cashDr > 0 && $other > 0 ?: throw new AccountingException('A receipt voucher brings money into cash or bank: debit a cash or bank ledger and credit the income or party.', 'lines'),
                'contra' => $other === 0 ?: throw new AccountingException('A contra voucher moves money only between cash and bank ledgers.', 'lines'),
                'journal' => $cashDr + $cashCr === 0 ?: throw new AccountingException('A journal voucher cannot use cash or bank ledgers. Use a payment, receipt or contra voucher.', 'lines'),
                default => null,
            };
        }
        $this->checkPeriodOpen($date);
    }

    public function checkPeriodOpen(string $date): void
    {
        $fy = FinancialYear::for($date);
        if (! $fy) {
            throw new AccountingException('There is no financial year for '.Carbon::parse($date)->format('j M Y').'. Add it in accounting settings.', 'date');
        }
        if ($fy->status === 'closed') {
            throw new AccountingException("Financial year {$fy->name} is closed.", 'date');
        }
        $lock = AccountingSetting::current()->locked_until?->format('Y-m-d');
        if ($lock && $date <= $lock) {
            throw new AccountingException('The books are locked up to '.Carbon::parse($lock)->format('j M Y').'. Choose a later date, or ask the owner to move the lock date.', 'date');
        }
    }
}
