<?php

namespace Modules\Fees\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Academic\Models\Enrollment;
use Modules\Fees\Models\FeeInvoice;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Models\StudentFeeConcession;

/** Outstanding dues with ageing, collections by day and mode, and concessions given. */
final class FeeReports
{
    public const BUCKETS = ['not_due' => 'Not yet due', 'd30' => '1–30 days', 'd60' => '31–60 days', 'd90' => '61–90 days', 'd90p' => 'Over 90 days'];

    /** Students with a balance, their dues split by how long they have been overdue. Optional section filter. */
    public function outstanding(string $asOn, ?string $sectionId = null): Collection
    {
        $ids = $sectionId ? Enrollment::where('section_id', $sectionId)->where('status', 'active')->pluck('student_id') : null;
        $invoices = FeeInvoice::with(['student.currentEnrollment.section.level', 'student.guardians'])->whereIn('status', ['open', 'partial'])
            ->where('issue_date', '<=', $asOn)->when($ids, fn ($q) => $q->whereIn('student_id', $ids))->get();
        $on = CarbonImmutable::parse($asOn);

        return $invoices->groupBy('student_id')->map(function ($list) use ($on) {
            $row = ['student' => $list->first()->student, 'invoices' => $list->count(), 'total' => 0] + array_fill_keys(array_keys(self::BUCKETS), 0);
            foreach ($list as $i) {
                $days = (int) floor($i->due_date->diffInDays($on, false));
                $b = $days <= 0 ? 'not_due' : ($days <= 30 ? 'd30' : ($days <= 60 ? 'd60' : ($days <= 90 ? 'd90' : 'd90p')));
                $row[$b] += $i->balance();
                $row['total'] += $i->balance();
            }

            return $row;
        })->filter(fn ($r) => $r['total'] > 0)->sortByDesc('total')->values();
    }

    /** Receipts in a period, with totals by mode and by day. Bounced cheques are listed but not counted. */
    public function collections(string $from, string $to): array
    {
        $list = FeePayment::with('student')->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('number')->get();
        $ok = $list->where('status', 'success');

        return ['list' => $list, 'total' => (int) $ok->sum('amount'), 'refunded' => (int) $ok->sum('refunded'),
            'by_mode' => $ok->groupBy('mode')->map->sum('amount')->sortDesc(), 'by_day' => $ok->groupBy(fn ($p) => $p->date->format('Y-m-d'))->map->sum('amount')];
    }

    public function concessions(): Collection
    {
        return StudentFeeConcession::with(['concession', 'plan.student'])->latest()->get()
            ->map(fn ($c) => ['row' => $c, 'given' => (int) \Modules\Fees\Models\FeeInvoice::where('plan_id', $c->plan_id)->whereNot('status', 'cancelled')->sum('concession')]);
    }
}
