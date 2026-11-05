<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Academic\Support\Csv;
use Modules\Accounting\Models\FinancialYear;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\InvoiceService;
use Modules\Accounting\Support\Amount;

class ReportController extends Controller
{
    public function __construct(private readonly Books $books)
    {
    }

    public function trialBalance(Request $request)
    {
        [$from, $to] = $this->range($request);
        $tb = $this->books->trialBalance($from, $to, $request->input('branch'));
        if ($request->input('format') === 'csv') {
            return Csv::download('trial-balance.csv', ['Code', 'Ledger', 'Group', 'Opening', 'Debit', 'Credit', 'Closing'],
                $tb['rows']->map(fn ($r) => [$r['ledger']->code, $r['ledger']->name, $r['ledger']->group->name, Amount::drcr($r['opening']), Amount::input($r['debit']), Amount::input($r['credit']), Amount::drcr($r['closing'])])->all());
        }

        return view('accounting::reports.trial-balance', ['tb' => $tb, 'from' => $from, 'to' => $to, 'branches' => $this->branches()]);
    }

    public function profitLoss(Request $request)
    {
        [$from, $to] = $this->range($request);

        return view('accounting::reports.profit-loss', ['pl' => $this->books->profitAndLoss($from, $to, $request->input('branch'), $request->input('cost_centre')),
            'from' => $from, 'to' => $to, 'branches' => $this->branches(), 'costCentres' => \Modules\Accounting\Models\CostCentre::orderBy('name')->get()]);
    }

    public function balanceSheet(Request $request)
    {
        $asOn = $request->input('as_on', today()->format('Y-m-d'));

        return view('accounting::reports.balance-sheet', ['bs' => $this->books->balanceSheet($asOn, $request->input('branch')), 'asOn' => $asOn, 'branches' => $this->branches()]);
    }

    public function cashFlow(Request $request)
    {
        [$from, $to] = $this->range($request);

        return view('accounting::reports.cash-flow', ['cf' => $this->books->cashFlow($from, $to, $request->input('branch')), 'from' => $from, 'to' => $to, 'branches' => $this->branches()]);
    }

    /** General ledger, cash book and bank book are the same account statement on different ledgers. */
    public function ledger(Request $request, string $book = 'ledger')
    {
        [$from, $to] = $this->range($request);
        $choices = match ($book) {
            'cash' => Ledger::where('type', 'cash')->orderBy('name')->get(),
            'bank' => Ledger::whereIn('type', ['bank'])->orderBy('name')->get(),
            default => Ledger::orderBy('name')->get(),
        };
        $ledger = $choices->firstWhere('id', $request->input('ledger')) ?? $choices->first();
        $st = $ledger ? $this->books->statement($ledger, $from, $to, $request->input('branch'), $request->input('cost_centre')) : null;
        if ($st && $request->input('format') === 'csv') {
            return Csv::download("{$ledger->code}-{$from}-{$to}.csv", ['Date', 'Voucher', 'Type', 'Particulars', 'Narration', 'Debit', 'Credit', 'Balance'],
                array_merge([['', '', '', 'Opening balance', '', '', '', Amount::drcr($st['opening'])]], $st['rows']->map(fn ($r) => [$r['entry']->date->format('Y-m-d'), $r['voucher']->number,
                    $r['voucher']->typeLabel(), $r['against'], $r['entry']->narration ?? $r['voucher']->narration, Amount::input($r['entry']->debit), Amount::input($r['entry']->credit), Amount::drcr($r['balance'])])->all()));
        }

        return view('accounting::reports.ledger', ['book' => $book, 'choices' => $choices, 'ledger' => $ledger, 'st' => $st, 'from' => $from, 'to' => $to, 'branches' => $this->branches(),
            'title' => ['cash' => 'Cash book', 'bank' => 'Bank book'][$book] ?? 'General ledger']);
    }

    public function cashBook(Request $request)
    {
        return $this->ledger($request, 'cash');
    }

    public function bankBook(Request $request)
    {
        return $this->ledger($request, 'bank');
    }

    public function dayBook(Request $request)
    {
        [$from, $to] = $this->range($request->merge(['from' => $request->input('from', today()->startOfMonth()->format('Y-m-d'))]));

        return view('accounting::reports.day-book', ['list' => $this->books->dayBook($from, $to, $request->input('type'), $request->input('branch')), 'from' => $from, 'to' => $to, 'branches' => $this->branches()]);
    }

    public function budget(Request $request)
    {
        $fy = FinancialYear::find($request->input('year')) ?? FinancialYear::current();

        return view('accounting::reports.budget', ['fy' => $fy, 'rows' => $this->books->budgetVsActual($fy, $request->input('branch')), 'years' => FinancialYear::orderByDesc('starts_on')->get(), 'branches' => $this->branches()]);
    }

    public function costCentres(Request $request)
    {
        [$from, $to] = $this->range($request);

        return view('accounting::reports.cost-centres', ['rows' => $this->books->costCentres($from, $to, $request->input('branch')), 'from' => $from, 'to' => $to, 'branches' => $this->branches()]);
    }

    /** Bills still owed to or by the institution, aged from the due date (or bill date). */
    public function outstanding(Request $request, InvoiceService $invoices)
    {
        $side = $request->input('side', 'receivable');
        $kinds = $side === 'payable' ? ['purchase', 'expense'] : ['sales'];
        $rows = Invoice::with('party')->where('status', 'posted')->whereIn('kind', $kinds)->orderBy('date')->get()
            ->map(function ($i) use ($invoices) {
                $days = (int) ($i->due_date ?? $i->date)->diffInDays(today(), false);

                return ['inv' => $i, 'due' => $invoices->outstanding($i), 'days' => $days, 'bucket' => $days <= 0 ? 'Not due' : ($days <= 30 ? '1–30 days' : ($days <= 60 ? '31–60 days' : ($days <= 90 ? '61–90 days' : 'Over 90 days')))];
            })->filter(fn ($r) => $r['due'] > 0)->values();

        return view('accounting::reports.outstanding', ['rows' => $rows, 'side' => $side, 'buckets' => $rows->groupBy('bucket')->map->sum('due')]);
    }
}
