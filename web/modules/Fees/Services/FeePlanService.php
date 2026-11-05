<?php

namespace Modules\Fees\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Models\Student;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Accounting\Models\Voucher;
use Modules\Accounting\Services\AccountingGateway;
use Modules\Accounting\Services\VoucherService;
use Modules\Fees\FeeException;
use Modules\Fees\Models\FeeConcession;
use Modules\Fees\Models\FeeHead;
use Modules\Fees\Models\FeeInvoice;
use Modules\Fees\Models\FeeInvoiceLine;
use Modules\Fees\Models\FeeStructure;
use Modules\Fees\Models\FeeStructureItem;
use Modules\Fees\Models\StudentFeeConcession;
use Modules\Fees\Models\StudentFeePlan;
use Modules\Fees\Support;
use Modules\Institution\Models\AcademicSession;

/**
 * Fee structures, student fee plans and their instalment invoices.
 *
 * Invoices are created as drafts when a plan is assigned and issued (numbered and posted to the books) from
 * their issue date: Dr Fees receivable and Fee concessions, Cr each head's income ledger. Late fees, concessions
 * approved later and waivers post their own vouchers, each idempotent on its source.
 */
final class FeePlanService
{
    public function __construct(private readonly CurrentMember $member, private readonly AccountingGateway $books)
    {
    }

    /* ---------------- structures ---------------- */

    public function saveStructure(array $d, ?FeeStructure $s = null): FeeStructure
    {
        $this->member->authorize('fees.configure');
        $items = collect($d['items'] ?? [])->filter(fn ($i) => filled($i['fee_head_id'] ?? null) && filled($i['amount'] ?? null))->values();
        if (blank($d['name'] ?? null)) {
            throw new FeeException('Give the structure a name, for example "Class 5 · 2026-27".', 'name');
        }
        if ($items->isEmpty()) {
            throw new FeeException('Add at least one fee head with an amount.', 'items');
        }
        if ($items->pluck('fee_head_id')->duplicates()->isNotEmpty()) {
            throw new FeeException('Each fee head can appear only once in a structure.', 'items');
        }
        $late = $d['late_fee_type'] ?? 'none';
        if (! array_key_exists($late, FeeStructure::LATE_TYPES)) {
            throw new FeeException('Choose a late fee rule.', 'late_fee_type');
        }
        $dueDay = (int) ($d['due_day'] ?? 10);
        if ($dueDay < 1 || $dueDay > 28) {
            throw new FeeException('The due day must be between 1 and 28 so it exists in every month.', 'due_day');
        }
        // Changing a structure affects plans assigned afterwards: invoices already created keep their own lines.

        return DB::transaction(function () use ($d, $s, $items, $late, $dueDay) {
            $s ??= new FeeStructure;
            $s->fill([
                'name' => trim($d['name']), 'session_id' => $d['session_id'] ?? AcademicSession::current()?->id, 'grade_level_id' => ($d['grade_level_id'] ?? null) ?: null,
                'branch_id' => $d['branch_id'] ?? null ?: null, 'due_day' => $dueDay, 'late_fee_type' => $late,
                'late_fee_value' => $late === 'percent' ? (int) round(((float) ($d['late_fee_value'] ?? 0)) * 100) : Support::paise($d['late_fee_value'] ?? 0, 'late_fee_value'),
                'grace_days' => max(0, (int) ($d['grace_days'] ?? 0)), 'late_fee_cap' => filled($d['late_fee_cap'] ?? null) ? Support::paise($d['late_fee_cap'], 'late_fee_cap') : null,
                'is_active' => (bool) ($d['is_active'] ?? true),
            ])->save();
            $s->items()->delete();
            foreach ($items as $i) {
                $freq = $i['frequency'] ?? 'annual';
                if (! array_key_exists($freq, FeeStructureItem::FREQUENCIES)) {
                    throw new FeeException('Choose how often each head is charged.', 'items');
                }
                $amount = Support::paise($i['amount'], 'items');
                if ($amount === 0) {
                    throw new FeeException('Amounts must be more than zero.', 'items');
                }
                $months = $freq === 'monthly' && ! empty($i['months']) ? array_values(array_map('intval', (array) $i['months'])) : null;
                $s->items()->create(['fee_head_id' => $i['fee_head_id'], 'frequency' => $freq, 'amount' => $amount, 'months' => $months]);
            }
            Audit::record('fees.structure_saved', $s);

            return $s->load('items.head');
        });
    }

