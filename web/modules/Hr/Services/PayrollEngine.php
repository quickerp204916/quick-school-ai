<?php

namespace Modules\Hr\Services;

use Carbon\CarbonImmutable;
use Modules\Academic\Models\StaffMember;
use Modules\Hr\HrException;
use Modules\Hr\Models\EmployeeProfile;
use Modules\Hr\Models\PayrollInput;
use Modules\Hr\Models\Payslip;
use Modules\Hr\Models\SalaryComponent;
use Modules\Hr\Models\SalaryStructure;
use Modules\Hr\Models\StatutoryRule;

/**
 * Works out one employee's pay for a month from their salary structure, attendance, one-off inputs and the
 * statutory rules in force on the last day of the month. Pure calculation: nothing is saved here.
 *
 * Every amount is rounded to the rupee, as payslips are. PF, ESI, PT and TDS follow the rule versions in
 * statutory_rules; the values used are returned in the snapshot so an approved payslip can always be explained.
 */
final class PayrollEngine
{
    public function __construct(private readonly AttendanceService $attendance)
    {
    }

    public static function rupee(float|int $paise): int
    {
        return (int) (round($paise / 100) * 100);
    }

    private static function ceilRupee(float|int $paise): int
    {
        return (int) (ceil(round($paise, 6) / 100) * 100);
    }

    private function rule(string $code, string $date): array
    {
        return StatutoryRule::inForce($code, $date)?->config ?? throw new HrException("There is no {$code} rule in force on {$date}. Add one under Payroll settings.");
    }

    /** @return array{earnings: array, deductions: array, employer: array, gross: int, total_deductions: int, net: int, snapshot: array, ...} */
    public function compute(StaffMember $staff, string $period): array
    {
        $end = CarbonImmutable::parse($period.'-01')->endOfMonth()->format('Y-m-d');
        $structure = SalaryStructure::inForce($staff->id, $end) ?? throw new HrException("{$staff->name} has no salary structure for {$period}.");
        $profile = EmployeeProfile::where('staff_member_id', $staff->id)->first() ?? new EmployeeProfile(['pf_applicable' => true, 'esi_applicable' => true, 'tax_regime' => 'new']);
        $att = $this->attendance->summary($staff, $period);
        $paid = max(0.0, $att['payable_days'] - $att['lop_days']);
        $ratio = $att['days_in_month'] ? $paid / $att['days_in_month'] : 0;
        $components = SalaryComponent::where('is_active', true)->get()->keyBy('code');

        $earn = [];
        foreach ($structure->components as $code => $monthly) {
            $earn[$code] = self::rupee($monthly * $ratio);
        }
        $basicDa = ($structure->components['BASIC'] ?? 0) + ($structure->components['DA'] ?? 0);
        $ot = $this->rule('overtime', $end);
        if ($att['overtime_minutes'] > 0) {
            $hourly = $basicDa / ($ot['divisor_days'] * $ot['hours_per_day']);
            $earn['OT'] = self::rupee($att['overtime_minutes'] / 60 * $hourly * $ot['multiplier_pct'] / 100);
        }
        $deduct = [];
        foreach (PayrollInput::where('staff_member_id', $staff->id)->where('period', $period)->get() as $in) {
            $kind = $components[$in->component]->kind ?? null;
            if ($kind === 'earning') {
                $earn[$in->component] = ($earn[$in->component] ?? 0) + $in->amount;
            } elseif ($kind === 'deduction') {
                $deduct[$in->component] = ($deduct[$in->component] ?? 0) + $in->amount;
            }
        }
        $earn = array_filter($earn);
        $gross = array_sum($earn);
        $sum = fn (string $flag) => array_sum(array_map(fn ($c, $a) => ($components[$c]->{$flag} ?? false) ? $a : 0, array_keys($earn), $earn));
        $employer = [];
        $snap = ['structure' => $structure->components, 'structure_from' => $structure->effective_from->format('Y-m-d'), 'attendance' => $att, 'regime' => $profile->tax_regime];

        // Provident fund
        if ($profile->pf_applicable) {
            $pf = $this->rule('pf', $end);
            $wage = $sum('pf_wage');
            $capped = $pf['restrict_to_ceiling'] ? min($wage, $pf['wage_ceiling']) : $wage;
            $deduct['PF'] = self::rupee($capped * $pf['employee_bp'] / 10000);
            $er = self::rupee($capped * $pf['employer_bp'] / 10000);
            $employer['EPS'] = min($er, self::rupee(min($wage, $pf['wage_ceiling']) * $pf['eps_bp'] / 10000));
            $employer['EPF'] = $er - $employer['EPS'];
            $employer['PF'] = $er;
            $snap['pf'] = $pf + ['wage' => $wage, 'capped_wage' => $capped];
        }
        // ESI: only while the contracted gross is within the ceiling.
        $esi = $this->rule('esi', $end);
        if ($profile->esi_applicable && $structure->monthly_gross <= $esi['gross_ceiling']) {
            $wage = $sum('esi_wage');
            $deduct['ESI'] = self::ceilRupee($wage * $esi['employee_bp'] / 10000);
            $employer['ESI'] = self::ceilRupee($wage * $esi['employer_bp'] / 10000);
            $snap['esi'] = $esi + ['wage' => $wage];
        }
        // Professional tax by the state the employee works in.
        $state = $profile->pt_state ?: $staff->branch?->state_code;
        $pt = $this->rule('pt', $end)['states'][$state] ?? null;
        if ($pt && $gross > 0) {
            foreach ($pt['slabs'] as $slab) {
                if ($gross >= $slab[0] && ($slab[1] === null || $gross <= $slab[1])) {
                    $amount = (int) ($period === substr($end, 0, 4).'-02' && isset($slab['feb']) ? $slab['feb'] : $slab[2]);
                    if ($amount) {
                        $deduct['PT'] = $amount;
                    }
                    break;
                }
            }
            $snap['pt'] = ['state' => $state, 'name' => $pt['name']];
        }
        // Income tax: project the year, take off what was deducted in earlier months, spread the rest.
        $tds = $this->tds($staff, $profile, $period, $end, $earn, $components, $structure, $deduct['PF'] ?? 0);
        if ($tds['monthly'] > 0) {
            $deduct['TDS'] = $tds['monthly'];
        }
        $snap['tds'] = $tds;
        $deduct = array_filter($deduct);
        $total = array_sum($deduct);
        if ($total > $gross) {
            throw new HrException("Deductions for {$staff->name} ({$total}) are more than the gross pay. Reduce the recovery for this month.");
        }

        return ['structure_id' => $structure->id, 'days_in_month' => $att['days_in_month'], 'payable_days' => $att['payable_days'], 'lop_days' => $att['lop_days'],
            'paid_days' => $paid, 'overtime_minutes' => $att['overtime_minutes'], 'earnings' => $earn, 'deductions' => $deduct, 'employer' => $employer,
            'gross' => $gross, 'total_deductions' => $total, 'net' => $gross - $total, 'snapshot' => $snap];
    }

