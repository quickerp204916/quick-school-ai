<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Services\SetupService;
use Modules\Academic\Support\Institution;
use Modules\Identity\Models\TenantMembership;
use Modules\Institution\Models\Branch;

class StaffController extends Controller
{
    public function __construct(private readonly SetupService $setup)
    {
    }

    public function index(Request $request): View
    {
        $staff = StaffMember::with(['branch', 'user'])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->whereLike('name', '%'.$request->q.'%')->orWhereLike('employee_code', '%'.$request->q.'%')))
            ->when($request->input('status', 'active') !== 'all', fn ($q) => $q->where('status', $request->input('status', 'active')))
            ->orderBy('name')->get();
        $roles = TenantMembership::whereIn('user_id', $staff->pluck('user_id')->filter())->pluck('role', 'user_id');

        return view('academic::staff.index', ['staff' => $staff, 'roles' => $roles, 'branches' => Branch::orderBy('name')->get(),
            'limit' => Institution::features()->limit('staff_max')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $s = $this->setup->saveStaff($request->all());

        return $this->done("{$s->name} added.".($s->user_id ? ' An invitation to set a password was emailed.' : ''));
    }

    public function edit(StaffMember $staff): View
    {
        return view('academic::staff.edit', ['s' => $staff, 'branches' => Branch::orderBy('name')->get(),
            'role' => $staff->user_id ? TenantMembership::where('user_id', $staff->user_id)->value('role') : null,
            'classes' => Section::with('level', 'session')->where('class_teacher_id', $staff->id)->get()]);
    }

    public function update(Request $request, StaffMember $staff): RedirectResponse
    {
        $this->setup->saveStaff($request->all(), $staff);

        return $this->done('Staff record saved.', route('app.staff.index'));
    }
}
