<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Accounting\Models\AccountGroup;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\TdsSection;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\LedgerService;

/** Chart of accounts: ledger groups, ledger master and opening balances. */
class ChartController extends Controller
{
    public function __construct(private readonly LedgerService $ledgers)
    {
    }

    public function index(Books $books): View
    {
        $today = today()->format('Y-m-d');

        return view('accounting::chart.index', ['groups' => AccountGroup::with('ledgers')->orderBy('sort_order')->get(),
            'balances' => Ledger::all()->mapWithKeys(fn ($l) => [$l->id => $books->balance($l, $today)]), 'sections' => TdsSection::whereNull('effective_to')->orderBy('code')->get(),
            'branches' => $this->branches()]);
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $g = $this->ledgers->saveGroup($request->all());

        return $this->done("Group {$g->name} added.");
    }

    public function updateGroup(Request $request, AccountGroup $group): RedirectResponse
    {
        $this->ledgers->saveGroup($request->all(), $group);

        return $this->done('Group saved.');
    }

    public function storeLedger(Request $request): RedirectResponse
    {
        $l = $this->ledgers->saveLedger($request->all());

        return $this->done("Ledger {$l->name} added.");
    }

    public function editLedger(Ledger $ledger): View
    {
        return view('accounting::chart.ledger', ['l' => $ledger, 'groups' => AccountGroup::orderBy('sort_order')->get(),
            'sections' => TdsSection::whereNull('effective_to')->orderBy('code')->get(), 'branches' => $this->branches()]);
    }

    public function updateLedger(Request $request, Ledger $ledger): RedirectResponse
    {
        $this->ledgers->saveLedger($request->all() + ['is_active' => 0], $ledger);

        return $this->done('Ledger saved.', route('app.accounts.chart'));
    }

    public function opening(): View
    {
        return view('accounting::chart.opening', ['groups' => AccountGroup::with('ledgers')->whereIn('nature', ['asset', 'liability', 'equity'])->orderBy('sort_order')->get(),
            'summary' => $this->ledgers->openingSummary(), 'first' => \Modules\Accounting\Models\FinancialYear::orderBy('starts_on')->first()]);
    }

    public function saveOpening(Request $request): RedirectResponse
    {
        $s = $this->ledgers->setOpeningBalances((array) $request->input('opening', []));
        if ($request->boolean('balance')) {
            $s = $this->ledgers->balanceOpening();
        }

        return $this->done($s['difference'] === 0 ? 'Opening balances saved. Debits and credits agree.'
            : 'Opening balances saved. They differ by '.\Modules\Accounting\Support\Amount::format(abs($s['difference'])).'.');
    }
}