    private function tds(StaffMember $staff, EmployeeProfile $profile, string $period, string $end, array $earn, $components, SalaryStructure $structure, int $pfThisMonth): array
    {
        $cfg = $this->rule('tds', $end);
        $regime = $cfg[$profile->tax_regime === 'old' ? 'old' : 'new'];
        $m = (int) substr($period, 5, 2);
        $fyStart = ($m >= 4 ? substr($period, 0, 4) : (int) substr($period, 0, 4) - 1).'-04';
        $remaining = 12 - (($m + 8) % 12);           // months left in the financial year, this one included
        $earlier = Payslip::where('staff_member_id', $staff->id)->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid'])
            ->where('period', '>=', $fyStart)->where('period', '<', $period))->get();
        $taxableOf = fn (array $e) => array_sum(array_map(fn ($c, $a) => ($components[$c]->taxable ?? true) ? $a : 0, array_keys($e), $e));
        $paidSoFar = $earlier->sum(fn ($p) => $taxableOf($p->earnings));
        $tdsSoFar = (int) $earlier->sum(fn ($p) => $p->deductions['TDS'] ?? 0);
        $recurring = $taxableOf($structure->components);
        $annual = ($paidSoFar + $taxableOf($earn) + $recurring * ($remaining - 1)) / 100;   // rupees
        $income = $annual - $regime['standard_deduction'];
        if ($profile->tax_regime === 'old') {
            $pfYear = ($earlier->sum(fn ($p) => $p->deductions['PF'] ?? 0) + $pfThisMonth * $remaining) / 100;
            $income -= min($regime['max_80c'], $pfYear + $profile->declared_80c / 100);
        }
        $income = max(0, $income);
        $tax = 0.0;
        $lower = 0;
        foreach ($regime['slabs'] as [$upto, $bp]) {
            $top = $upto ?? PHP_INT_MAX;
            if ($income > $lower) {
                $tax += (min($income, $top) - $lower) * $bp / 10000;
            }
            $lower = $top;
        }
        if ($income <= $regime['rebate_limit']) {
            $tax = max(0, $tax - $regime['rebate_max']);
        }
        $tax = round($tax * (1 + $cfg['cess_bp'] / 10000));
        $monthly = max(0, self::rupee(($tax * 100 - $tdsSoFar) / $remaining));

        return ['projected_income' => (int) round($annual), 'taxable_income' => (int) round($income), 'annual_tax' => (int) $tax, 'deducted_so_far' => $tdsSoFar,
            'months_left' => $remaining, 'monthly' => $monthly];
    }
}
