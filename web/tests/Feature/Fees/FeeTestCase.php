<?php

namespace Tests\Feature\Fees;

use Modules\Academic\Models\Student;
use Modules\Fees\Models\FeeConcession;
use Modules\Fees\Models\FeeHead;
use Modules\Fees\Models\FeeInvoice;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Models\FeeStructure;
use Modules\Fees\Models\StudentFeePlan;
use Modules\Fees\Services\CollectionService;
use Modules\Fees\Services\FeePlanService;
use Modules\Institution\Models\AcademicSession;
use Tests\Feature\Accounting\AccountingTestCase;

/**
 * An institution with books and the default fee heads. Today is Wed 4 Nov 2026; the session runs April 2026 to March 2027.
 * Standard structure: tuition ₹3,000 a month, annual charges ₹6,000, admission ₹10,000 once, optional transport ₹1,200 a month, due on the 10th.
 */
abstract class FeeTestCase extends AccountingTestCase
{
    protected function feeHead(string $code): FeeHead
    {
        return $this->in(fn () => FeeHead::where('code', $code)->firstOrFail());
    }

    protected function plans(): FeePlanService
    {
        return app(FeePlanService::class);
    }

    protected function collections(): CollectionService
    {
        return app(CollectionService::class);
    }

    protected function structure(array $extra = []): FeeStructure
    {
        return $this->in(fn () => $this->plans()->saveStructure($extra + [
            'name' => 'Class 5 · 2026-27', 'session_id' => AcademicSession::current()->id, 'due_day' => 10, 'late_fee_type' => 'none',
            'items' => [
                ['fee_head_id' => $this->feeHead('TUITION')->id, 'frequency' => 'monthly', 'amount' => '3000'],
                ['fee_head_id' => $this->feeHead('ANNUAL')->id, 'frequency' => 'annual', 'amount' => '6000'],
                ['fee_head_id' => $this->feeHead('ADMISSION')->id, 'frequency' => 'one_time', 'amount' => '10000'],
                ['fee_head_id' => $this->feeHead('TRANSPORT')->id, 'frequency' => 'monthly', 'amount' => '1200'],
            ],
        ]));
    }

    /** A student on the standard structure, charged from April. */
    protected function feeStudent(array $plan = [], ?FeeStructure $s = null): Student
    {
        $student = $this->student($this->feeSection());
        $s ??= $this->structure(['name' => 'Structure '.uniqid()]);
        $this->in(fn () => $this->plans()->assign($student, $plan + ['structure_id' => $s->id, 'starts_from' => '2026-04-01']));

        return $student;
    }

    protected function feeSection(): \Modules\Academic\Models\Section
    {
        return $this->in(fn () => \Modules\Academic\Models\Section::where('name', 'A')->first()) ?? $this->section();
    }

    protected function invoices(Student $s)
    {
        return $this->in(fn () => FeeInvoice::where('student_id', $s->id)->orderBy('instalment')->get());
    }

    protected function due(Student $s): int
    {
        return $this->in(fn () => FeeInvoice::where('student_id', $s->id)->whereIn('status', ['open', 'partial'])->get()->sum(fn ($i) => $i->balance()));
    }

    protected function pay(Student $s, string $amount, array $extra = []): FeePayment
    {
        return $this->in(fn () => $this->collections()->collect($s, $extra + ['amount' => $amount, 'mode' => 'cash', 'date' => '2026-11-04', 'idempotency_key' => 'k-'.uniqid('', true)]));
    }

    protected function concession(string $name = 'Sibling', int $percent = 10, array $heads = ['TUITION']): FeeConcession
    {
        return $this->in(fn () => FeeConcession::create(['name' => $name, 'category' => 'sibling', 'type' => 'percent', 'value' => $percent * 100,
            'fee_head_ids' => array_map(fn ($c) => $this->feeHead($c)->id, $heads)]));
    }

    protected function feePlan(Student $s): StudentFeePlan
    {
        return $this->in(fn () => StudentFeePlan::where('student_id', $s->id)->where('status', 'active')->firstOrFail());
    }

    protected function trialBalanceAgrees(): void
    {
        $tb = $this->in(fn () => app(\Modules\Accounting\Services\Books::class)->trialBalance('2026-04-01', '2027-03-31'));
        $this->assertGreaterThan(0, $tb['debit']);
        $this->assertSame($tb['debit'], $tb['credit'], 'Trial balance must agree');
    }
}
