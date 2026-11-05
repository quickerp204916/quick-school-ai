<?php

namespace Tests\Feature\Ai;

use Modules\Accounting\Models\Voucher;
use Modules\Ai\Models\AiActionRequest;

/** The tool API the AI service calls back: signatures, live sessions, tenant binding, permissions, and proposals that never post. */
class ToolApiTest extends AiTestCase
{
    public function test_every_call_must_be_signed_and_carry_a_live_session_for_this_institution(): void
    {
        $token = $this->chatSession($this->ownerUser(), 'accounting');

        $this->tool('accounting.trial_balance', [], $token, sign: false)->assertUnauthorized();
        $this->tool('accounting.trial_balance', [], 'not-a-token')->assertForbidden();
        $other = $this->provision();
        $this->tool('accounting.trial_balance', [], $token, tenant: $other)->assertForbidden()->assertJsonPath('message', 'The AI session has expired or belongs to another institution.');
        $this->tool('payroll.runs', [], $token)->assertForbidden();   // not offered to the accounting assistant

        $tb = $this->tool('accounting.trial_balance', ['from' => '2026-04-01'], $token)->assertOk();
        $this->assertTrue($tb->json('agrees'));

        $this->in(fn () => app(\Modules\Ai\Services\AiSessions::class)->revoke($token));
        $this->tool('accounting.trial_balance', [], $token)->assertForbidden();
    }

    public function test_tools_follow_the_users_role(): void
    {
        $teacher = $this->member('teacher');
        $offered = $this->in(function () use ($teacher) {
            $this->actingAs($teacher, 'web');

            return array_column(app(\Modules\Ai\Tools\ToolRegistry::class)->available('accounting'), 'name');
        });
        $this->assertSame([], $offered, 'A teacher gets no accounting tools');

        // Even a token that lists the tool cannot make a teacher read the books.
        $token = $this->chatSession($teacher, 'accounting', ['accounting.trial_balance']);
        $this->tool('accounting.trial_balance', [], $token)->assertForbidden();

        $accountant = $this->member('accountant');
        $names = $this->in(function () use ($accountant) {
            $this->actingAs($accountant, 'web');

            return array_column(app(\Modules\Ai\Tools\ToolRegistry::class)->available('accounting'), 'name');
        });
        $this->assertContains('accounting.anomalies', $names);
        $this->assertContains('accounting.propose_voucher', $names);
    }

    public function test_a_teacher_only_finds_students_in_their_own_sections(): void
    {
        $mine = $this->section('Class 5', 'A');
        $other = $this->section('Class 5', 'B');
        $a = $this->student($mine, ['first_name' => 'Riya']);
        $this->student($other, ['first_name' => 'Riya']);
        $teacher = $this->member('teacher');
        $this->in(fn () => $mine->update(['class_teacher_id' => $this->staffOf($teacher)->id]));

        $r = $this->tool('students.search', ['query' => 'Riya'], $this->chatSession($teacher, 'academic'))->assertOk();
        $this->assertSame([$a->id], array_column($r->json('students'), 'id'));
    }

    public function test_proposing_a_voucher_posts_nothing(): void
    {
        $accountant = $this->member('accountant');
        $token = $this->chatSession($accountant, 'accounting');
        $before = $this->in(fn () => Voucher::count());

        $this->tool('accounting.propose_voucher', ['type' => 'payment', 'narration' => 'Electricity bill October', 'lines' => [
            ['ledger' => 'ELECTRICITY', 'debit' => '18500'], ['ledger' => 'Main bank account', 'credit' => '17000']]], $token)
            ->assertUnprocessable()->assertJsonPath('message', 'Debits (₹18,500.00) must equal credits (₹17,000.00) across at least two lines.');

        $r = $this->tool('accounting.propose_voucher', ['type' => 'payment', 'date' => '2026-11-04', 'narration' => 'Electricity bill October', 'lines' => [
            ['ledger' => 'ELECTRICITY', 'debit' => '18500'], ['ledger' => 'Main bank account', 'credit' => '18500']]], $token)->assertOk();
        $this->assertTrue($r->json('proposed'));
        $this->assertSame('pending', $r->json('approval_request.status'));
        $this->assertStringContainsString('Nothing has been posted', $r->json('approval_request.message'));

        $this->assertSame($before, $this->in(fn () => Voucher::count()), 'No voucher is created by the AI');
        $req = $this->in(fn () => AiActionRequest::firstOrFail());
        $this->assertSame([$accountant->id, 'accounts.approve', 1850000], [$req->requested_by, $req->permission, $req->payload['lines'][0]['debit']]);
    }

    public function test_admission_enquiries_and_call_logs_are_ordinary_records(): void
    {
        $fo = $this->member('front_office');
        $token = $this->chatSession($fo, 'admission');
        $level = $this->in(fn () => \Modules\Academic\Models\GradeLevel::where('is_active', true)->orderBy('sequence')->firstOrFail());
        $r = $this->tool('admission.create_enquiry', ['child_first_name' => 'Kabir', 'class' => $level->name, 'parent_name' => 'Neha Mehta', 'relation' => 'mother', 'phone' => '98765 43210'], $token)->assertOk();
        $this->assertTrue($r->json('created'));
        $this->assertSame(1, $this->tool('admission.funnel', [], $token)->assertOk()->json('stages.enquiry'));

        $voice = $this->chatSession($this->member('accountant'), 'voice');
        $this->tool('voice.log_call', ['purpose' => 'fee_reminder', 'phone' => '9876543210', 'outcome' => 'promised_to_pay', 'notes' => 'Will pay Friday'], $voice)->assertOk();
        $this->tool('voice.log_call', ['purpose' => 'fee_reminder', 'phone' => '9876543210', 'outcome' => 'maybe'], $voice)->assertUnprocessable();
        $this->assertSame(1, $this->in(fn () => \Modules\Ai\Models\AiCallLog::count()));
    }

    public function test_fee_tools(): void
    {
        $s = $this->feeStudent();
        $this->in(fn () => $this->plans()->issueDue(null, '2026-11-04'));
        $this->pay($s, '5000');
        $token = $this->chatSession($this->member('accountant'), 'accounting');

        $dues = $this->tool('fees.dues', [], $token)->assertOk();
        $this->assertSame($this->due($s), $dues->json('total.paise'));
        $acct = $this->tool('fees.student_account', ['student' => $s->admission_no], $token)->assertOk();
        $this->assertCount(1, $acct->json('receipts'));
        $this->assertSame(500000, $this->tool('fees.collections', ['from' => '2026-11-01', 'to' => '2026-11-30'], $token)->json('total.paise'));
    }
}
