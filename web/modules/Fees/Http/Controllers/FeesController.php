<?php

namespace Modules\Fees\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Modules\Fees\Models\FeeInvoice;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Models\FeeRefund;
use Modules\Fees\Models\StudentFeeConcession;
use Modules\Fees\Services\CollectionService;
use Modules\Fees\Services\FeePlanService;

class FeesController extends \Modules\Academic\Http\Controllers\Controller
{
    public function dashboard(): View
    {
        $today = today()->format('Y-m-d');
        $open = FeeInvoice::whereIn('status', ['open', 'partial'])->get();
        $month = FeePayment::where('status', 'success')->where('date', '>=', today()->startOfMonth()->format('Y-m-d'));

        return view('fees::dashboard', [
            'due' => $open->sum(fn ($i) => $i->balance()),
            'overdue' => $open->filter(fn ($i) => $i->isOverdue($today))->sum(fn ($i) => $i->balance()),
            'overdueStudents' => $open->filter(fn ($i) => $i->isOverdue($today))->pluck('student_id')->unique()->count(),
            'today' => (int) FeePayment::where('status', 'success')->where('date', $today)->sum('amount'),
            'month' => (int) (clone $month)->sum('amount'),
            'byMode' => (clone $month)->get()->groupBy('mode')->map->sum('amount'),
            'recent' => FeePayment::with('student')->latest()->limit(8)->get(),
            'pending' => StudentFeeConcession::where('status', 'pending')->count() + FeeRefund::whereIn('status', ['requested', 'approved', 'failed'])->count(),
        ]);
    }

    /** Runs today's fee jobs now (the scheduler also runs them every morning). */
    public function run(FeePlanService $plans, CollectionService $collections): RedirectResponse
    {
        $issued = $plans->issueDue();
        $late = $plans->applyLateFees();
        $collections->applyAdvances();

        return $this->done("Issued {$issued} invoice(s) and updated late fees on {$late}.");
    }
}
