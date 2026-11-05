<?php

namespace Tests\Feature\Fees;

use Illuminate\Support\Facades\Notification;
use Modules\Accounting\Models\Voucher;
use Modules\Fees\FeeException;
use Modules\Fees\Models\FeeInvoice;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Models\FeeRefund;
use Modules\Fees\Models\FeeSetting;
use Modules\Fees\Notifications\FeeReminder;
use Modules\Fees\Services\Reminders;

/** Fee structures, plans, invoices, collection, advances, late fees, concessions, waivers, bounces, refunds, reminders. */
class FeePlansAndCollectionTest extends FeeTestCase
{
    public function test_plan_creates_monthly_instalments_and_issues_the_ones_due(): void
    {
        $student = $this->feeStudent();
        $inv = $this->invoices($student);

        $this->assertCount(12, $inv, 'One invoice a month, April to March');
        $first = $inv->first();
        $this->assertSame(['April 2026', '2026-04-10', 1900000], [$first->period, $first->due_date->format('Y-m-d'), $first->gross], 'April carries tuition, annual and admission');
        $this->assertSame(300000, $inv[1]->gross, 'Transport is optional and not taken');
        // Invoices are issued 10 days before they fall due: April to November are out, December onwards are scheduled.
        $this->assertSame(8, $inv->where('status', 'open')->count());
        $this->assertSame('draft', $inv[8]->status);
        $this->assertMatchesRegularExpression('#^FEE/2026-27/\d{5}$#', $first->number);
        $this->assertSame(1900000 + 7 * 300000, $this->balance('FEE-REC'));
        $this->assertSame(-(8 * 300000 + 600000), $this->balance('FEE-TUITION'), 'Tuition and annual charges both post to tuition fee');
        $this->assertSame(-1000000, $this->balance('FEE-ADMISSION'));
        $this->trialBalanceAgrees();

        $this->assertThrows(fn () => $this->in(fn () => $this->plans()->assign($student, ['structure_id' => $first->plan->structure_id])), FeeException::class, 'already has a fee plan');
    }

    public function test_mid_year_admission_and_optional_transport(): void
    {
        $student = $this->student($this->feeSection(), ['admitted_on' => '2026-09-20']);
        $s = $this->structure(['name' => 'With transport']);
        $this->in(fn () => $this->plans()->assign($student, ['structure_id' => $s->id, 'optional_head_ids' => [$this->feeHead('TRANSPORT')->id]]));
        $inv = $this->invoices($student);

        $this->assertCount(7, $inv, 'September to March');
        $this->assertSame('September 2026', $inv[0]->period);
        $this->assertSame(300000 + 600000 + 1000000 + 120000, $inv[0]->gross);
        $this->assertSame(420000, $inv[1]->gross);
    }

    public function test_collection_pays_oldest_first_keeps_an_advance_and_never_records_twice(): void
    {
        $student = $this->feeStudent();
        $due = $this->due($student);
        $p = $this->pay($student, '26,000', ['idempotency_key' => 'counter-1']);

        $this->assertMatchesRegularExpression('#^RCT/2026-27/\d{5}$#', $p->number);
        $this->assertSame(2600000, $p->allocations->sum('amount'));
        $inv = $this->invoices($student);
        $this->assertSame(['paid', 'paid', 'paid', 'partial', 'open'], $inv->take(5)->pluck('status')->all(), 'April, May and June in full, July in part');
        $this->assertSame(100000, $inv[3]->paid);
        $this->assertSame($due - 2600000, $this->due($student));
        $this->assertSame(2600000, $this->balance('CASH'));

        // The same form submitted again: nothing new.
        $again = $this->pay($student, '26,000', ['idempotency_key' => 'counter-1']);
        $this->assertSame($p->id, $again->id);
        $this->assertSame(1, $this->in(fn () => FeePayment::count()));
        $this->assertThrows(fn () => $this->pay($student, '500', ['idempotency_key' => 'counter-1']), FeeException::class, 'already recorded');

        // Pay everything due and ₹5,000 more: the extra is an advance, used on December's invoice when it is issued.
        $rest = $this->due($student);
        $p2 = $this->pay($student, \Modules\Accounting\Support\Amount::input($rest + 500000), ['mode' => 'upi', 'reference' => 'UPI 4411']);
        $this->assertSame(500000, $p2->advance);
        $this->assertSame(-500000, $this->balance('FEE-ADV'));
        $this->assertSame(0, $this->balance('FEE-REC'));

        $this->travelTo('2026-12-01 09:00');
        $this->in(fn () => $this->plans()->issueDue());
        $dec = $this->invoices($student)[8];
        $this->assertSame('paid', $dec->status, 'The advance covers December in full');
        $this->assertSame(-200000, $this->balance('FEE-ADV'));
        $this->trialBalanceAgrees();
    }

