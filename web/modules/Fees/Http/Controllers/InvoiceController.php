<?php

namespace Modules\Fees\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\Fees\Models\FeeInvoice;
use Modules\Fees\Services\FeePlanService;

class InvoiceController extends \Modules\Academic\Http\Controllers\Controller
{
    public function __construct(private readonly FeePlanService $plans)
    {
    }

    public function index(Request $r): View
    {
        $status = $r->input('status', 'due');
        $list = FeeInvoice::with('student')
            ->when($status === 'due', fn ($q) => $q->whereIn('status', ['open', 'partial']))
            ->when($status === 'overdue', fn ($q) => $q->whereIn('status', ['open', 'partial'])->where('due_date', '<', today()->format('Y-m-d')))
            ->when(in_array($status, ['draft', 'paid', 'cancelled'], true), fn ($q) => $q->where('status', $status))
            ->when($r->filled('from'), fn ($q) => $q->where('due_date', '>=', $r->from))->when($r->filled('to'), fn ($q) => $q->where('due_date', '<=', $r->to))
            ->orderBy('due_date')->orderBy('number')->paginate(50)->withQueryString();

        return view('fees::invoices.index', ['list' => $list, 'status' => $status]);
    }

    public function show(FeeInvoice $feeInvoice): View
    {
        $this->member()->mustSeeStudent($feeInvoice->student);

        return view('fees::invoices.show', ['inv' => $feeInvoice->load(['lines', 'student', 'allocations.payment', 'plan.structure']), 'token' => (string) Str::uuid()]);
    }

    public function waive(Request $r, FeeInvoice $feeInvoice): RedirectResponse
    {
        $this->plans->waive($feeInvoice, $r->input('amount'), (string) $r->input('reason'), $r->input('token'));

        return $this->done('Waiver recorded and posted.');
    }

    public function cancel(Request $r, FeeInvoice $feeInvoice): RedirectResponse
    {
        $r->validate(['reason' => ['required', 'min:5', 'max:200']]);
        $this->plans->cancel($feeInvoice, $r->reason);

        return $this->done('Invoice cancelled.');
    }
}
