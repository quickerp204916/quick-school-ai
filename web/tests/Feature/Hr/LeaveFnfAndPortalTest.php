<?php

namespace Tests\Feature\Hr;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Academic\Models\StaffMember;
use Modules\Hr\HrException;
use Modules\Hr\Models\Attendance;
use Modules\Hr\Models\EmployeeProfile;
use Modules\Hr\Models\FnfSettlement;
use Modules\Hr\Models\LeaveRequest;
use Modules\Hr\Models\LeaveType;
use Modules\Hr\Services\FnfService;
use Modules\Hr\Services\LeaveService;
use Modules\Identity\Models\User;

/** Leave with approval, full and final settlement, documents, the self-service portal, the HR API and the screens. */
class LeaveFnfAndPortalTest extends HrTestCase
{
    private function leaveType(string $code): LeaveType
    {
        return $this->in(fn () => LeaveType::where('code', $code)->firstOrFail());
    }

    /** A teacher with a login, an HR profile and a salary. */
    private function staffUser(): array
    {
        $user = $this->member('teacher');
        $staff = $this->staffOf($user);
        $this->in(fn () => app(\Modules\Hr\Services\EmployeeService::class)->revise($staff, ['effective_from' => '2025-06-01', 'components' => ['BASIC' => '30000', 'HRA' => '10000']]));

        return [$user, $staff];
    }

    public function test_leave_is_applied_approved_by_someone_else_and_marked_in_attendance(): void
    {
        [$user, $staff] = $this->staffUser();
        $this->actingAs($user, 'web');
        // Fri 6 Nov to Mon 9 Nov: Sunday is a weekly off, so three working days.
        $r = $this->in(fn () => app(LeaveService::class)->apply($staff, ['leave_type_id' => $this->leaveType('CL')->id, 'from_date' => '2026-11-06', 'to_date' => '2026-11-09', 'reason' => 'Family wedding']));
        $this->assertSame([3.0, 'pending'], [$r->days, $r->status]);
        $this->assertThrows(fn () => $this->in(fn () => app(LeaveService::class)->apply($staff, ['leave_type_id' => $this->leaveType('SL')->id, 'from_date' => '2026-11-09', 'reason' => 'Overlap'])), HrException::class, 'already a leave');
        $this->assertThrows(fn () => $this->in(fn () => app(LeaveService::class)->apply($staff, ['leave_type_id' => $this->leaveType('CL')->id, 'from_date' => '2026-11-16', 'to_date' => '2026-11-30', 'reason' => 'Too long'])), HrException::class, 'left');
        $this->assertThrows(fn () => $this->in(fn () => app(LeaveService::class)->decide($r, true)), \Illuminate\Auth\Access\AuthorizationException::class);

        $this->asRole('hr');
        $this->in(fn () => app(LeaveService::class)->decide($r, true));
        $marks = $this->in(fn () => Attendance::where('staff_member_id', $staff->id)->orderBy('date')->get());
        $this->assertSame(['2026-11-06', '2026-11-07', '2026-11-09'], $marks->map(fn ($m) => $m->date->format('Y-m-d'))->all());
        $this->assertTrue($marks->every(fn ($m) => $m->status === 'leave' && $m->paid_leave));
        $this->assertSame(9.0, $this->in(fn () => app(LeaveService::class)->balance($staff, $this->leaveType('CL'), '2026-27')));

        // Unpaid leave costs pay; paid leave does not.
        $lwp = $this->in(fn () => app(LeaveService::class)->apply($staff, ['leave_type_id' => $this->leaveType('LWP')->id, 'from_date' => '2026-11-12', 'reason' => 'Personal']));
        $this->in(fn () => app(LeaveService::class)->decide($lwp, true));
        $slip = $this->slip($this->process(), $staff);
        $this->assertSame([1.0, 29.0], [$slip->lop_days, $slip->paid_days]);
    }