    /**
     * The instalment schedule a structure produces for a plan: [instalment => [period, issue, due, lines[[head, description, amount]]]].
     * Periods before $from are skipped (mid-year admission), except a quarter or half year already running.
     */
    public function schedule(FeeStructure $s, array $optionalHeadIds = [], ?string $from = null): array
    {
        $session = $s->session;
        $start = CarbonImmutable::parse($session->starts_on)->startOfMonth();
        $fromMonth = CarbonImmutable::parse($from ?? $start)->startOfMonth();
        $len = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'annual' => 12, 'one_time' => 12];
        $months = [];
        $first = null;
        foreach ($s->items()->with('head')->get() as $item) {
            if ($item->head->kind === 'optional' && ! in_array($item->fee_head_id, $optionalHeadIds, true)) {
                continue;
            }
            $step = $len[$item->frequency];
            for ($m = 0; $m < 12; $m += $step) {
                $periodStart = $start->addMonths($m);
                if ($periodStart->addMonths($step) <= $fromMonth) {
                    continue;   // period ended before the student joined
                }
                if ($item->frequency === 'monthly' && $item->months && ! in_array($periodStart->month, $item->months, true)) {
                    continue;
                }
                $at = $periodStart < $fromMonth ? $fromMonth : $periodStart;
                $key = $at->format('Y-m');
                $first = $first === null || $key < $first ? $key : $first;
                $months[$key][] = ['head' => $item->head, 'amount' => $item->amount, 'frequency' => $item->frequency,
                    'description' => $item->head->name.match ($item->frequency) {
                        'monthly' => ' · '.$periodStart->format('M Y'),
                        'quarterly', 'half_yearly' => ' · '.$periodStart->format('M').'–'.$periodStart->addMonths($step - 1)->format('M Y'),
                        default => '',
                    }];
                if ($item->frequency === 'one_time') {
                    break;
                }
            }
        }
        // One-time heads belong in the student's first instalment.
        foreach ($months as $key => $lines) {
            foreach ($lines as $i => $l) {
                if ($l['frequency'] === 'one_time' && $key !== $first) {
                    $months[$first][] = $l;
                    unset($months[$key][$i]);
                }
            }
        }
        ksort($months);
        $out = [];
        $n = 0;
        foreach (array_filter($months) as $key => $lines) {
            $month = CarbonImmutable::parse($key.'-01');
            $due = $month->day($s->due_day);
            $out[++$n] = ['period' => $month->format('F Y'), 'due' => $due->format('Y-m-d'),
                'issue' => max($due->subDays(config('fees.issue_days_before_due'))->format('Y-m-d'), $month->format('Y-m-d')), 'lines' => array_values($lines)];
        }

