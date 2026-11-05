<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\BankStatementLine;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Support\Amount;

/** Bank statement import, automatic and manual matching, and the bank reconciliation statement. */
final class BankReconciliation
{
    public function __construct(private readonly CurrentMember $member, private readonly Books $books)
    {
    }

    /**
     * CSV with a header row. Columns (any order, case-insensitive): Date, Description (or Narration), Reference (or Ref / Cheque),
     * and either Amount (+ in, - out) or Withdrawal and Deposit (or Debit and Credit, as on bank statements).
     * Dates: 31/10/2026, 31-10-2026 or 2026-10-31. Lines already imported are skipped.
     *
     * @return array{imported: int, skipped: int}
     */
    public function import(Ledger $bank, string $csv): array
    {
        $this->member->authorize('accounts.post');
        if ($bank->type !== 'bank') {
            throw new AccountingException('Choose a bank ledger.');
        }
        $rows = array_values(array_filter(array_map('str_getcsv', preg_split('/\r\n|\r|\n/', trim($csv))), fn ($r) => count(array_filter($r, 'strlen')) > 0));
        if (count($rows) < 2) {
            throw new AccountingException('The file has no statement lines. It needs a header row and at least one line.', 'file');
        }
        $head = array_map(fn ($h) => strtolower(trim($h)), $rows[0]);
        $col = function (array $names) use ($head) {
            foreach ($names as $n) {
                if (($i = array_search($n, $head, true)) !== false) {
                    return $i;
                }
            }

            return null;
        };
        $iDate = $col(['date', 'txn date', 'transaction date', 'value date']);
        $iDesc = $col(['description', 'narration', 'particulars', 'remarks']);
        $iRef = $col(['reference', 'ref', 'ref no', 'cheque', 'cheque no', 'chq/ref no']);
        $iAmt = $col(['amount']);
        $iOut = $col(['withdrawal', 'debit', 'withdrawal amt', 'dr']);
        $iIn = $col(['deposit', 'credit', 'deposit amt', 'cr']);
        if ($iDate === null || $iDesc === null || ($iAmt === null && ($iOut === null || $iIn === null))) {
            throw new AccountingException('The file needs Date, Description and either Amount or Withdrawal and Deposit columns.', 'file');
        }
        $batch = now()->format('YmdHis').'-'.Str::random(4);
        $imported = $skipped = 0;
        DB::transaction(function () use ($rows, $bank, $iDate, $iDesc, $iRef, $iAmt, $iOut, $iIn, $batch, &$imported, &$skipped) {
            foreach (array_slice($rows, 1) as $n => $r) {
                $line = $n + 2;
                $date = $this->date($r[$iDate] ?? '') ?? throw new AccountingException("Row {$line}: the date “".($r[$iDate] ?? '')."” is not a date.", 'file');
                try {
                    $amount = $iAmt !== null ? Amount::parse(trim($r[$iAmt] ?? '')) : Amount::parse(trim($r[$iIn] ?? '')) - Amount::parse(trim($r[$iOut] ?? ''));
                } catch (\InvalidArgumentException) {
                    throw new AccountingException("Row {$line}: the amount is not a number.", 'file');
                }
                if ($amount === 0) {
                    $skipped++;
                    continue;
                }
                $desc = Str::limit(trim($r[$iDesc] ?? ''), 290, '');
                $ref = $iRef !== null ? (trim($r[$iRef] ?? '') ?: null) : null;
                if (BankStatementLine::where(['ledger_id' => $bank->id, 'date' => $date, 'amount' => $amount, 'description' => $desc, 'reference' => $ref])->exists()) {
                    $skipped++;
                    continue;
                }
                BankStatementLine::create(['ledger_id' => $bank->id, 'date' => $date, 'description' => $desc, 'reference' => $ref, 'amount' => $amount, 'batch' => $batch]);
                $imported++;
            }
        });
        Audit::record('bank.statement_imported', $bank, ['imported' => $imported, 'skipped' => $skipped]);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function date(string $v): ?string
    {
        $v = trim($v);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd-M-Y', 'd M Y'] as $f) {
            $d = \DateTime::createFromFormat('!'.$f, $v);
            if ($d && $d->format($f) === $v) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /** Book entries on the bank ledger not yet matched to the statement. */
    public function unmatchedEntries(Ledger $bank, ?string $upTo = null)
    {
        $matched = BankStatementLine::whereNotNull('matched_entry_id')->pluck('matched_entry_id');

        return $this->books->entries()->with('voucher')->where('ledger_id', $bank->id)->whereNotIn('id', $matched)
            ->when($upTo, fn ($q) => $q->where('date', '<=', $upTo))->orderBy('date')->get();
    }

    /** Matches each statement line to the one book entry with the same amount within 5 days (same reference first). */
    public function autoMatch(Ledger $bank): int
    {
        $this->member->authorize('accounts.post');
        $entries = $this->unmatchedEntries($bank);
        $n = 0;
        foreach (BankStatementLine::where('ledger_id', $bank->id)->whereNull('matched_entry_id')->orderBy('date')->get() as $line) {
            $cands = $entries->filter(fn (LedgerEntry $e) => ($e->debit - $e->credit) === $line->amount && abs($e->date->diffInDays($line->date)) <= 5);
            if ($line->reference) {
                $byRef = $cands->filter(fn ($e) => $e->voucher->reference && str_contains(strtoupper($line->reference.' '.$line->description), strtoupper($e->voucher->reference)));
                $cands = $byRef->isNotEmpty() ? $byRef : $cands;
            }
            if ($cands->count() === 1) {
                $e = $cands->first();
                $line->update(['matched_entry_id' => $e->id, 'matched_at' => now(), 'matched_by' => $this->member->userId()]);
                $entries = $entries->reject(fn ($x) => $x->id === $e->id);
                $n++;
            }
        }
        Audit::record('bank.auto_matched', $bank, ['matched' => $n]);

        return $n;
    }

    public function match(BankStatementLine $line, LedgerEntry $entry): void
    {
        $this->member->authorize('accounts.post');
        if ($entry->ledger_id !== $line->ledger_id) {
            throw new AccountingException('The entry is in a different bank ledger.');
        }
        if (($entry->debit - $entry->credit) !== $line->amount) {
            throw new AccountingException('The amounts are different: statement '.Amount::drcr($line->amount).', books '.Amount::drcr($entry->debit - $entry->credit).'.');
        }
        if ($line->matched_entry_id || BankStatementLine::where('matched_entry_id', $entry->id)->exists()) {
            throw new AccountingException('One of them is already matched.');
        }
        $line->update(['matched_entry_id' => $entry->id, 'matched_at' => now(), 'matched_by' => $this->member->userId()]);
        Audit::record('bank.matched', $line, ['entry' => $entry->id]);
    }

    public function unmatch(BankStatementLine $line): void
    {
        $this->member->authorize('accounts.post');
        $line->update(['matched_entry_id' => null, 'matched_at' => null, 'matched_by' => null]);
        Audit::record('bank.unmatched', $line);
    }

    /** Balance as per books, items not yet on the statement, and the balance the bank should show. */
    public function statement(Ledger $bank, string $asOn): array
    {
        $books = $this->books->balance($bank, $asOn);
        $open = $this->unmatchedEntries($bank, $asOn);
        $deposits = $open->where('debit', '>', 0);
        $payments = $open->where('credit', '>', 0);
        $unbooked = BankStatementLine::where('ledger_id', $bank->id)->whereNull('matched_entry_id')->where('date', '<=', $asOn)->orderBy('date')->get();

        return ['books' => $books, 'deposits_not_credited' => $deposits, 'payments_not_presented' => $payments, 'not_in_books' => $unbooked,
            'bank' => $books - $deposits->sum('debit') + $payments->sum('credit') + $unbooked->sum('amount')];
    }
}