    public function test_hr_cannot_approve_their_own_leave(): void
    {
        $hr = $this->asRole('hr');
        $me = $this->staffOf($hr);
        $r = $this->in(fn () => app(LeaveService::class)->apply($me, ['leave_type_id' => $this->leaveType('CL')->id, 'from_date' => '2026-11-10', 'reason' => 'Doctor visit']));
        $this->assertThrows(fn () => $this->in(fn () => app(LeaveService::class)->decide($r, true)), HrException::class, 'your own leave');
    }

    public function test_full_and_final_settlement_with_gratuity_encashment_and_notice(): void
    {
        $s = $this->employee('LEAVER', ['BASIC' => '30000', 'HRA' => '15000'], ['joined_on' => '2020-06-01', 'notice_days' => 30, 'salary_from' => '2020-06-01']);
        $this->in(fn () => app(\Modules\Hr\Services\EmployeeService::class)->relieve($s, ['resigned_on' => '2026-11-01', 'relieved_on' => '2026-11-20', 'exit_reason' => 'Moving city']));
        $hr = $this->asRole('hr');
        $f = $this->in(fn () => app(FnfService::class)->prepare($s));

        $this->assertSame([20.0, 3000000], [$f->salary_days, $f->salary], '20 days of November');
        $this->assertSame([15.0, 1730800], [$f->encash_days, $f->leave_encashment], '15 days of earned leave at ₹30,000 ÷ 26');
        $this->assertSame(10384600, $f->gratuity, '15/26 × ₹30,000 × 6 years (6 years 5 months)');
        $this->assertSame(1650000, $f->notice_recovery, '11 days of notice not served');
        $this->assertSame(3000000 + 1730800 + 10384600 - 1650000, $f->net);
        $this->assertThrows(fn () => $this->in(fn () => app(FnfService::class)->approve($f)), \Illuminate\Auth\Access\AuthorizationException::class);

        $this->asRole('admin');
        $this->in(fn () => app(FnfService::class)->approve($f));
        $this->assertSame([3000000, 10384600, 1730800, -1650000], [$this->balance('SALARY'), $this->balance('GRATUITY'), $this->balance('LEAVE-ENCASH'), $this->balance('NOTICE-RECOVERY')]);
        $this->assertSame(-$f->net, $this->balance('SAL-PAY'));
        $this->assertSame('inactive', $this->in(fn () => $s->fresh()->status));
        $this->assertThrows(fn () => $this->process(), HrException::class, 'Nobody', 'Settled staff are not paid again in payroll');
    }

    public function test_documents_are_private_to_hr_and_the_employee(): void
    {
        Storage::fake('local');
        [$user, $staff] = $this->staffUser();
        $doc = $this->in(fn () => app(\Modules\Hr\Services\EmployeeService::class)->upload($staff, 'pan', 'PAN card', UploadedFile::fake()->create('pan.pdf', 50, 'application/pdf')));
        $this->assertThrows(fn () => $this->in(fn () => app(\Modules\Hr\Services\EmployeeService::class)->upload($staff, 'pan', 'x', UploadedFile::fake()->create('x.exe', 5, 'application/x-msdownload'))), HrException::class, 'PDF');

        $this->actingAs($user, 'web');
        $this->get($this->at("me/documents/{$doc->id}"))->assertOk();
        $this->actingAs($this->member('teacher'), 'web');
        $this->get($this->at("me/documents/{$doc->id}"))->assertForbidden();
        $this->get($this->at("hr/documents/{$doc->id}"))->assertForbidden();
    }

