<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Academic\Support\Rules;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\DepreciationPosting;
use Modules\Accounting\Models\FixedAsset;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\Voucher;
use Modules\Accounting\Support\Amount;

/** Fixed asset register and monthly depreciation, posted to the books once per asset and month. */
final class AssetService
{
    public function __construct(private readonly CurrentMember $member, private readonly VoucherService $vouchers)
    {
    }

    public function register(array $data): FixedAsset
    {
        $this->member->authorize('accounts.configure');
        $d = Rules::check($data, [
            'code' => ['required', 'string', 'max:30', Rules::unique('fixed_assets', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'asset_ledger_id' => ['required', Rules::exists('ledgers')],
            'put_to_use_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'cost' => ['required'], 'salvage' => ['nullable'],
            'method' => ['required', Rule::in(['slm', 'wdv'])],
            'rate' => ['required', 'numeric', 'gt:0', 'max:100'],
            'branch_id' => ['nullable', Rules::exists('branches')],
            'cost_centre_id' => ['nullable', Rules::exists('cost_centres')],
        ]);
        $ledger = Ledger::with('group')->findOrFail($d['asset_ledger_id']);
        if ($ledger->group->code !== 'A2' && $ledger->group->parent?->code !== 'A2') {
            throw new AccountingException('Choose a fixed asset ledger.', 'asset_ledger_id');
        }
        $cost = Amount::parse($d['cost']);
        $salvage = Amount::parse($d['salvage'] ?? 0);
        if ($cost <= 0 || $salvage < 0 || $salvage >= $cost) {
            throw new AccountingException('The cost must be more than zero and more than the salvage value.', 'cost');
        }
        $a = FixedAsset::create(['code' => strtoupper($d['code']), 'name' => $d['name'], 'asset_ledger_id' => $ledger->id, 'depreciation_ledger_id' => Ledger::system('depreciation')->id,
            'accumulated_ledger_id' => Ledger::system('accumulated_depreciation')->id, 'put_to_use_on' => $d['put_to_use_on'], 'cost' => $cost, 'salvage' => $salvage,
            'method' => $d['method'], 'rate_bp' => (int) round($d['rate'] * 100), 'branch_id' => $d['branch_id'] ?? null, 'cost_centre_id' => $d['cost_centre_id'] ?? null]);
        Audit::record('asset.registered', $a, ['cost' => Amount::format($cost)]);

        return $a;
    }

    public function bookValue(FixedAsset $a, ?string $before = null): int
    {
        return $a->cost - (int) $a->postings()->when($before, fn ($q) => $q->where('period', '<', $before))->sum('amount');
    }

    /** One month's charge: straight line on (cost − salvage), or written down on the opening book value; never below salvage. */
    public function charge(FixedAsset $a, string $period): int
    {
        $monthEnd = Carbon::parse($period.'-01')->endOfMonth();
        if ($a->status !== 'active' || $a->put_to_use_on->gt($monthEnd)) {
            return 0;
        }
        $book = $this->bookValue($a, $period);
        $raw = $a->method === 'slm' ? intdiv(($a->cost - $a->salvage) * $a->rate_bp, 10000 * 12) : intdiv($book * $a->rate_bp, 10000 * 12);

        return max(0, min($raw, $book - $a->salvage));
    }

    /** Posts depreciation for a month for every asset not yet charged. Safe to run again. */
    public function run(string $period): ?Voucher
    {
        $this->member->authorize('accounts.post');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new AccountingException('Choose a month.');
        }
        $date = Carbon::parse($period.'-01')->endOfMonth()->format('Y-m-d');
        if ($date > today()->endOfMonth()->format('Y-m-d')) {
            throw new AccountingException('Depreciation cannot be posted for a future month.');
        }
        $done = DepreciationPosting::where('period', $period)->pluck('asset_id');
        $charges = FixedAsset::whereNotIn('id', $done)->get()->mapWithKeys(fn ($a) => [$a->id => ['asset' => $a, 'amount' => $this->charge($a, $period)]])->filter(fn ($c) => $c['amount'] > 0);
        if ($charges->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($charges, $period, $date) {
            $lines = [];
            foreach ($charges as $c) {
                $lines[] = ['ledger_id' => $c['asset']->depreciation_ledger_id, 'debit' => $c['amount'], 'cost_centre_id' => $c['asset']->cost_centre_id, 'narration' => $c['asset']->code.' '.$c['asset']->name];
                $lines[] = ['ledger_id' => $c['asset']->accumulated_ledger_id, 'credit' => $c['amount'], 'narration' => $c['asset']->code];
            }
            $v = $this->vouchers->record('journal', $date, $lines, 'depreciation', $period, "Depreciation for {$period}", null, null,
                'depreciation:'.$period.':'.sha1($charges->keys()->sort()->implode(',')));
            foreach ($charges as $id => $c) {
                DepreciationPosting::create(['asset_id' => $id, 'voucher_id' => $v->id, 'period' => $period, 'amount' => $c['amount']]);
            }

            return $v;
        });
    }
}
