<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Accounting\Models\Budget;
use Modules\Accounting\Models\CostCentre;
use Modules\Accounting\Models\FinancialYear;
use Modules\Accounting\Models\FixedAsset;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Services\AssetService;
use Modules\Accounting\Services\LedgerService;

/** Budgets and the fixed asset register. */
class PlanningController extends Controller
{
    public function budgets(Request $request): View
    {
        $fy = FinancialYear::find($request->input('year')) ?? FinancialYear::current();

        return view('accounting::budgets', ['fy' => $fy, 'years' => FinancialYear::orderByDesc('starts_on')->get(),
            'rows' => app(\Modules\Accounting\Services\Books::class)->budgetVsActual($fy),
            'ledgers' => Ledger::whereHas('group', fn ($q) => $q->whereIn('nature', ['income', 'expense']))->orderBy('name')->get(),
            'costCentres' => CostCentre::where('is_active', true)->orderBy('name')->get(), 'branches' => $this->branches()]);
    }

    public function storeBudget(Request $request, LedgerService $ledgers): RedirectResponse
    {
        $ledgers->saveBudget($request->all());

        return $this->done('Budget saved.');
    }

    public function assets(AssetService $svc): View
    {
        $assets = FixedAsset::with('assetLedger')->orderBy('code')->get();

        return view('accounting::assets', ['assets' => $assets, 'book' => $assets->mapWithKeys(fn ($a) => [$a->id => $svc->bookValue($a)]),
            'ledgers' => Ledger::whereHas('group', fn ($q) => $q->where('code', 'A2'))->orderBy('name')->get(),
            'costCentres' => CostCentre::where('is_active', true)->orderBy('name')->get(), 'branches' => $this->branches()]);
    }

    public function storeAsset(Request $request, AssetService $svc): RedirectResponse
    {
        $a = $svc->register($request->all());

        return $this->done("{$a->name} added to the asset register.");
    }

    public function depreciate(Request $request, AssetService $svc): RedirectResponse
    {
        $v = $svc->run((string) $request->input('period'));

        return $this->done($v ? "Depreciation posted as {$v->number}." : 'Nothing to post: every asset is already charged for that month.');
    }
}
