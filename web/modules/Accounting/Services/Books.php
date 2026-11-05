<?php

namespace Modules\Accounting\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Accounting\Models\AccountGroup;
use Modules\Accounting\Models\Budget;
use Modules\Accounting\Models\FinancialYear;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Models\Voucher;

/**
 * Reports read only posted entries (vouchers posted or later reversed: a reversal is its own posted voucher,
 * so the two cancel out). Opening balances on ledgers count from the start of the books.
 * Balances are signed paise: positive = debit, negative = credit.
 */
final class Books
{
    /** Entries that count, optionally limited to a branch and cost centre. */
    public function entries(?string $branchId = null, ?string $costCentreId = null): Builder
    {
        return LedgerEntry::query()
            ->whereHas('voucher', fn ($q) => $q->whereIn('status', ['posted', 'reversed']))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($costCentreId, fn ($q) => $q->where('cost_centre_id', $costCentreId));
    }

    /** @return array<string, array{dr: int, cr: int}> ledger id => sums */
    private function sums(?string $from, ?string $to, ?string $branchId = null, ?string $costCentreId = null): array
    {
        return $this->entries($branchId, $costCentreId)
            ->when($from, fn ($q) => $q->where('date', '>=', $from))->when($to, fn ($q) => $q->where('date', '<=', $to))
            ->groupBy('ledger_id')->selectRaw('ledger_id, sum(debit) as dr, sum(credit) as cr')->get()
            ->mapWithKeys(fn ($r) => [$r->ledger_id => ['dr' => (int) $r->dr, 'cr' => (int) $r->cr]])->all();
    }

    /** Opening balances apply to the whole institution, not to one branch or cost centre. */
    private function openingOf(Ledger $l, ?string $branchId, ?string $costCentreId): int
    {
        return $branchId || $costCentreId ? 0 : $l->opening_balance;
    }

    public function balance(Ledger $ledger, string $upTo, ?string $branchId = null): int
    {
        $s = $this->sums(null, $upTo, $branchId)[$ledger->id] ?? ['dr' => 0, 'cr' => 0];

        return $this->openingOf($ledger, $branchId, null) + $s['dr'] - $s['cr'];
    }

    /** Ledger account: opening, every entry with a running balance, closing. Used for the general ledger, cash book and bank book. */
    public function statement(Ledger $ledger, string $from, string $to, ?string $branchId = null, ?string $costCentreId = null): array
    {
        $before = $this->sums(null, date('Y-m-d', strtotime($from.' -1 day')), $branchId, $costCentreId)[$ledger->id] ?? ['dr' => 0, 'cr' => 0];
        $opening = $this->openingOf($ledger, $branchId, $costCentreId) + $before['dr'] - $before['cr'];
        $run = $opening;
        $rows = $this->entries($branchId, $costCentreId)->with(['voucher', 'voucher.entries.ledger'])->where('ledger_id', $ledger->id)
            ->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('created_at')->orderBy('line_no')->get()
            ->map(function (LedgerEntry $e) use (&$run) {
                $run += $e->debit - $e->credit;
                $other = $e->voucher->entries->where('ledger_id', '!=', $e->ledger_id)->where($e->debit > 0 ? 'credit' : 'debit', '>', 0)->pluck('ledger.name')->unique()->values();

                return ['entry' => $e, 'voucher' => $e->voucher, 'against' => $other->count() > 1 ? $other->first().' and others' : $other->first(), 'balance' => $run];
            });

        return ['ledger' => $ledger, 'opening' => $opening, 'rows' => $rows, 'debit' => $rows->sum(fn ($r) => $r['entry']->debit),
            'credit' => $rows->sum(fn ($r) => $r['entry']->credit), 'closing' => $run];
    }

