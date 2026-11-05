<?php

namespace Modules\Hr\Services;

use Modules\Accounting\Models\Ledger;
use Modules\Hr\Models\Department;
use Modules\Hr\Models\Designation;
use Modules\Hr\Models\LeaveType;
use Modules\Hr\Models\SalaryComponent;
use Modules\Hr\Models\Shift;
use Modules\Hr\Models\StatutoryRule;

/** Departments, designations, a default shift, leave types, salary components and the statutory rules, for a new institution. */
final class HrSetup
{
    public function seed(): void
    {
        if (SalaryComponent::exists()) {
            return;
        }
        foreach (config('hr.departments') as [$code, $name]) {
            Department::create(['code' => $code, 'name' => $name]);
        }
        foreach (config('hr.designations') as $name) {
            Designation::create(['name' => $name]);
        }
        Shift::create(config('hr.shift'));
        foreach (config('hr.leave_types') as [$code, $name, $days, $paid, $carry, $encash]) {
            LeaveType::create(['code' => $code, 'name' => $name, 'days_per_year' => $days, 'is_paid' => $paid, 'carry_forward_max' => $carry, 'encashable' => $encash]);
        }
        foreach (config('hr.components') as $i => [$code, $name, $kind, $taxable, $pf, $esi, $ledger]) {
            SalaryComponent::create(['code' => $code, 'name' => $name, 'kind' => $kind, 'taxable' => $taxable, 'pf_wage' => $pf, 'esi_wage' => $esi,
                'is_computed' => in_array($code, config('hr.computed'), true), 'sort_order' => $i,
                'ledger_id' => (Ledger::where('system_key', $ledger)->first() ?? Ledger::where('code', 'MISC-INCOME')->firstOrFail())->id]);
        }
        foreach (config('hr.statutory') as $code => [$from, $config]) {
            StatutoryRule::create(['code' => $code, 'effective_from' => $from, 'config' => $config, 'note' => 'Default seeded value. Confirm with your payroll consultant.']);
        }
    }
}
