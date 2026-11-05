<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\Guardian;

class GuardianController extends Controller
{
    public function index(Request $request): View
    {
        $guardians = Guardian::with(['students.currentEnrollment.section.level'])->withCount('students')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->whereLike('name', '%'.$request->q.'%')
                ->orWhereLike('phone', '%'.$request->q.'%')->orWhereLike('email', '%'.$request->q.'%')))
            ->when($request->boolean('siblings'), fn ($q) => $q->has('students', '>', 1))
            ->orderBy('name')->paginate(40)->withQueryString();

        return view('academic::guardians.index', ['guardians' => $guardians]);
    }

    public function show(Guardian $guardian): View
    {
        return view('academic::guardians.show', ['g' => $guardian->load(['students.currentEnrollment.section.level'])]);
    }
}