    public function test_counter_rules(): void
    {
        $student = $this->feeStudent();
        $this->assertThrows(fn () => $this->pay($student, '0'), FeeException::class, 'amount received');
        $this->assertThrows(fn () => $this->pay($student, '100', ['mode' => 'cheque']), FeeException::class, 'cheque number');
        $this->assertThrows(fn () => $this->pay($student, '100', ['date' => '2026-11-05']), FeeException::class, 'future');
        $this->assertThrows(fn () => $this->pay($student, '100', ['mode' => 'razorpay']), FeeException::class, 'automatically');
        $this->assertThrows(fn () => $this->pay($student, '100', ['idempotency_key' => '']), FeeException::class, 'idempotency key');

        $this->actingAs($this->member('teacher'), 'web');
        $this->assertThrows(fn () => $this->pay($student, '100'), \Illuminate\Auth\Access\AuthorizationException::class);
    }

    public function test_late_fees_after_the_grace_period_are_posted_once_and_can_be_waived(): void
    {
        $s = $this->structure(['name' => 'Late', 'late_fee_type' => 'flat', 'late_fee_value' => '200', 'grace_days' => 5]);
        $student = $this->feeStudent([], $s);
        $n = $this->in(fn () => $this->plans()->applyLateFees(null, '2026-11-04'));

        $this->assertSame(7, $n, 'April to October are overdue; November is not due until the 10th');
        $this->assertSame(0, $this->in(fn () => $this->plans()->applyLateFees(null, '2026-11-04')), 'Running again posts nothing');
        $this->assertSame(-7 * 20000, $this->balance('FEE-LATE'));
        $oct = $this->invoices($student)[6];
        $this->assertSame([20000, 320000], [$oct->late_fee, $oct->balance()]);

        $this->in(fn () => $this->plans()->waive($oct, '200', 'Parent was travelling', 'tok-1'));
        $this->in(fn () => $this->plans()->waive($oct->fresh(), '200', 'Parent was travelling', 'tok-1'));   // double submit
        $this->assertSame(300000, $this->invoices($student)[6]->balance());
        $this->assertSame(20000, $this->balance('CONCESSION'));

        $this->actingAs($this->member('accountant'), 'web');
        $this->assertThrows(fn () => $this->in(fn () => $this->plans()->waive($oct, '100', 'Not allowed')), \Illuminate\Auth\Access\AuthorizationException::class);
        $this->trialBalanceAgrees();
    }

    public function test_per_day_late_fee_has_a_cap(): void
    {
        $s = $this->structure(['name' => 'Per day', 'late_fee_type' => 'per_day', 'late_fee_value' => '10', 'grace_days' => 0, 'late_fee_cap' => '250']);
        $student = $this->feeStudent([], $s);
        $inv = $this->in(fn () => FeeInvoice::with('plan.structure')->where('student_id', $student->id)->where('instalment', 8)->first());   // November, due 10 Nov

        $this->assertSame(0, $this->in(fn () => $this->plans()->lateFeeDue($inv, '2026-11-10')));
        $this->assertSame(1000 * 3, $this->in(fn () => $this->plans()->lateFeeDue($inv, '2026-11-13')));
        $this->assertSame(25000, $this->in(fn () => $this->plans()->lateFeeDue($inv, '2026-12-31')), 'Capped at ₹250');
    }

    public function test_concessions_need_a_second_person_and_post_to_the_books(): void
    {
        $sibling = $this->concession('Sibling', 10);
        $accountant = $this->member('accountant');
        $this->actingAs($accountant, 'web');
        $student = $this->feeStudent(['concession_ids' => [$sibling->id], 'concession_reason' => 'Elder sister in class 9']);
        $this->assertSame(0, $this->invoices($student)->sum('concession'), 'Nothing is applied until approved');

        $sc = $this->in(fn () => $this->feePlan($student)->concessions()->first());
        $this->assertThrows(fn () => $this->in(fn () => $this->plans()->approveConcession($sc, true)), \Illuminate\Auth\Access\AuthorizationException::class);

        $this->asOwner();
        $this->in(fn () => $this->plans()->approveConcession($sc, true));
        $inv = $this->invoices($student);
        $this->assertSame(30000, $inv[1]->concession, '10% of tuition only');
        $this->assertSame(30000, $inv[11]->concession, 'Scheduled invoices get it too');
        $this->assertSame(8 * 30000, $this->balance('CONCESSION'), 'Posted for the eight issued invoices');
        $this->trialBalanceAgrees();

        // Assigned by an approver: applied straight away.
        $other = $this->feeStudent(['concession_ids' => [$this->concession('Merit', 50)->id]]);
        $this->assertSame(150000, $this->invoices($other)[1]->concession);
    }

    public function test_requester_cannot_approve_their_own_concession(): void
    {
        $student = $this->student($this->feeSection());
        $s = $this->structure();
        $admin = $this->member('admin');
        $this->in(function () use ($student, $s) {
            $plan = $this->plans()->assign($student, ['structure_id' => $s->id, 'concession_ids' => []]);
            $plan->concessions()->create(['concession_id' => $this->concession()->id, 'status' => 'pending', 'requested_by' => $this->ownerUser()->id]);
        });
        $sc = $this->in(fn () => $this->feePlan($student)->concessions()->first());
        $this->assertThrows(fn () => $this->in(fn () => $this->plans()->approveConcession($sc, true)), FeeException::class, 'another approver');
        $this->actingAs($admin, 'web');
        $this->in(fn () => $this->plans()->approveConcession($sc, true));
        $this->assertSame('approved', $this->in(fn () => $sc->fresh())->status);
    }

