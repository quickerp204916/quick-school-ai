<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Accounting\Models\BankStatementLine;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Services\BankReconciliation;

class BankController extends Controller
{
    public function __construct(private readonly BankReconciliation $recon)
    {
    }

    public function index(Request $request): View
    {
        $banks = Ledger::where('type', 'bank')->orderBy('name')->get();
        $bank = $banks->firstWhere('id', $request->input('bank')) ?? $banks->first();
        $asOn = $request->input('as_on', today()->format('Y-m-d'));

        return view('accounting::bank.index', ['banks' => $banks, 'bank' => $bank, 'asOn' => $asOn,
            'brs' => $bank ? $this->recon->statement($bank, $asOn) : null,
            'lines' => $bank ? BankStatementLine::with('entry.voucher')->where('ledger_id', $bank->id)->orderByDesc('date')->limit(200)->get() : collect(),
            'open' => $bank ? $this->recon->unmatchedEntries($bank) : collect()]);
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate(['bank' => ['required'], 'file' => ['nullable', 'file', 'mimes:csv,txt', 'max:2048']]);
        $bank = Ledger::findOrFail($request->input('bank'));
        $csv = $request->file('file')?->get() ?? (string) $request->input('csv');
        $r = $this->recon->import($bank, $csv);
        $m = $this->recon->autoMatch($bank);

        return $this->done("Imported {$r['imported']} lines ({$r['skipped']} skipped) and matched {$m} automatically.");
    }

    public function autoMatch(Request $request): RedirectResponse
    {
        return $this->done($this->recon->autoMatch(Ledger::findOrFail($request->input('bank'))).' lines matched.');
    }

    public function match(Request $request, BankStatementLine $statementLine): RedirectResponse
    {
        $this->recon->match($statementLine, LedgerEntry::findOrFail($request->input('entry_id')));

        return $this->done('Matched.');
    }

    public function unmatch(BankStatementLine $statementLine): RedirectResponse
    {
        $this->recon->unmatch($statementLine);

        return $this->done('Match removed.');
    }
}
