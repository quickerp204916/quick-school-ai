<?php

namespace Modules\Ai\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Models\Voucher;
use Modules\Accounting\Support\Amount;

/**
 * Rule-based checks for unusual accounting activity. Deterministic, so the findings can be trusted and explained;
 * the Accounting Assistant uses them as a tool and explains them in plain language.
 * Each finding: rule, severity (high | medium | low), title, detail, vouchers, amount (paise).
 */
final class AnomalyDetector
{
    public const CASH_LIMIT = 1000000;          // ₹10,000: cash payments above this to one party in a day (Income-tax Act s.40A(3))
    public const ROUND_FROM = 10000000;         // ₹1,00,000
    public const WAIVER_FROM = 1000000;         // ₹10,000

    public function scan(string $from, string $to): array
    {
        $vouchers = Voucher::with('entries.ledger')->whereIn('status', ['posted', 'reversed'])->whereBetween('date', [$from, $to])->get();
        $found = array_merge(
            $this->duplicates($vouchers), $this->splits($vouchers), $this->cash($vouchers), $this->selfApproved($vouchers),
            $this->backdated($vouchers), $this->round($vouchers), $this->reversals($vouchers), $this->waivers($vouchers), $this->spikes($from, $to),
        );
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($found, fn ($a, $b) => [$rank[$a['severity']], -$a['amount']] <=> [$rank[$b['severity']], -$b['amount']]);

        return $found;
    }

    /** The party a payment went to: the largest non-cash, non-tax debit. */
    private function party(Voucher $v): ?\Modules\Accounting\Models\Ledger
    {
        return $v->entries->filter(fn ($e) => $e->debit > 0 && ! $e->ledger->isCashOrBank() && $e->ledger->type !== 'tax')->sortByDesc('debit')->first()?->ledger;
    }

    private function payments(Collection $vs): Collection
    {
        return $vs->where('type', 'payment')->where('status', 'posted');
    }

    private static function f(string $rule, string $severity, string $title, string $detail, array $vouchers, int $amount): array
    {
        return compact('rule', 'severity', 'title', 'detail', 'vouchers', 'amount') + ['amount_display' => Amount::format($amount)];
    }

    private function duplicates(Collection $vs): array
    {
        $out = [];
        $list = $this->payments($vs)->sortBy('date')->values();
        foreach ($list as $i => $a) {
            foreach ($list->slice($i + 1) as $b) {
                if ($b->date->diffInDays($a->date, true) > 7) {
                    break;
                }
                if ($a->total === $b->total && ($pa = $this->party($a)) && $pa->id === $this->party($b)?->id) {
                    $out[] = self::f('duplicate_payment', 'high', 'Possible duplicate payment', "{$pa->name} was paid ".Amount::format($a->total)." twice within a week ({$a->number} on {$a->date->format('j M')}, {$b->number} on {$b->date->format('j M')}).", [$a->number, $b->number], $a->total);
                }
            }
        }

        return $out;
    }

    private function splits(Collection $vs): array
    {
        $limit = (int) ((\Modules\Accounting\Models\AccountingSetting::current()->approval_thresholds ?? [])['payment'] ?? 0);
        if ($limit <= 0) {
            return [];
        }
        $out = [];
        foreach ($this->payments($vs)->filter(fn ($v) => $v->total < $limit)->groupBy(fn ($v) => $v->date->format('Y-m-d').'|'.$this->party($v)?->id) as $key => $g) {
            if ($g->count() >= 2 && $g->sum('total') >= $limit && ! str_ends_with($key, '|')) {
                $out[] = self::f('split_below_limit', 'high', 'Payments split under the approval limit', $this->party($g->first())->name.' received '.$g->count().' payments on '.$g->first()->date->format('j M Y')
                    .' totalling '.Amount::format((int) $g->sum('total')).', each under the '.Amount::format($limit).' approval limit.', $g->pluck('number')->all(), (int) $g->sum('total'));
            }
        }

        return $out;
    }

