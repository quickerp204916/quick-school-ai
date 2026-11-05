<?php

namespace Modules\Fees\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\GradeLevel;
use Modules\Fees\Models\FeeStructure;
use Modules\Fees\Services\FeePlanService;
use Modules\Institution\Models\AcademicSession;

class StructureController extends \Modules\Academic\Http\Controllers\Controller
{
    public function __construct(private readonly FeePlanService $plans)
    {
    }

    public function index(): View
    {
        $session = $this->session();

        return view('fees::structures.index', ['session' => $session, 'sessions' => AcademicSession::orderByDesc('starts_on')->get(),
            'list' => FeeStructure::with(['items.head', 'gradeLevel'])->withCount('items')->where('session_id', $session->id)->orderBy('name')->get()]);
    }

    private function form(FeeStructure $s): View
    {
        return view('fees::structures.form', ['s' => $s, 'heads' => FeePlanService::heads(), 'levels' => GradeLevel::orderBy('sequence')->get(),
            'sessions' => AcademicSession::orderByDesc('starts_on')->get(),
            'preview' => $s->exists ? $this->plans->schedule($s->load('session'), $s->items->pluck('fee_head_id')->all()) : []]);
    }

    public function create(): View
    {
        return $this->form(new FeeStructure(['session_id' => AcademicSession::current()?->id, 'due_day' => 10, 'late_fee_type' => 'none']));
    }

    public function edit(FeeStructure $feeStructure): View
    {
        return $this->form($feeStructure->load('items.head'));
    }

    public function store(Request $r): RedirectResponse
    {
        $s = $this->plans->saveStructure($r->all());

        return $this->done("Fee structure {$s->name} saved.", route('app.fees.structures.edit', $s));
    }

    public function update(Request $r, FeeStructure $feeStructure): RedirectResponse
    {
        $this->plans->saveStructure($r->all(), $feeStructure);

        return $this->done('Fee structure saved. Plans assigned from now on use these amounts.');
    }
}