    /** Every ledger with opening, period debit and credit, and closing balance. Debits equal credits in every column. */
    public function trialBalance(string $from, string $to, ?string $branchId = null): array
    {
        $before = $this->sums(null, date('Y-m-d', strtotime($from.' -1 day')), $branchId);
        $period = $this->sums($from, $to, $branchId);
        $rows = Ledger::with('group')->orderBy('code')->get()->map(function (Ledger $l) use ($before, $period, $branchId) {
            $b = $before[$l->id] ?? ['dr' => 0, 'cr' => 0];
            $p = $period[$l->id] ?? ['dr' => 0, 'cr' => 0];
            $open = $this->openingOf($l, $branchId, null) + $b['dr'] - $b['cr'];

            return ['ledger' => $l, 'opening' => $open, 'debit' => $p['dr'], 'credit' => $p['cr'], 'closing' => $open + $p['dr'] - $p['cr']];
        })->filter(fn ($r) => $r['opening'] || $r['debit'] || $r['credit'])->values();

        $tot = fn (string $k, int $sign) => $rows->sum(fn ($r) => $sign > 0 ? max(0, $r[$k]) : max(0, -$r[$k]));

        return ['rows' => $rows, 'opening_dr' => $tot('opening', 1), 'opening_cr' => $tot('opening', -1), 'debit' => $rows->sum('debit'), 'credit' => $rows->sum('credit'),
            'closing_dr' => $tot('closing', 1), 'closing_cr' => $tot('closing', -1)];
    }

    /** Group tree with each ledger's movement, for P&L and balance sheet. */
    private function grouped(array $natures, callable $amountOf): array
    {
        $groups = AccountGroup::with('ledgers')->whereIn('nature', $natures)->orderBy('sort_order')->get();
        $byParent = $groups->groupBy(fn ($g) => $g->parent_id ?? 'root');
        $build = function ($g) use (&$build, $byParent, $amountOf) {
            $ledgers = $g->ledgers->map(fn ($l) => ['ledger' => $l, 'amount' => $amountOf($l)])->filter(fn ($r) => $r['amount'] !== 0)->values();
            $children = collect($byParent[$g->id] ?? [])->map($build)->filter(fn ($c) => $c['total'] !== 0)->values();

            return ['group' => $g, 'ledgers' => $ledgers, 'children' => $children, 'total' => $ledgers->sum('amount') + $children->sum('total')];
        };

        return collect($byParent['root'] ?? [])->map($build)->values()->all();
    }

    /** Income less expenses for a period. Income shown positive (credit), expenses positive (debit). */
    public function profitAndLoss(string $from, string $to, ?string $branchId = null, ?string $costCentreId = null): array
    {
        $s = $this->sums($from, $to, $branchId, $costCentreId);
        $income = $this->grouped(['income'], fn ($l) => ($s[$l->id]['cr'] ?? 0) - ($s[$l->id]['dr'] ?? 0));
        $expense = $this->grouped(['expense'], fn ($l) => ($s[$l->id]['dr'] ?? 0) - ($s[$l->id]['cr'] ?? 0));
        $ti = collect($income)->sum('total');
        $te = collect($expense)->sum('total');

        return ['income' => $income, 'expense' => $expense, 'total_income' => $ti, 'total_expense' => $te, 'surplus' => $ti - $te];
    }

