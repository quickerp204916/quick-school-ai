<?php

namespace Modules\Hr\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\StaffMember;
use Modules\Hr\Models\Attendance;
use Modules\Hr\Models\Department;
use Modules\Hr\Models\Designation;
use Modules\Hr\Models\EmployeeDocument;
use Modules\Hr\Models\EmployeeProfile;
use Modules\Hr\Models\FnfSettlement;
use Modules\Hr\Models\LeaveRequest;
use Modules\Hr\Models\PayrollInput;
use Modules\Hr\Models\PayrollRun;
use Modules\Hr\Models\Payslip;
use Modules\Hr\Models\SalaryComponent;
use Modules\Hr\Models\SalaryStructure;
use Modules\Hr\Models\Shift;
use Modules\Hr\Services\EmployeeService;
use Modules\Hr\Services\LeaveService;
use Modules\Institution\Models\Branch;

/** HR home and the employee master. */
class HrController extends \Modules\Academic\Http\Controllers\Controller
{
    public function __construct(private readonly EmployeeService $employees)
    {
    }

    public function dashboard(): View
    {
        $today = today()->format('Y-m-d');

        return view('hr::dashboard', [
            'headcount' => StaffMember::where('status', 'active')->count(),
            'onLeave' => Attendance::where('date', $today)->where('status', 'leave')->count(),
            'absent' => Attendance::where('date', $today)->where('status', 'absent')->count(),
            'pendingLeave' => LeaveRequest::with(['staff', 'type'])->where('status', 'pending')->orderBy('from_date')->get(),
            'lastRun' => PayrollRun::orderByDesc('period')->first(),
            'noSalary' => StaffMember::where('status', 'active')->whereNotIn('id', SalaryStructure::select('staff_member_id'))->count(),
            'expiring' => EmployeeDocument::with('staff')->whereNotNull('expires_on')->where('expires_on', '<=', today()->addDays(45)->format('Y-m-d'))->orderBy('expires_on')->get(),
            'byDept' => EmployeeProfile::with('department')->get()->groupBy(fn ($p) => $p->department?->name ?? 'No department')->map->count(),
        ]);
    }

    public function index(Request $r): View
    {
        $list = StaffMember::with(['profile.department', 'profile.designation'])->when($r->input('status', 'active') !== 'all', fn ($q) => $q->where('status', $r->input('status', 'active')))
            ->when($r->filled('q'), fn ($q) => $q->where(fn ($w) => $w->whereLike('name', '%'.$r->q.'%')->orWhereLike('employee_code', '%'.$r->q.'%')))->orderBy('name')->paginate(50)->withQueryString();
        $gross = SalaryStructure::whereIn('staff_member_id', $list->getCollection()->pluck('id'))->orderBy('effective_from')->get()->keyBy('staff_member_id')->map->monthly_gross;

        return view('hr::employees.index', ['list' => $list, 'gross' => $gross]);
    }

    private function form(StaffMember $s): array
    {
        return ['s' => $s, 'p' => $s->profile ?? new EmployeeProfile(['employment_type' => 'permanent', 'tax_regime' => 'new', 'pf_applicable' => true, 'esi_applicable' => true, 'notice_days' => 30]),
            'departments' => Department::orderBy('name')->get(), 'designations' => Designation::orderBy('name')->get(), 'shifts' => Shift::orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(), 'states' => collect(config('hr.statutory.pt.1.states'))->map->name];
    }

    public function create(): View
    {
        return view('hr::employees.create', $this->form(new StaffMember(['joined_on' => today(), 'is_teaching' => true])));
    }

    public function store(Request $r): RedirectResponse
    {
        $s = $this->employees->save($r->all());

        return $this->done("{$s->name} added. Now set their salary.", route('app.hr.employees.show', $s));
    }

    public function show(StaffMember $staff, LeaveService $leave): View
    {
        return view('hr::employees.show', $this->form($staff->load('profile')) + [
            'structures' => SalaryStructure::where('staff_member_id', $staff->id)->orderByDesc('effective_from')->get(),
            'components' => SalaryComponent::where('kind', 'earning')->where('is_computed', false)->where('code', '!=', 'BONUS')->orderBy('sort_order')->get(),
            'documents' => EmployeeDocument::where('staff_member_id', $staff->id)->latest()->get(),
            'balances' => $leave->balances($staff), 'payslips' => Payslip::with('run')->where('staff_member_id', $staff->id)->latest()->limit(12)->get(),
            'inputs' => PayrollInput::where('staff_member_id', $staff->id)->orderByDesc('period')->limit(10)->get(),
            'fnf' => FnfSettlement::where('staff_member_id', $staff->id)->first(),
        ]);
    }

    public function update(Request $r, StaffMember $staff): RedirectResponse
    {
        $this->employees->save($r->all() + ['employee_code' => $staff->employee_code, 'name' => $staff->name], $staff);

        return $this->done('Employee details saved.');
    }

    public function revise(Request $r, StaffMember $staff): RedirectResponse
    {
        $s = $this->employees->revise($staff, $r->all());

        return $this->done('Salary of '.\Modules\Accounting\Support\Amount::format($s->monthly_gross).' a month applies from '.$s->effective_from->format('j M Y').'.');
    }

    public function input(Request $r, StaffMember $staff, \Modules\Hr\Services\PayrollService $payroll): RedirectResponse
    {
        $payroll->addInput($staff, $r->all());

        return $this->done('Added to the payroll for '.date('F Y', strtotime($r->input('period').'-01')).'.');
    }

    public function relieve(Request $r, StaffMember $staff): RedirectResponse
    {
        $this->employees->relieve($staff, $r->all());

        return $this->done('Relieving recorded. Prepare the full and final settlement next.');
    }

    public function upload(Request $r, StaffMember $staff): RedirectResponse
    {
        $r->validate(['file' => ['required', 'file']]);
        $this->employees->upload($staff, (string) $r->input('type'), (string) $r->input('title'), $r->file('file'), $r->input('expires_on'));

        return $this->done('Document uploaded.');
    }

    public function document(EmployeeDocument $employeeDocument)
    {
        return $this->employees->download($employeeDocument);
    }

    public function deleteDocument(EmployeeDocument $employeeDocument): RedirectResponse
    {
        $this->employees->deleteDocument($employeeDocument);

        return $this->done('Document deleted.');
    }
}
