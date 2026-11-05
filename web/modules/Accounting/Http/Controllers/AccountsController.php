<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\CostCentre;
use Modules\Accounting\Models\FinancialYear;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\TaxRate;
use Modules\Accounting\Models\TdsSection;
use Modules\Accounting\Models\Voucher;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\InvoiceService;
use Modules\Accounting\Services\LedgerService;

/** Accounts home and settings: year, lock date, approvals, GST and TDS, cost centres. */
class AccountsController extends Controller
{
    public function __construct(private readonly Books $books, private readonly LedgerService $ledgers)
    {
    }

    public function dashboard(): View
    {
        $today = today()->format('Y-m-d');
        $monthStart = today()->startOfMonth()->format('Y-m-d');
        $pl = $this->books->profitAndLoss($monthStart, $today);
        $cash = Ledger::whereIn('type', ['cash', 'bank', 'gateway'])->where('is_active', true)->orderBy('type')->orderBy('name')->get()
            ->map(fn ($l) => ['ledger' => $l, 'balance' => $this->books->balance($l, $today)]);
        $inv = app(InvoiceService::class);
        $open = Invoice::with('party')->where('status', 'posted')->whereIn('kind', ['sales', 'purchase', 'expense'])->get()->map(fn ($i) => ['inv' => $i, 'due' => $inv->outstanding($i)]);

        return view('accounting::dashboard', [
            'cash' => $cash, 'pl' => $pl, 'fy' => FinancialYear::current(), 'settings' => AccountingSetting::current(),
            'pending' => Voucher::where('status', 'pending')->count(), 'drafts' => Voucher::where('status', 'draft')->whereNull('source_type')->count(),
            'receivable' => $open->filter(fn ($r) => $r['inv']->kind === 'sales')->sum('due'), 'payable' => $open->filter(fn ($r) => $r['inv']->kind !== 'sales')->sum('due'),
            'recent' => Voucher::whereIn('status', ['posted', 'reversed'])->orderByDesc('posted_at')->limit(10)->get(),
        ]);
    }

    public function settings(): View
    {
        return view('accounting::settings', ['s' => AccountingSetting::current(), 'years' => FinancialYear::orderByDesc('starts_on')->get(),
            'costCentres' => CostCentre::orderBy('code')->get(), 'rates' => TaxRate::orderBy('rate_bp')->get(),
            'sections' => TdsSection::orderBy('code')->orderByDesc('effective_from')->get(), 'opening' => $this->ledgers->openingSummary()]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $this->ledgers->saveSettings($request->all());

        return $this->done('Accounting settings saved.');
    }

    public function lock(Request $request): RedirectResponse
    {
        $this->ledgers->lockUntil($request->input('locked_until') ?: null, $request->input('reason'));

        return $this->done($request->input('locked_until') ? 'Books locked up to '.\Illuminate\Support\Carbon::parse($request->input('locked_until'))->format('j M Y').'.' : 'Books unlocked.');
    }

    public function addYear(): RedirectResponse
    {
        $fy = $this->ledgers->addFinancialYear();

        return $this->done("Financial year {$fy->name} added.");
    }

    public function closeYear(FinancialYear $financialYear): RedirectResponse
    {
        $this->ledgers->closeYear($financialYear);

        return $this->done("{$financialYear->name} closed and locked.");
    }

    public function storeCostCentre(Request $request): RedirectResponse
    {
        $this->ledgers->saveCostCentre($request->all());

        return $this->done('Cost centre added.');
    }

    public function updateCostCentre(Request $request, CostCentre $costCentre): RedirectResponse
    {
        $this->ledgers->saveCostCentre($request->all(), $costCentre);

        return $this->done('Cost centre saved.');
    }

    public function storeTds(Request $request): RedirectResponse
    {
        $s = $this->ledgers->saveTdsSection($request->all());

        return $this->done("TDS {$s->code} rate saved from ".$s->effective_from->format('j M Y').'. Earlier bills keep the old rate.');
    }
}
