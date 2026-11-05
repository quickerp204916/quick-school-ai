<?php

namespace Modules\Hr\Services;

use Illuminate\Support\Collection;
use Modules\Hr\Models\EmployeeProfile;
use Modules\Hr\Models\PayrollRun;

/** Payroll register and statutory returns (PF ECR, ESI, professional tax, TDS) for one month. */
final class PayrollReports
{
    public function register(PayrollRun $run): Collection
    {
        return $run->payslips()->with('staff')->get()->sortBy(fn ($p) => $p->staff->name)->values();
    }

    /** One row per employee in the EPFO ECR layout (UAN, name, gross, EPF/EPS/EDLI wages, contributions), amounts in rupees. */
    public function pf(PayrollRun $run): Collection
    {
        $profiles = EmployeeProfile::pluck('uan', 'staff_member_id');

        return $this->register($run)->filter(fn ($p) => isset($p->deductions['PF']))->map(fn ($p) => [
            'uan' => $profiles[$p->staff_member_id] ?? '', 'name' => $p->staff->name, 'gross' => intdiv($p->gross, 100),
            'epf_wages' => intdiv($p->snapshot['pf']['capped_wage'] ?? 0, 100), 'eps_wages' => intdiv(min($p->snapshot['pf']['wage'] ?? 0, $p->snapshot['pf']['wage_ceiling'] ?? 0), 100),
            'edli_wages' => intdiv(min($p->snapshot['pf']['wage'] ?? 0, $p->snapshot['pf']['wage_ceiling'] ?? 0), 100),
            'ee' => intdiv($p->deductions['PF'], 100), 'eps' => intdiv($p->employer['EPS'] ?? 0, 100), 'er_epf' => intdiv($p->employer['EPF'] ?? 0, 100),
            'ncp_days' => (int) round($p->lop_days),
        ])->values();
    }

    public function esi(PayrollRun $run): Collection
    {
        $profiles = EmployeeProfile::pluck('esi_number', 'staff_member_id');

        return $this->register($run)->filter(fn ($p) => isset($p->deductions['ESI']))->map(fn ($p) => ['ip' => $profiles[$p->staff_member_id] ?? '', 'name' => $p->staff->name,
            'days' => $p->paid_days, 'wages' => $p->snapshot['esi']['wage'] ?? 0, 'ee' => $p->deductions['ESI'], 'er' => $p->employer['ESI'] ?? 0])->values();
    }

    public function pt(PayrollRun $run): Collection
    {
        return $this->register($run)->filter(fn ($p) => isset($p->deductions['PT']))->groupBy(fn ($p) => $p->snapshot['pt']['name'] ?? '—')
            ->map(fn ($g, $state) => ['state' => $state, 'employees' => $g->count(), 'amount' => (int) $g->sum(fn ($p) => $p->deductions['PT'])])->values();
    }

    public function tds(PayrollRun $run): Collection
    {
        $profiles = EmployeeProfile::get()->keyBy('staff_member_id');

        return $this->register($run)->map(fn ($p) => ['name' => $p->staff->name, 'pan' => $profiles[$p->staff_member_id]?->maskedPan(), 'regime' => $p->snapshot['regime'] ?? 'new',
            'projected' => $p->snapshot['tds']['projected_income'] ?? 0, 'annual_tax' => $p->snapshot['tds']['annual_tax'] ?? 0, 'tds' => $p->deductions['TDS'] ?? 0])->values();
    }
}