    public function test_a_bounced_cheque_reverses_the_receipt_and_reopens_the_dues(): void
    {
        $student = $this->feeStudent();
        $before = $this->due($student);
        $p = $this->pay($student, '25000', ['mode' => 'cheque', 'reference' => 'HDFC 004512']);
        $this->assertSame($before - 2500000, $this->due($student));

        $this->in(fn () => $this->collections()->bounce($p, 'Insufficient funds'));
        $this->assertSame('bounced', $this->in(fn () => $p->fresh())->status);
        $this->assertSame($before, $this->due($student));
        $this->assertSame(0, $this->balance('BANK') - $this->balance('BANK', '2026-03-31'), 'Bank deposit reversed');
        $this->assertSame(1, $this->in(fn () => Voucher::where('type', 'reversal')->count()));
        $this->assertThrows(fn () => $this->in(fn () => $this->collections()->bounce($this->in(fn () => $p->fresh()), 'Again please')), FeeException::class);
        $this->assertThrows(fn () => $this->in(fn () => $this->in(fn () => $p->fresh())->update(['amount' => 1])), \LogicException::class, 'cannot be changed');
        $this->trialBalanceAgrees();
    }

    public function test_refunds_need_approval_by_another_person_then_are_paid_out(): void
    {
        $student = $this->feeStudent();
        $p = $this->pay($student, \Modules\Accounting\Support\Amount::input($this->due($student) + 100000));   // ₹1,000 too much
        $accountant = $this->member('accountant');
        $this->actingAs($accountant, 'web');
        $this->assertThrows(fn () => $this->in(fn () => $this->collections()->requestRefund($p, ['amount' => '99999999', 'reason' => 'Too much'])), FeeException::class, 'no more than');
        $r = $this->in(fn () => $this->collections()->requestRefund($p, ['amount' => '1000', 'reason' => 'Paid ₹1,000 extra', 'mode' => 'bank']));
        $this->assertThrows(fn () => $this->in(fn () => $this->collections()->approveRefund($r)), \Illuminate\Auth\Access\AuthorizationException::class);

        $this->asOwner();
        $this->in(fn () => $this->collections()->approveRefund($r));
        $this->assertSame([-100000, 0], [$this->balance('FEE-REFUND'), $this->balance('FEE-ADV')], 'Moved from advance to refunds payable');
        $this->assertThrows(fn () => $this->in(fn () => $this->collections()->processRefund($this->in(fn () => $r->fresh()))), FeeException::class, 'UTR');
        $this->in(fn () => $this->collections()->processRefund($this->in(fn () => $r->fresh()), ['reference' => 'NEFT-RF-1']));
        $this->assertSame(['processed', 0], [$this->in(fn () => $r->fresh())->status, $this->balance('FEE-REFUND')]);
        $this->assertSame(100000, $this->in(fn () => $p->fresh())->refunded);

        // Refunding a paid fee reopens the invoice.
        $r2 = $this->in(fn () => $this->collections()->requestRefund($this->in(fn () => $p->fresh()), ['amount' => '3000', 'reason' => 'Fee charged twice', 'mode' => 'cash']));
        $this->assertThrows(fn () => $this->in(fn () => $this->collections()->approveRefund($r2)), FeeException::class, 'another approver');
        $this->actingAs($this->member('admin'), 'web');
        $this->in(fn () => $this->collections()->approveRefund($r2));
        $this->assertSame(300000, $this->due($student));
        $this->trialBalanceAgrees();
    }

    public function test_reminders_go_once_on_the_configured_days(): void
    {
        Notification::fake();
        $student = $this->feeStudent();
        $this->in(fn () => FeeSetting::current()->update(['reminder_days_before' => [6], 'reminder_days_after' => [25]]));

        $r = $this->in(fn () => app(Reminders::class)->send('2026-11-04'));
        // November (due 10 Nov, 6 days away) and October (due 10 Oct, 25 days ago).
        $this->assertSame(2, $r['sent']);
        $this->assertSame(0, $this->in(fn () => app(Reminders::class)->send('2026-11-04'))['sent'], 'Never twice');
        Notification::assertSentOnDemandTimes(FeeReminder::class, 2);
    }

    public function test_cancelling_a_plan_reverses_unpaid_invoices(): void
    {
        $student = $this->feeStudent();
        $this->pay($student, '19000');   // April
        $this->in(fn () => $this->plans()->cancelPlan($this->feePlan($student), 'Student left on 4 Nov'));
        $inv = $this->invoices($student);

        $this->assertSame('paid', $inv[0]->status);
        $this->assertSame(11, $inv->where('status', 'cancelled')->count());
        $this->assertSame(0, $this->balance('FEE-REC'));
        $this->trialBalanceAgrees();
    }
}