    public function test_self_service_portal_and_api_show_only_ones_own_records(): void
    {
        [$user, $staff] = $this->staffUser();
        $other = $this->employee('OTH', ['BASIC' => '20000']);
        $run = $this->process();
        $mine = $this->slip($run, $staff);
        $theirs = $this->slip($run, $other);

        $this->actingAs($user, 'web');
        $this->get($this->at("me/payslips/{$mine->id}"))->assertForbidden();   // not approved yet
        $this->asRole('admin');
        $this->in(fn () => $this->payroll()->approve($run));

        $this->actingAs($user, 'web');
        $this->get($this->at('me'))->assertOk()->assertSee('My payslips')->assertSee('November 2026');
        $this->get($this->at("me/payslips/{$mine->id}"))->assertOk()->assertSee('Net pay');
        $this->get($this->at("me/payslips/{$theirs->id}"))->assertForbidden();
        $this->get($this->at('hr'))->assertForbidden();

        $user->forceFill(['password' => 'Secret-pass-123'])->save();
        $this->app['auth']->forgetGuards();
        $t = $this->postJson($this->api('auth/token'), ['email' => $user->email, 'password' => 'Secret-pass-123', 'device_name' => 'Phone'])->json('token');
        $this->withToken($t)->getJson($this->api('hr/me'))->assertOk()->assertJsonPath('employee.name', $staff->name)->assertJsonPath('leave.0.code', 'CL');
        $this->withToken($t)->getJson($this->api('hr/me/payslips'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.net', $mine->net);
        $this->withToken($t)->getJson($this->api("hr/me/payslips/{$theirs->id}"))->assertForbidden();
        $this->withToken($t)->postJson($this->api('hr/me/leave'), ['leave_type_id' => $this->leaveType('SL')->id, 'from_date' => '2026-12-01', 'reason' => 'Fever'])->assertCreated()->assertJsonPath('data.days', 1);
        $this->withToken($t)->getJson($this->api('hr/leave/pending'))->assertForbidden();
    }

    public function test_hr_screens_render(): void
    {
        [$user, $staff] = $this->staffUser();
        $run = $this->process();
        $slip = $this->slip($run, $staff);
        $this->in(fn () => app(\Modules\Hr\Services\EmployeeService::class)->relieve($staff, ['relieved_on' => '2026-11-30']));
        $f = $this->in(fn () => app(FnfService::class)->prepare($staff));

        foreach (['hr' => 'HR and payroll', 'hr/employees' => $staff->name, 'hr/employees/create' => 'Add employee', "hr/employees/{$staff->id}" => 'Revise salary',
            'hr/attendance' => 'Staff attendance', 'hr/attendance/month' => 'Loss of pay', 'hr/leave' => 'Leave', 'hr/payroll' => 'November 2026',
            "hr/payroll/{$run->id}" => 'Approve and post', "hr/payroll/{$run->id}/reports/pf" => 'ECR', "hr/payroll/{$run->id}/reports/tds" => 'TDS', "hr/payslips/{$slip->id}" => 'Draft: not yet approved',
            'hr/fnf' => 'Full and final', "hr/fnf/{$f->id}" => 'Gratuity', 'hr/settings' => 'Statutory rules'] as $path => $text) {
            $this->get($this->at($path))->assertOk()->assertSee($text, false);
        }
        $this->post($this->at('hr/employees'), ['employee_code' => 'E900', 'name' => 'Lata Menon', 'pan' => 'bad'])->assertSessionHasErrors('pan');
        $this->post($this->at('hr/employees'), ['employee_code' => 'E900', 'name' => 'Lata Menon', 'pan' => 'ABCDE1234F', 'bank_account' => '123456789012', 'bank_ifsc' => 'SBIN0001234'])->assertRedirect();
        $p = $this->in(fn () => EmployeeProfile::whereHas('staff', fn ($q) => $q->where('employee_code', 'E900'))->first());
        $this->assertSame(['ABCDE1234F', 'XXXXXXXX9012'], [$p->pan, $p->maskedAccount()]);
        $this->assertNotSame('123456789012', $this->in(fn () => \Illuminate\Support\Facades\DB::table('employee_profiles')->where('id', $p->id)->value('bank_account')), 'Stored encrypted');
    }
}