    private function cash(Collection $vs): array
    {
        $out = [];
        $cashPaid = $this->payments($vs)->filter(fn ($v) => $v->entries->contains(fn ($e) => $e->credit > 0 && $e->ledger->type === 'cash'));
        foreach ($cashPaid->groupBy(fn ($v) => $v->date->format('Y-m-d').'|'.$this->party($v)?->id) as $g) {
            $cash = (int) $g->sum(fn ($v) => $v->entries->filter(fn ($e) => $e->credit > 0 && $e->ledger->type === 'cash')->sum('credit'));
            if ($cash > self::CASH_LIMIT) {
                $out[] = self::f('cash_over_limit', 'medium', 'Cash payment above ₹10,000', ($this->party($g->first())?->name ?? 'A party').' was paid '.Amount::format($cash).' in cash on '.$g->first()->date->format('j M Y')
                    .'. Payments above ₹10,000 a day to one person should be by bank (Income-tax Act s.40A(3)).', $g->pluck('number')->all(), $cash);
            }
        }

        return $out;
    }

    private function selfApproved(Collection $vs): array
    {
        return $vs->filter(fn ($v) => $v->approved_by && $v->approved_by === $v->created_by)->map(fn ($v) => self::f('self_approved', 'high', 'Approved by the person who entered it',
            "{$v->number} (".Amount::format($v->total).') was entered and approved by the same person.', [$v->number], $v->total))->values()->all();
    }

    private function backdated(Collection $vs): array
    {
        return $vs->filter(fn ($v) => $v->posted_at && in_array($v->type, config('accounting.manual_types'), true) && (int) floor($v->date->diffInDays($v->posted_at->copy()->startOfDay(), false)) > 30)
            ->map(fn ($v) => self::f('backdated', 'medium', 'Posted long after its date', "{$v->number} is dated {$v->date->format('j M Y')} but was posted on {$v->posted_at->format('j M Y')}.", [$v->number], $v->total))
            ->values()->all();
    }

    private function round(Collection $vs): array
    {
        return $this->payments($vs)->filter(fn ($v) => $v->total >= self::ROUND_FROM && $v->total % 1000000 === 0 && ! $v->source_type)
            ->map(fn ($v) => self::f('round_amount', 'low', 'Large round-figure payment', "{$v->number}: ".Amount::format($v->total).' to '.($this->party($v)?->name ?? 'a party').'. Check there is a bill for it.', [$v->number], $v->total))
            ->values()->all();
    }

    private function reversals(Collection $vs): array
    {
        $out = [];
        foreach ($vs->where('type', 'reversal')->groupBy('created_by') as $by => $g) {
            if ($g->count() >= 3) {
                $name = \Modules\Identity\Models\User::find($by)?->name ?? 'One user';
                $out[] = self::f('frequent_reversals', 'medium', 'Many reversals by one person', "{$name} reversed {$g->count()} vouchers in this period.", $g->pluck('number')->all(), (int) $g->sum('total'));
            }
        }

        return $out;
    }

    private function waivers(Collection $vs): array
    {
        return $vs->where('source_type', 'fee_waiver')->where('status', 'posted')->filter(fn ($v) => $v->total >= self::WAIVER_FROM)
            ->map(fn ($v) => self::f('large_waiver', 'medium', 'Large fee waiver', "{$v->number}: ".Amount::format($v->total).' waived. '.$v->narration, [$v->number], $v->total))->values()->all();
    }

    /** An expense ledger whose spend this month is more than three times its average month before. */
    private function spikes(string $from, string $to): array
    {
        $end = CarbonImmutable::parse($to);
        $monthStart = $end->startOfMonth()->format('Y-m-d');
        $rows = LedgerEntry::query()->join('ledgers as l', 'l.id', '=', 'ledger_entries.ledger_id')->join('account_groups as g', 'g.id', '=', 'l.group_id')
            ->where('g.nature', 'expense')->whereBetween('ledger_entries.date', [$from, $to])
            ->get(['ledger_entries.ledger_id', 'l.name', 'ledger_entries.date', 'ledger_entries.debit', 'ledger_entries.credit']);
        $out = [];
        foreach ($rows->groupBy('ledger_id') as $g) {
            $byMonth = $g->groupBy(fn ($e) => substr((string) $e->date, 0, 7))->map(fn ($m) => (int) $m->sum(fn ($e) => $e->debit - $e->credit));
            $current = $byMonth[substr($monthStart, 0, 7)] ?? 0;
            $before = $byMonth->except(substr($monthStart, 0, 7))->filter(fn ($v) => $v > 0);
            if ($before->count() >= 3 && $current > 1000000 && $current > 3 * $before->avg()) {
                $out[] = self::f('spend_spike', 'low', 'Unusual spend this month', "{$g->first()->name}: ".Amount::format($current).' this month against an average of '.Amount::format((int) $before->avg()).'.', [], $current);
            }
        }

        return $out;
    }
}
