<?php

namespace Modules\Hr\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Support\Audit;
use Modules\Hr\HrException;
use Modules\Hr\Models\LeaveType;
use Modules\Hr\Models\SalaryComponent;
use Modules\Hr\Models\Shift;
use Modules\Hr\Models\StatutoryRule;
use Modules\Hr\Services\PayrollService;

/** Shifts, leave types, salary components and the effective-dated statutory rules. */
class HrSettingsController extends \Modules\Academic\Http\Controllers\Controller
{
    public function index(): View
    {
        return view('hr::settings.index', ['shifts' => Shift::orderBy('name')->get(), 'types' => LeaveType::orderBy('code')->get(),
            'components' => SalaryComponent::with('ledger')->orderBy('sort_order')->get(), 'rules' => StatutoryRule::orderBy('code')->orderByDesc('effective_from')->get()->groupBy('code')]);
    }

    /** A new version of a rule from a date. The JSON holds only the values that change. */
    public function addRule(Request $r, PayrollService $payroll): RedirectResponse
    {
        $config = json_decode((string) $r->input('config'), true);
        if (! is_array($config)) {
            throw new HrException('The changes must be valid JSON, for example {"employee_bp": 1000}.', 'config');
        }
        $rule = $payroll->addRule((string) $r->input('code'), (string) $r->input('effective_from'), $config, $r->input('note'));

        return $this->done(strtoupper($rule->code).' rule saved from '.$rule->effective_from->format('j M Y').'. Earlier payrolls keep the rule they used.');
    }

    public function updateShift(Request $r, Shift $shift): RedirectResponse
    {
        $d = $r->validate(['name' => ['required', 'max:60'], 'starts_at' => ['required', 'date_format:H:i'], 'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'break_minutes' => ['integer', 'min:0', 'max:240'], 'grace_minutes' => ['integer', 'min:0', 'max:120'], 'weekly_off' => ['array'], 'weekly_off.*' => ['integer', 'between:0,6']]);
        $shift->update($d + ['weekly_off' => array_map('intval', $d['weekly_off'] ?? [])]);
        Audit::record('hr.shift_updated', $shift);

        return $this->done('Shift saved.');
    }
}