    /** Assets = liabilities + capital + surplus to date. The surplus is income less expenses since the books began. */
    public function balanceSheet(string $asOn, ?string $branchId = null): array
    {
        $s = $this->sums(null, $asOn, $branchId);
        $bal = fn (Ledger $l) => $this->openingOf($l, $branchId, null) + ($s[$l->id]['dr'] ?? 0) - ($s[$l->id]['cr'] ?? 0);
        $assets = $this->grouped(['asset'], $bal);
        $liabilities = $this->grouped(['liability'], fn ($l) => -$bal($l));
        $equity = $this->grouped(['equity'], fn ($l) => -$bal($l));
        $ie = Ledger::whereHas('group', fn ($q) => $q->whereIn('nature', ['income', 'expense']))->get();
        $surplus = -$ie->sum(fn ($l) => $bal($l));
        $ta = collect($assets)->sum('total');
        $tl = collect($liabilities)->sum('total') + collect($equity)->sum('total') + $surplus;

        return ['assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity, 'surplus' => $surplus, 'total_assets' => $ta, 'total_liabilities' => $tl, 'difference' => $ta - $tl];
    }

    /**
     * Cash flow by the direct method: every movement in cash and bank ledgers, classed by the other side of its voucher
     * (operating, investing or financing, from the account group). Transfers between cash and bank are left out.
     */
    public function cashFlow(string $from, string $to, ?string $branchId = null): array
    {
        $cashIds = Ledger::whereIn('type', ['cash', 'bank'])->pluck('id')->all();
        $vouchers = Voucher::with('entries.ledger.group.parent.parent')->whereIn('status', ['posted', 'reversed'])->whereBetween('date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereHas('entries', fn ($q) => $q->whereIn('ledger_id', $cashIds))->get();
        $sections = ['operating' => [], 'investing' => [], 'financing' => []];
        foreach ($vouchers as $v) {
            $cash = $v->entries->whereIn('ledger_id', $cashIds);
            $net = $cash->sum('debit') - $cash->sum('credit');
            $others = $v->entries->whereNotIn('ledger_id', $cashIds);
            $base = $others->sum(fn ($e) => $e->debit + $e->credit);
            if ($net === 0 || $base === 0) {
                continue;   // contra
            }
            $left = $net;
            foreach ($others->values() as $i => $e) {
                $share = $i === $others->count() - 1 ? $left : intdiv($net * ($e->debit + $e->credit), $base);
                $left -= $share;
                $class = $e->ledger->group->cashFlowClass();
                $class = in_array($class, ['investing', 'financing'], true) ? $class : 'operating';
                $name = $e->ledger->group->name;
                $sections[$class][$name] = ($sections[$class][$name] ?? 0) + $share;
            }
        }
        $opening = collect($cashIds)->sum(fn ($id) => $this->balance(Ledger::find($id), date('Y-m-d', strtotime($from.' -1 day')), $branchId));
        $closing = collect($cashIds)->sum(fn ($id) => $this->balance(Ledger::find($id), $to, $branchId));
        $net = collect($sections)->flatten()->sum();

        return ['sections' => collect($sections)->map(fn ($rows) => ['rows' => $rows, 'total' => array_sum($rows)])->all(), 'net' => $net, 'opening' => $opening, 'closing' => $closing];
    }

    /** Vouchers in a date range, for the day book. */
    public function dayBook(string $from, string $to, ?string $type = null, ?string $branchId = null): Collection
    {
        return Voucher::with('entries.ledger')->whereIn('status', ['posted', 'reversed'])->whereBetween('date', [$from, $to])
            ->when($type, fn ($q) => $q->where('type', $type))->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('date')->orderBy('posted_at')->get();
    }

    /** Budget against actual for a financial year: actual is the ledger's movement on its natural side. */
    public function budgetVsActual(FinancialYear $fy, ?string $branchId = null): Collection
    {
        $s = $this->sums($fy->starts_on->format('Y-m-d'), $fy->ends_on->format('Y-m-d'), $branchId);
        $ccSums = fn ($cc) => $this->sums($fy->starts_on->format('Y-m-d'), $fy->ends_on->format('Y-m-d'), $branchId, $cc);

        return Budget::with(['ledger.group', 'costCentre'])->where('financial_year_id', $fy->id)
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereNull('branch_id')))->get()
            ->map(function (Budget $b) use ($s, $ccSums) {
                $sums = $b->cost_centre_id ? $ccSums($b->cost_centre_id) : $s;
                $x = $sums[$b->ledger_id] ?? ['dr' => 0, 'cr' => 0];
                $actual = $b->ledger->group->isDebitNature() ? $x['dr'] - $x['cr'] : $x['cr'] - $x['dr'];

                return ['budget' => $b, 'actual' => $actual, 'variance' => $b->amount - $actual, 'used' => $b->amount ? round($actual * 100 / $b->amount, 1) : null];
            })->sortBy(fn ($r) => $r['budget']->ledger->name)->values();
    }

    /** Income and expense per cost centre. */
    public function costCentres(string $from, string $to, ?string $branchId = null): Collection
    {
        return $this->entries($branchId)->whereBetween('date', [$from, $to])->whereNotNull('cost_centre_id')
            ->join('ledgers as l', 'l.id', '=', 'ledger_entries.ledger_id')->join('account_groups as g', 'g.id', '=', 'l.group_id')
            ->whereIn('g.nature', ['income', 'expense'])
            ->groupBy('ledger_entries.cost_centre_id', 'g.nature')->selectRaw('ledger_entries.cost_centre_id, g.nature, sum(ledger_entries.debit) as dr, sum(ledger_entries.credit) as cr')
            ->get()->groupBy('cost_centre_id')->map(function ($rows, $cc) {
                $inc = $rows->where('nature', 'income')->sum(fn ($r) => $r->cr - $r->dr);
                $exp = $rows->where('nature', 'expense')->sum(fn ($r) => $r->dr - $r->cr);

                return ['cost_centre' => \Modules\Accounting\Models\CostCentre::find($cc), 'income' => (int) $inc, 'expense' => (int) $exp, 'net' => (int) ($inc - $exp)];
            })->values();
    }
}
