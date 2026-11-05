<?php

namespace Modules\Accounting\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Support\CurrentMember;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\Voucher;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\InvoiceService;
use Modules\Accounting\Services\VoucherService;

/**
 * Accounting API. Amounts are returned in paise and as display strings; amounts sent may be rupee strings ("1500.50") or paise integers.
 * Creating vouchers or invoices accepts an Idempotency-Key header: a retried request returns the first result instead of posting twice.
 */
class AccountingApiController
{
    public function __construct(private readonly CurrentMember $member, private readonly VoucherService $vouchers, private readonly Books $books)
    {
    }

    public function ledgers(Request $request): JsonResponse
    {
        $this->member->authorize('accounts.view');
        $asOn = $request->input('as_on', today()->format('Y-m-d'));

        return response()->json(['data' => Ledger::with('group')->orderBy('code')->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))->get()
            ->map(fn ($l) => ['id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'type' => $l->type, 'group' => $l->group->name, 'nature' => $l->group->nature,
                'system_key' => $l->system_key, 'active' => $l->is_active, 'balance' => $this->books->balance($l, $asOn)])]);
    }

    public function vouchers(Request $request): JsonResponse
    {
        $this->member->authorize('accounts.view');
        $page = Voucher::query()->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('from'), fn ($q) => $q->where('date', '>=', $request->from))->when($request->filled('to'), fn ($q) => $q->where('date', '<=', $request->to))
            ->orderByDesc('date')->paginate(50);

        return response()->json(['data' => collect($page->items())->map(fn ($v) => self::voucher($v)), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage()]]);
    }

    public function voucherShow(Voucher $voucher): JsonResponse
    {
        $this->member->authorize('accounts.view');

        return response()->json(['data' => self::voucher($voucher->load('entries'), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if ($key && ($existing = Voucher::where('idempotency_key', 'api:'.$key)->first())) {
            return response()->json(['data' => self::voucher($existing->load('entries'), true)])->header('Idempotent-Replayed', 'true');
        }
        $v = DB::transaction(function () use ($request, $key) {
            $v = $this->vouchers->saveDraft($request->all());
            if ($key) {
                $v->forceFill(['idempotency_key' => 'api:'.$key])->save();
            }

            return $request->boolean('draft') ? $v : $this->vouchers->submit($v);
        });

        return response()->json(['data' => self::voucher($v->load('entries'), true)], 201);
    }

    public function approve(Voucher $voucher): JsonResponse
    {
        return response()->json(['data' => self::voucher($this->vouchers->approve($voucher)->load('entries'), true)]);
    }

    public function reject(Request $request, Voucher $voucher): JsonResponse
    {
        return response()->json(['data' => self::voucher($this->vouchers->reject($voucher, (string) $request->input('reason')))]);
    }

    public function reverse(Request $request, Voucher $voucher): JsonResponse
    {
        return response()->json(['data' => self::voucher($this->vouchers->reverse($voucher, (string) $request->input('reason'), $request->input('date'))->load('entries'), true)], 201);
    }

    public function storeInvoice(Request $request, InvoiceService $invoices): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if ($key && ($existing = Voucher::where('idempotency_key', 'api-invoice:'.$key)->first())) {
            return response()->json(['data' => self::invoice(Invoice::findOrFail($existing->source_id))])->header('Idempotent-Replayed', 'true');
        }
        $inv = DB::transaction(function () use ($request, $invoices, $key) {
            $inv = $invoices->save((string) $request->input('kind'), $request->all());
            $inv = $request->boolean('draft') ? $inv : $invoices->submit($inv);
            if ($key && $inv->voucher_id) {
                Voucher::whereKey($inv->voucher_id)->first()->forceFill(['idempotency_key' => 'api-invoice:'.$key])->saveQuietly();
            }

            return $inv;
        });

        return response()->json(['data' => self::invoice($inv)], 201);
    }

    public function report(Request $request, string $report): JsonResponse
    {
        $this->member->authorize('accounts.view');
        $from = $request->input('from', \Modules\Accounting\Models\FinancialYear::current()?->starts_on->format('Y-m-d'));
        $to = $request->input('to', today()->format('Y-m-d'));
        $branch = $request->input('branch');
        $tree = fn ($nodes) => collect($nodes)->map(fn ($n) => ['group' => $n['group']->name, 'total' => $n['total'],
            'ledgers' => $n['ledgers']->map(fn ($l) => ['code' => $l['ledger']->code, 'name' => $l['ledger']->name, 'amount' => $l['amount']])->all()])->all();

        return response()->json(match ($report) {
            'trial-balance' => (fn ($tb) => ['from' => $from, 'to' => $to, 'rows' => $tb['rows']->map(fn ($r) => ['code' => $r['ledger']->code, 'name' => $r['ledger']->name,
                'opening' => $r['opening'], 'debit' => $r['debit'], 'credit' => $r['credit'], 'closing' => $r['closing']])->all()] + collect($tb)->except('rows')->all())($this->books->trialBalance($from, $to, $branch)),
            'profit-loss' => (fn ($pl) => ['from' => $from, 'to' => $to, 'income' => $tree($pl['income']), 'expense' => $tree($pl['expense']),
                'total_income' => $pl['total_income'], 'total_expense' => $pl['total_expense'], 'surplus' => $pl['surplus']])($this->books->profitAndLoss($from, $to, $branch)),
            'balance-sheet' => (fn ($bs) => ['as_on' => $to, 'assets' => $tree($bs['assets']), 'liabilities' => $tree($bs['liabilities']), 'equity' => $tree($bs['equity']),
                'surplus' => $bs['surplus'], 'total_assets' => $bs['total_assets'], 'total_liabilities' => $bs['total_liabilities'], 'difference' => $bs['difference']])($this->books->balanceSheet($to, $branch)),
            'cash-flow' => ['from' => $from, 'to' => $to] + $this->books->cashFlow($from, $to, $branch),
            default => abort(404, 'Unknown report.'),
        });
    }

    public static function voucher(Voucher $v, bool $full = false): array
    {
        $base = ['id' => $v->id, 'number' => $v->number, 'type' => $v->type, 'date' => $v->date->format('Y-m-d'), 'status' => $v->status, 'total' => $v->total,
            'total_display' => \Modules\Accounting\Support\Amount::format($v->total), 'narration' => $v->narration, 'reference' => $v->reference,
            'source' => $v->source_type ? ['type' => $v->source_type, 'id' => $v->source_id] : null, 'reversed_by' => $v->reversed_by_id, 'reversal_of' => $v->reversal_of_id];

        return $full ? $base + ['lines' => $v->isPosted() ? $v->entries->map(fn ($e) => ['ledger_id' => $e->ledger_id, 'debit' => $e->debit, 'credit' => $e->credit,
            'cost_centre_id' => $e->cost_centre_id, 'bill_ref' => $e->bill_ref])->all() : $v->lines, 'entries_hash' => $v->entries_hash] : $base;
    }

    public static function invoice(Invoice $i): array
    {
        return ['id' => $i->id, 'kind' => $i->kind, 'number' => $i->number, 'status' => $i->status, 'date' => $i->date->format('Y-m-d'), 'party_ledger_id' => $i->party_ledger_id,
            'subtotal' => $i->subtotal, 'cgst' => $i->cgst, 'sgst' => $i->sgst, 'igst' => $i->igst, 'total' => $i->total, 'tds' => $i->tds, 'net' => $i->net, 'voucher_id' => $i->voucher_id];
    }
}
