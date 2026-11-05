<?php

namespace Modules\Fees\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\Academic\Models\Student;
use Modules\Fees\Models\FeeConcession;
use Modules\Fees\Models\FeeInvoice;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Models\FeeStructure;
use Modules\Fees\Models\StudentFeeConcession;
use Modules\Fees\Models\StudentFeePlan;
use Modules\Fees\Services\CollectionService;
use Modules\Fees\Services\FeePlanService;

/** A student's fees: plan, invoices, receipts, and the collection counter. */
class StudentFeeController extends \Modules\Academic\Http\Controllers\Controller
{
    public function __construct(private readonly FeePlanService $plans, private readonly CollectionService $collections)
    {
    }

    public function index(Request $r): View
    {
        $q = Student::with(['currentEnrollment.section.level', 'guardians'])->where('status', 'active')
            ->when($r->filled('q'), function ($q) use ($r) {
                $t = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($r->q)).'%';
                $q->where(fn ($w) => $w->whereLike('first_name', $t)->orWhereLike('last_name', $t)->orWhereLike('admission_no', $t)
                    ->orWhereHas('guardians', fn ($g) => $g->whereLike('phone', $t)));
            })
            ->when($r->input('show') === 'due', fn ($q) => $q->whereIn('id', FeeInvoice::select('student_id')->whereIn('status', ['open', 'partial'])))
            ->when($r->input('show') === 'no_plan', fn ($q) => $q->whereNotIn('id', StudentFeePlan::select('student_id')->where('status', 'active')->where('session_id', $this->session()->id)))
            ->orderBy('first_name')->paginate(40)->withQueryString();
        $balances = FeeInvoice::whereIn('student_id', $q->getCollection()->pluck('id'))->whereIn('status', ['open', 'partial'])->get()
            ->groupBy('student_id')->map(fn ($l) => $l->sum(fn ($i) => $i->balance()));

        return view('fees::students.index', ['list' => $q, 'balances' => $balances]);
    }

    public function show(Student $student): View
    {
        $this->member()->mustSeeStudent($student);
        $session = $this->session();
        $plan = StudentFeePlan::with(['structure', 'concessions.concession'])->where('student_id', $student->id)->where('session_id', $session->id)->where('status', 'active')->first();

        return view('fees::students.show', [
            'student' => $student->load(['currentEnrollment.section.level', 'guardians']), 'plan' => $plan, 'session' => $session,
            'invoices' => FeeInvoice::where('student_id', $student->id)->orderBy('due_date')->orderBy('instalment')->get(),
            'payments' => FeePayment::where('student_id', $student->id)->orderByDesc('date')->orderByDesc('number')->get(),
            'structures' => FeeStructure::where('session_id', $session->id)->where('is_active', true)
                ->orderByRaw('CASE WHEN grade_level_id = ? THEN 0 ELSE 1 END', [$student->currentEnrollment?->section?->grade_level_id ?? ''])->orderBy('name')->get(),
            'concessions' => FeeConcession::where('is_active', true)->orderBy('name')->get(),
            'optionalHeads' => FeePlanService::heads()->where('kind', 'optional'),
            'token' => (string) Str::uuid(),
        ]);
    }

    public function assign(Request $r, Student $student): RedirectResponse
    {
        $plan = $this->plans->assign($student, $r->all());
        $pending = $plan->concessions->where('status', 'pending')->count();

        return $this->done('Fee plan assigned: '.$plan->invoices->count().' instalments.'.($pending ? " {$pending} concession(s) wait for approval." : ''));
    }

    public function collect(Request $r, Student $student): RedirectResponse
    {
        $p = $this->collections->collect($student, $r->only(['amount', 'date', 'mode', 'reference', 'invoice_ids', 'idempotency_key', 'remarks']));

        return $this->done("Receipt {$p->number} saved.", route('app.fees.payments.show', $p));
    }

    public function cancelPlan(Request $r, StudentFeePlan $feePlan): RedirectResponse
    {
        $r->validate(['reason' => ['required', 'min:5', 'max:200']]);
        $this->plans->cancelPlan($feePlan, $r->reason);

        return $this->done('Fee plan cancelled. Paid invoices are kept.');
    }

    public function decideConcession(Request $r, StudentFeeConcession $planConcession): RedirectResponse
    {
        $this->plans->approveConcession($planConcession, $r->input('decision') === 'approve', $r->input('note'));

        return $this->done($r->input('decision') === 'approve' ? 'Concession approved and applied to the open instalments.' : 'Concession rejected.');
    }
}
