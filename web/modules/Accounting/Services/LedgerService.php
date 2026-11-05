<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Academic\Support\Rules;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\AccountGroup;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\Budget;
use Modules\Accounting\Models\CostCentre;
use Modules\Accounting\Models\FinancialYear;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Models\TaxRate;
use Modules\Accounting\Models\TdsSection;
use Modules\Accounting\Models\Voucher;
use Modules\Accounting\Support\Amount;

/** Chart of accounts, opening balances, cost centres, budgets, financial years, period locking, GST and TDS settings. */
final class LedgerService
{
    public function __construct(private readonly CurrentMember $member)
    {
    }

    public function saveGroup(array $data, ?AccountGroup $group = null): AccountGroup
    {
        $this->member->authorize('accounts.configure');
        $d = Rules::check($data, [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/i', Rules::unique('account_groups', 'code', $group?->id)],
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['required', Rules::exists('account_groups')],
            'cash_flow' => ['nullable', Rule::in(['operating', 'investing', 'financing'])],
        ], ['parent_id.required' => 'Choose the group this belongs under.']);
        $parent = AccountGroup::findOrFail($d['parent_id']);
        if ($group && ($group->is_system && $parent->id !== $group->parent_id)) {
            throw new AccountingException('Built-in groups cannot be moved.', 'parent_id');
        }
        if ($group && $this->isDescendant($parent, $group)) {
            throw new AccountingException('A group cannot sit under itself.', 'parent_id');
        }
        $group ??= new AccountGroup(['is_system' => false]);
        $group->fill(['code' => strtoupper($d['code']), 'name' => $d['name'], 'parent_id' => $parent->id, 'nature' => $parent->nature, 'cash_flow' => $d['cash_flow'] ?? null,
            'sort_order' => $group->sort_order ?: (int) AccountGroup::max('sort_order') + 1])->save();
        Audit::record('account_group.saved', $group, ['code' => $group->code]);

        return $group;
    }

    private function isDescendant(AccountGroup $candidate, AccountGroup $of): bool
    {
        for ($g = $candidate; $g; $g = $g->parent) {
            if ($g->id === $of->id) {
                return true;
            }
        }

        return false;
    }

