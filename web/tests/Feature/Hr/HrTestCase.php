<?php

namespace Tests\Feature\Hr;

use Modules\Academic\Models\StaffMember;
use Modules\Hr\Models\Department;
use Modules\Hr\Models\PayrollRun;
use Modules\Hr\Models\Payslip;
use Modules\Hr\Services\EmployeeService;
use Modules\Hr\Services\PayrollService;
use Tests\Feature\Accounting\AccountingTestCase;

/** An institution with books and HR set up. Today is Wed 4 Nov 2026; November has 30 days and starts on a Sunday. */
abstract class HrTestCase extends AccountingTestCase
{
    protected function employee(string $code, array $salary, array $extra = []): StaffMember
    {
        return $this->in(function () use ($code, $salary, $extra) {
            $s = app(EmployeeService::class)->save($extra + ['employee_code' => $code, 'name' => 'Employee '.$code, 'joined_on' => '2025-06-01', 'pt_state' => '27',
                'department_id' => Department::where('code', 'ACAD')->value('id'), 'bank_account' => '50100012345678', 'bank_ifsc' => 'HDFC0001234', 'pan' => 'ABCDE1234F', 'uan' => '100200300400']);
            app(EmployeeService::class)->revise($s, ['effective_from' => $extra['salary_from'] ?? '2025-06-01', 'components' => $salary]);

            return $s;
        });
    }

    protected function payroll(): PayrollService
    {
        return app(PayrollService::class);
    }

    protected function process(string $period = '2026-11'): PayrollRun
    {
        return $this->in(fn () => $this->payroll()->process($period));
    }

    protected function slip(PayrollRun $run, StaffMember $s): Payslip
    {
        return $this->in(fn () => Payslip::where('run_id', $run->id)->where('staff_member_id', $s->id)->firstOrFail());
    }

    protected function asRole(string $role)
    {
        $u = $this->member($role);
        $this->actingAs($u, 'web');

        return $u;
    }
}