        return $out;
    }

    /* ---------------- plans ---------------- */

    /** Assigns a structure to a student for the session, creates the instalment invoices and issues those already due for issue. */
    public function assign(Student $student, array $d): StudentFeePlan
    {
        $this->member->authorize('fees.configure');
        $s = FeeStructure::with('session')->find($d['structure_id'] ?? null) ?? throw new FeeException('Choose a fee structure.', 'structure_id');
        if (! $s->is_active) {
            throw new FeeException('This fee structure is inactive.', 'structure_id');
        }
        if (StudentFeePlan::where('student_id', $student->id)->where('session_id', $s->session_id)->where('status', 'active')->exists()) {
            throw new FeeException("{$student->fullName()} already has a fee plan for {$s->session->name}. Cancel it first to assign another.", 'structure_id');
        }
        $from = $d['starts_from'] ?? null ?: max($s->session->starts_on->format('Y-m-d'), $student->admitted_on?->format('Y-m-d') ?? '0000');
        $optional = array_values(array_filter((array) ($d['optional_head_ids'] ?? [])));
        $concessions = FeeConcession::whereIn('id', array_filter((array) ($d['concession_ids'] ?? [])))->where('is_active', true)->get();
        $autoApprove = $this->member->can('fees.approve');

        $plan = DB::transaction(function () use ($student, $s, $from, $optional, $concessions, $autoApprove, $d) {
            $plan = StudentFeePlan::create(['student_id' => $student->id, 'session_id' => $s->session_id, 'structure_id' => $s->id,
                'optional_head_ids' => $optional, 'status' => 'active', 'created_by' => $this->member->userId()]);
            foreach ($concessions as $c) {
                $plan->concessions()->create(['concession_id' => $c->id, 'reason' => $d['concession_reason'] ?? null, 'requested_by' => $this->member->userId(),
                    'status' => $autoApprove ? 'approved' : 'pending', 'approved_by' => $autoApprove ? $this->member->userId() : null, 'approved_at' => $autoApprove ? now() : null]);
            }
            $approved = $concessions->filter(fn () => $autoApprove);
            foreach ($this->schedule($s, $optional, $from) as $n => $inst) {
                $inv = FeeInvoice::create(['student_id' => $student->id, 'plan_id' => $plan->id, 'branch_id' => $student->branch_id, 'instalment' => $n,
                    'period' => $inst['period'], 'issue_date' => $inst['issue'], 'due_date' => $inst['due'], 'status' => 'draft']);
                foreach ($inst['lines'] as $l) {
                    $inv->lines()->create(['fee_head_id' => $l['head']->id, 'description' => $l['description'], 'amount' => $l['amount'], 'kind' => 'fee']);
                }
                $this->applyConcessions($inv, $approved);
            }
            Audit::record('fees.plan_assigned', $plan, ['structure' => $s->name, 'from' => $from]);

            return $plan;
        });
        $this->issueDue($student->id);

        return $plan->load('invoices.lines', 'concessions.concession');
    }

    /** Works out each line's concession from the approved concessions and stores the totals (drafts only). */
    private function applyConcessions(FeeInvoice $inv, Collection $concessions): void
    {
        $lines = $inv->lines()->where('kind', 'fee')->get();
        $off = $lines->mapWithKeys(fn ($l) => [$l->id => 0])->all();
        foreach ($concessions as $c) {
            $budget = $c->type === 'amount' ? $c->value : null;
            foreach ($lines as $l) {
                if (! $c->appliesTo($l->fee_head_id)) {
                    continue;
                }
                $room = $l->amount - $off[$l->id];
                $cut = $c->type === 'percent' ? intdiv($l->amount * $c->value + 5000, 10000) : min($budget, $room);
                $cut = min($cut, $room);
                $off[$l->id] += $cut;
                if ($budget !== null) {
                    $budget -= $cut;
                }
            }
        }
        foreach ($lines as $l) {
            $l->update(['concession' => $off[$l->id]]);
        }
        $inv->update(['gross' => (int) $lines->sum('amount'), 'concession' => array_sum($off)]);
    }

    public function approveConcession(StudentFeeConcession $sc, bool $approve, ?string $note = null): StudentFeeConcession
    {
        $this->member->authorize('fees.approve');
        if ($sc->status !== 'pending') {
            throw new FeeException('This concession has already been decided.');
        }
        if ($sc->requested_by === $this->member->userId()) {
            throw new FeeException('You requested this concession, so another approver has to decide it.');
        }
        DB::transaction(function () use ($sc, $approve, $note) {
            $sc->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $this->member->userId(), 'approved_at' => now(),
                'reason' => trim(($sc->reason ? $sc->reason.' · ' : '').($note ?? '')) ?: $sc->reason]);
            if ($approve) {
                $approved = FeeConcession::whereIn('id', $sc->plan->concessions()->where('status', 'approved')->pluck('concession_id'))->get();
                foreach ($sc->plan->invoices()->whereIn('status', ['draft', 'open', 'partial'])->get() as $inv) {
                    $this->reprice($inv, $approved);
                }
            }
            Audit::record($approve ? 'fees.concession_approved' : 'fees.concession_rejected', $sc);
        });

        return $sc;
    }

    /** Applies newly approved concessions to an invoice. Issued invoices get a concession voucher for the increase, never more than what is still due. */
    private function reprice(FeeInvoice $inv, Collection $approved): void
    {
        $before = $inv->concession;
        $this->applyConcessions($inv, $approved);
        $inv->refresh();
        if ($inv->status === 'draft') {
            return;
        }
        $delta = $inv->concession - $before;
        $room = $inv->total() - $inv->paid + $delta;   // balance before this concession
        $delta = min($delta, max(0, $room));
        if ($delta <= 0) {
            $inv->update(['concession' => $before]);   // nothing left to reduce: keep the books and invoice equal

            return;
        }
        $inv->update(['concession' => $before + $delta]);
        $this->books->post('journal', 'fee_concession', $inv->id.':'.($before + $delta), today()->format('Y-m-d'),
            [['key' => 'fee_concession', 'debit' => $delta], ['key' => 'fee_receivable', 'credit' => $delta]],
            "Concession on {$inv->number}", $inv->branch_id, $inv->number);
        $this->refresh($inv);
    }

    /** Issues every draft invoice whose issue date has come: number, post to the books, then use any advance the student has. */
    public function issueDue(?string $studentId = null, ?string $on = null): int
    {
        $on ??= today()->format('Y-m-d');
        $n = 0;
        foreach (FeeInvoice::where('status', 'draft')->where('issue_date', '<=', $on)->when($studentId, fn ($q) => $q->where('student_id', $studentId))
            ->orderBy('issue_date')->orderBy('instalment')->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$n) {
                $inv = FeeInvoice::lockForUpdate()->find($id);
                if ($inv->status !== 'draft') {
                    return;
                }
                $date = $inv->issue_date->format('Y-m-d');
                $inv->number = Support::number('FEE', $date);
                $credits = $inv->lines()->where('kind', 'fee')->with('head')->get()->groupBy(fn ($l) => $l->head->ledger_id)->map->sum('amount');
                $net = $inv->gross - $inv->concession;
                if ($inv->gross > 0) {
                    $lines = array_values(array_filter([
                        $net > 0 ? ['key' => 'fee_receivable', 'debit' => $net, 'bill_ref' => $inv->number] : null,
                        $inv->concession > 0 ? ['key' => 'fee_concession', 'debit' => $inv->concession] : null,
                    ]));
                    foreach ($credits as $ledger => $amount) {
                        $lines[] = ['ledger_id' => $ledger, 'credit' => $amount];
                    }
                    $inv->voucher_id = $this->books->post('journal', 'fee_invoice', $inv->id, $date, $lines,
                        "Fee invoice {$inv->number} · {$inv->period}", $inv->branch_id, $inv->number)->id;
                }
                $inv->status = 'open';
                $inv->save();
                $this->refresh($inv);
                $n++;
            });
        }
        if ($n) {
            app(CollectionService::class)->applyAdvances($studentId);
        }

        return $n;
    }

    /* ---------------- late fees, waivers, cancellation ---------------- */

    /** The late fee an invoice should carry on a date under its structure's rule. */
    public function lateFeeDue(FeeInvoice $inv, string $on): int
    {
        $s = $inv->plan->structure;
        if ($s->late_fee_type === 'none' || ! in_array($inv->status, ['open', 'partial'], true)) {
            return $inv->late_fee;
        }
        $days = (int) floor(CarbonImmutable::parse($inv->due_date)->diffInDays(CarbonImmutable::parse($on), false)) - $s->grace_days;
        if ($days <= 0 || $inv->gross - $inv->concession - $inv->waived - $inv->paid <= 0) {
            return $inv->late_fee;
        }
        $fee = match ($s->late_fee_type) {
            'flat' => $s->late_fee_value,
            'per_day' => $s->late_fee_value * (int) $days,
            'percent' => intdiv(($inv->gross - $inv->concession) * $s->late_fee_value + 5000, 10000),
        };
        if ($s->late_fee_cap !== null) {
            $fee = min($fee, $s->late_fee_cap);
        }

        return max($inv->late_fee, $fee);   // a late fee only grows; a reduction is a waiver
    }

    /** Brings late fees up to date for overdue invoices (one student, or all). Each increase is posted once. */
    public function applyLateFees(?string $studentId = null, ?string $on = null): int
    {
        $on ??= today()->format('Y-m-d');
        $n = 0;
        $ids = FeeInvoice::whereIn('status', ['open', 'partial'])->where('due_date', '<', $on)->when($studentId, fn ($q) => $q->where('student_id', $studentId))->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $on, &$n) {
                $inv = FeeInvoice::with('plan.structure')->lockForUpdate()->find($id);
                $target = $this->lateFeeDue($inv, $on);
                $delta = $target - $inv->late_fee;
                if ($delta <= 0) {
                    return;
                }
                $this->books->post('journal', 'fee_late', "{$inv->id}:{$target}", $on,
                    [['key' => 'fee_receivable', 'debit' => $delta, 'bill_ref' => $inv->number], ['key' => 'fee_late', 'credit' => $delta]],
                    "Late fee on {$inv->number}", $inv->branch_id, $inv->number);
                $line = $inv->lines()->where('kind', 'late_fee')->first();
                $line ? $line->update(['amount' => $target]) : $inv->lines()->create(['description' => 'Late fee', 'amount' => $target, 'kind' => 'late_fee']);
                $inv->update(['late_fee' => $target]);
                $this->refresh($inv);
                $n++;
            });
        }

        return $n;
    }

    /** Writes off part of what is due (a late fee or hardship), with a reason. Needs an approver. */
    public function waive(FeeInvoice $inv, mixed $amount, string $reason, ?string $token = null): FeeInvoice
    {
        $this->member->authorize('fees.approve');
        $p = Support::paise($amount);
        if (mb_strlen(trim($reason)) < 5) {
            throw new FeeException('Give the reason for the waiver.', 'reason');
        }
        if (! in_array($inv->status, ['open', 'partial'], true) || $p <= 0 || $p > $inv->balance()) {
            throw new FeeException('A waiver must be more than zero and no more than the balance ('.\Modules\Accounting\Support\Amount::format($inv->balance()).').', 'amount');
        }

        return DB::transaction(function () use ($inv, $p, $reason, $token) {
            $inv = FeeInvoice::lockForUpdate()->find($inv->id);
            $key = $inv->id.':'.substr(hash('sha256', (string) ($token ?: Str::uuid())), 0, 16);   // fits the 60-character source id
            if ($token && Voucher::where('idempotency_key', "fee_waiver:{$key}")->exists()) {
                return $inv;   // the same form was submitted twice: already waived
            }
            $this->books->post('journal', 'fee_waiver', $key, today()->format('Y-m-d'),
                [['key' => 'fee_concession', 'debit' => $p], ['key' => 'fee_receivable', 'credit' => $p, 'bill_ref' => $inv->number]],
                "Waiver on {$inv->number}: {$reason}", $inv->branch_id, $inv->number);
            $inv->update(['waived' => $inv->waived + $p]);
            Audit::record('fees.waived', $inv, ['amount' => $p, 'reason' => $reason]);

            return $this->refresh($inv);
        });
    }

    /** Cancels an invoice nobody has paid against. An issued one is reversed in the books. */
    public function cancel(FeeInvoice $inv, string $reason): FeeInvoice
    {
        $this->member->authorize('fees.approve');
        if ($inv->paid > 0) {
            throw new FeeException('Part of this invoice has been paid. Waive the balance instead, or refund the payment first.');
        }
        if ($inv->status === 'cancelled') {
            return $inv;
        }

        return DB::transaction(function () use ($inv, $reason) {
            foreach (Voucher::where('status', 'posted')->whereIn('source_type', ['fee_invoice', 'fee_late', 'fee_concession', 'fee_waiver'])
                ->where(fn ($q) => $q->where('source_id', $inv->id)->orWhere('source_id', 'like', $inv->id.':%'))->get() as $v) {
                app(VoucherService::class)->reverse($v, "Fee invoice cancelled: {$reason}");
            }
            $inv->update(['status' => 'cancelled']);
            Audit::record('fees.invoice_cancelled', $inv, ['reason' => $reason]);

            return $inv;
        });
    }

    /** Cancels a plan: drafts and unpaid invoices are cancelled; paid ones stay. */
    public function cancelPlan(StudentFeePlan $plan, string $reason): StudentFeePlan
    {
        $this->member->authorize('fees.approve');
        DB::transaction(function () use ($plan, $reason) {
            foreach ($plan->invoices()->where('status', '!=', 'cancelled')->get() as $inv) {
                if ($inv->paid === 0) {
                    $this->cancel($inv, $reason);
                }
            }
            $plan->update(['status' => 'cancelled']);
            Audit::record('fees.plan_cancelled', $plan, ['reason' => $reason]);
        });

        return $plan;
    }

    /** Recomputes paid and status from successful payment allocations. */
    public function refresh(FeeInvoice $inv): FeeInvoice
    {
        $paid = (int) $inv->allocations()->whereHas('payment', fn ($q) => $q->where('status', 'success'))->sum('amount');
        $inv->paid = $paid;
        if ($inv->status !== 'cancelled' && $inv->status !== 'draft') {
            $inv->status = $inv->total() <= $paid ? 'paid' : ($paid > 0 ? 'partial' : 'open');
        }
        $inv->save();

        return $inv;
    }

    public static function heads(): Collection
    {
        return FeeHead::where('is_active', true)->orderBy('sort_order')->get();
    }
}