    public function saveLedger(array $data, ?Ledger $ledger = null): Ledger
    {
        $this->member->authorize('accounts.configure');
        $d = Rules::check($data, [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/i', Rules::unique('ledgers', 'code', $ledger?->id)],
            'name' => ['required', 'string', 'max:150'],
            'group_id' => ['required', Rules::exists('account_groups')],
            'type' => ['required', Rule::in(array_keys(config('accounting.ledger_types')))],
            'branch_id' => ['nullable', Rules::exists('branches')],
            'tds_section_id' => ['nullable', Rules::exists('tds_sections')],
            'gstin' => ['nullable', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'state_code' => ['nullable', 'digits:2'],
            'pan' => ['nullable', 'regex:/^[A-Z]{5}\d{4}[A-Z]$/'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:30'],
            'ifsc' => ['nullable', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
        ], ['gstin.regex' => 'Enter a valid 15-character GSTIN.', 'pan.regex' => 'Enter a valid 10-character PAN, for example ABCDE1234F.', 'ifsc.regex' => 'Enter a valid IFSC, for example SBIN0001234.']);
        $d['code'] = strtoupper($d['code']);
        foreach (['gstin', 'pan', 'ifsc'] as $k) {
            $d[$k] = isset($d[$k]) ? strtoupper($d[$k]) : null;
        }
        if (! empty($d['gstin']) && ! empty($d['state_code']) && substr($d['gstin'], 0, 2) !== $d['state_code']) {
            throw new AccountingException('The state does not match the first two digits of the GSTIN.', 'state_code');
        }
        $d['state_code'] ??= ! empty($d['gstin']) ? substr($d['gstin'], 0, 2) : null;
        $group = AccountGroup::findOrFail($d['group_id']);
        if (in_array($d['type'], ['cash', 'bank'], true) && $group->cashFlowClass() !== 'cash') {
            throw new AccountingException('Cash and bank ledgers belong under Cash in hand or Bank accounts.', 'group_id');
        }
        if ($ledger?->system_key && ($d['type'] !== $ledger->type || array_key_exists('is_active', $d) && ! $d['is_active'])) {
            throw new AccountingException('This ledger is used for automatic postings, so its type cannot change and it cannot be made inactive.', 'type');
        }
        if ($ledger && $ledger->entries()->exists() && $group->nature !== $ledger->group->nature) {
            throw new AccountingException('This ledger has entries, so it can only move to a group of the same nature.', 'group_id');
        }
        $ledger ??= new Ledger();
        $ledger->fill($d + ['is_active' => $d['is_active'] ?? true])->save();
        Audit::record('ledger.saved', $ledger, ['code' => $ledger->code]);

        return $ledger;
    }

    /** Opening balances can be set only before any period of the first year is locked or closed. Map: ledger id => signed rupees (debit positive) or [dr, cr]. */
    public function setOpeningBalances(array $balances): array
    {
        $this->member->authorize('accounts.configure');
        $first = FinancialYear::orderBy('starts_on')->firstOrFail();
        $lock = AccountingSetting::current()->locked_until?->format('Y-m-d');
        if ($first->status === 'closed' || ($lock && $lock >= $first->starts_on->format('Y-m-d'))) {
            throw new AccountingException('Opening balances are fixed once the first year is locked or closed. Use a journal voucher instead.');
        }
        DB::transaction(function () use ($balances) {
            foreach ($balances as $id => $row) {
                $ledger = Ledger::findOrFail($id);
                try {
                    $amount = is_array($row) ? Amount::parse($row['dr'] ?? 0) - Amount::parse($row['cr'] ?? 0) : Amount::parse($row);
                } catch (\InvalidArgumentException) {
                    throw new AccountingException("{$ledger->name}: enter amounts like 1500 or 1500.50.");
                }
                if ($amount !== $ledger->opening_balance) {
                    Audit::record('ledger.opening_balance', $ledger, ['from' => $ledger->opening_balance, 'to' => $amount]);
                    $ledger->update(['opening_balance' => $amount]);
                }
            }
        });

        return $this->openingSummary();
    }

    public function openingSummary(): array
    {
        $all = Ledger::where('opening_balance', '!=', 0)->get();
        $dr = $all->where('opening_balance', '>', 0)->sum('opening_balance');
        $cr = -$all->where('opening_balance', '<', 0)->sum('opening_balance');

        return ['debit' => $dr, 'credit' => $cr, 'difference' => $dr - $cr];
    }

    /** Puts any difference into "Opening balance difference" so the opening trial balance agrees. */
    public function balanceOpening(): array
    {
        $diff = $this->openingSummary()['difference'];
        if ($diff !== 0) {
            $l = Ledger::system('opening_difference');
            $this->setOpeningBalances([$l->id => $l->opening_balance - $diff]);
        }

        return $this->openingSummary();
    }

    public function saveCostCentre(array $data, ?CostCentre $cc = null): CostCentre
    {
        $this->member->authorize('accounts.configure');
        $d = Rules::check($data, [
            'code' => ['required', 'string', 'max:20', Rules::unique('cost_centres', 'code', $cc?->id)],
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', Rules::exists('cost_centres')],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $cc ??= new CostCentre();
        $cc->fill(['code' => strtoupper($d['code']), 'name' => $d['name'], 'parent_id' => $d['parent_id'] ?? null, 'is_active' => $d['is_active'] ?? true])->save();

        return $cc;
    }

    public function saveBudget(array $data): Budget
    {
        $this->member->authorize('accounts.configure');
        $d = Rules::check($data, [
            'financial_year_id' => ['required', Rules::exists('financial_years')],
            'ledger_id' => ['required', Rules::exists('ledgers')],
            'cost_centre_id' => ['nullable', Rules::exists('cost_centres')],
            'branch_id' => ['nullable', Rules::exists('branches')],
            'amount' => ['required'],
        ]);
        $ledger = Ledger::with('group')->findOrFail($d['ledger_id']);
        if (! in_array($ledger->group->nature, ['income', 'expense'], true)) {
            throw new AccountingException('Budgets are for income and expense ledgers.', 'ledger_id');
        }
        $amount = Amount::parse($d['amount']);
        if ($amount <= 0) {
            throw new AccountingException('Enter the budget amount.', 'amount');
        }
        $b = Budget::firstOrNew(['financial_year_id' => $d['financial_year_id'], 'ledger_id' => $ledger->id, 'cost_centre_id' => $d['cost_centre_id'] ?? null, 'branch_id' => $d['branch_id'] ?? null]);
        $b->fill(['amount' => $amount, 'months' => array_fill(0, 12, intdiv($amount, 12))])->save();
        Audit::record('budget.saved', $b, ['ledger' => $ledger->code, 'amount' => Amount::format($amount)]);

        return $b;
    }

    public function addFinancialYear(): FinancialYear
    {
        $this->member->authorize('accounts.configure');
        $last = FinancialYear::orderByDesc('starts_on')->firstOrFail();
        $start = $last->ends_on->copy()->addDay();

        return FinancialYear::create(['name' => $start->year.'-'.substr((string) ($start->year + 1), -2), 'starts_on' => $start->format('Y-m-d'), 'ends_on' => $start->copy()->addYear()->subDay()->format('Y-m-d')]);
    }

    /** Lock the books up to a date. Moving the lock earlier (unlocking) is for the owner only. */
    public function lockUntil(?string $date, ?string $reason = null): AccountingSetting
    {
        $this->member->authorize('accounts.configure');
        $s = AccountingSetting::current();
        $current = $s->locked_until?->format('Y-m-d');
        if ($date && $date > today()->format('Y-m-d')) {
            throw new AccountingException('The lock date cannot be in the future.', 'locked_until');
        }
        if ($current && (! $date || $date < $current)) {
            if ($this->member->role() !== 'owner') {
                throw new AccountingException('Only the owner can unlock a locked period.', 'locked_until');
            }
            if (mb_strlen(trim((string) $reason)) < 5) {
                throw new AccountingException('Give the reason for unlocking.', 'reason');
            }
        }
        $s->update(['locked_until' => $date]);
        Audit::record('books.locked', $s, ['from' => $current, 'to' => $date, 'reason' => $reason]);

        return $s;
    }

    /** Closes a year: nothing may be waiting for approval in it, and the next year must exist. The books are locked to its last day. */
    public function closeYear(FinancialYear $fy): FinancialYear
    {
        $this->member->authorize('accounts.configure');
        if ($fy->status === 'closed') {
            throw new AccountingException("{$fy->name} is already closed.");
        }
        if ($fy->ends_on->isFuture()) {
            throw new AccountingException("{$fy->name} has not ended yet.");
        }
        if ($n = Voucher::where('financial_year_id', $fy->id)->whereIn('status', ['draft', 'pending'])->count()) {
            throw new AccountingException("{$n} vouchers in {$fy->name} are still drafts or waiting for approval.");
        }
        if (! FinancialYear::where('starts_on', $fy->ends_on->copy()->addDay()->format('Y-m-d'))->exists()) {
            $this->addFinancialYear();
        }
        $fy->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $this->member->userId()]);
        $s = AccountingSetting::current();
        if (! $s->locked_until || $s->locked_until->lt($fy->ends_on)) {
            $s->update(['locked_until' => $fy->ends_on->format('Y-m-d')]);
        }
        Audit::record('financial_year.closed', $fy);

        return $fy;
    }

    public function saveSettings(array $data): AccountingSetting
    {
        $this->member->authorize('accounts.configure');
        $d = Rules::check($data, [
            'gstin' => ['nullable', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'state_code' => ['nullable', 'digits:2'],
            'tan' => ['nullable', 'regex:/^[A-Z]{4}\d{5}[A-Z]$/'],
            'pan' => ['nullable', 'regex:/^[A-Z]{5}\d{4}[A-Z]$/'],
            'thresholds' => ['array'],
            'allow_self_approval' => ['nullable', 'boolean'],
        ], ['tan.regex' => 'Enter a valid TAN, for example DELA12345B.', 'gstin.regex' => 'Enter a valid 15-character GSTIN.']);
        $thresholds = [];
        foreach (array_keys(config('accounting.approval_thresholds')) as $type) {
            $raw = $data['thresholds'][$type] ?? null;
            $thresholds[$type] = ($raw === null || $raw === '') ? null : Amount::parse($raw);
        }
        $s = AccountingSetting::current();
        $s->update(['gstin' => $d['gstin'] ?? null, 'state_code' => $d['state_code'] ?? (! empty($d['gstin']) ? substr($d['gstin'], 0, 2) : $s->state_code),
            'tan' => $d['tan'] ?? null, 'pan' => $d['pan'] ?? null, 'approval_thresholds' => $thresholds, 'allow_self_approval' => (bool) ($d['allow_self_approval'] ?? false)]);
        Audit::record('accounting.settings_saved', $s, ['thresholds' => $thresholds]);

        return $s;
    }

    /** New rate or section version from a date; the version before it ends the day before. History stays for earlier documents. */
    public function saveTdsSection(array $data): TdsSection
    {
        $this->member->authorize('accounts.configure');
        $d = Rules::check($data, [
            'code' => ['required', 'string', 'max:10'], 'name' => ['required', 'string', 'max:150'],
            'rate' => ['required', 'numeric', 'between:0,100'], 'rate_no_pan' => ['required', 'numeric', 'between:0,100'],
            'threshold_single' => ['nullable'], 'threshold_annual' => ['nullable'], 'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);
        return DB::transaction(function () use ($d) {
            $prev = TdsSection::where('code', $d['code'])->whereNull('effective_to')->first();
            if ($prev && $prev->effective_from->format('Y-m-d') >= $d['effective_from']) {
                throw new AccountingException('The new rate must start after '.$prev->effective_from->format('j M Y').'.', 'effective_from');
            }
            $prev?->update(['effective_to' => Carbon::parse($d['effective_from'])->subDay()->format('Y-m-d')]);

            return TdsSection::create(['code' => strtoupper($d['code']), 'name' => $d['name'], 'rate_bp' => (int) round($d['rate'] * 100), 'rate_no_pan_bp' => (int) round($d['rate_no_pan'] * 100),
                'threshold_single' => Amount::parse($d['threshold_single'] ?? 0), 'threshold_annual' => Amount::parse($d['threshold_annual'] ?? 0), 'effective_from' => $d['effective_from']]);
        });
    }
}
