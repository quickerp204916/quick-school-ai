<?php

namespace Modules\Fees\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Models\FeeRefund;
use Modules\Fees\Models\FeeSetting;
use Modules\Fees\Models\StudentFeeConcession;
use Modules\Fees\Services\CollectionService;

/** Receipts, bounced cheques and refunds. */
class PaymentController extends \Modules\Academic\Http\Controllers\Controller
{
    public function __construct(private readonly CollectionService $collections)
    {
    }

    public function index(Request $r): View
    {
        $from = $r->input('from', today()->startOfMonth()->format('Y-m-d'));
        $to = $r->input('to', today()->format('Y-m-d'));
        $list = FeePayment::with('student')->whereBetween('date', [$from, $to])->when($r->filled('mode'), fn ($q) => $q->where('mode', $r->mode))
            ->orderByDesc('date')->orderByDesc('number')->paginate(50)->withQueryString();

        return view('fees::payments.index', ['list' => $list, 'from' => $from, 'to' => $to]);
    }

    public function show(FeePayment $feePayment): View
    {
        $this->member()->mustSeeStudent($feePayment->student);

        return view('fees::payments.show', ['p' => $feePayment->load(['student.currentEnrollment.section.level', 'allocations.invoice', 'refunds.requester', 'receiver']),
            'note' => FeeSetting::current()->receipt_note, 'print' => false]);
    }

    /** The printable receipt behind a signed link (parent app, email). */
    public function receipt(FeePayment $feePayment): View
    {
        return view('fees::payments.receipt', ['p' => $feePayment->load(['student.currentEnrollment.section.level', 'allocations.invoice']),
            'note' => FeeSetting::current()->receipt_note]);
    }

    public function bounce(Request $r, FeePayment $feePayment): RedirectResponse
    {
        $this->collections->bounce($feePayment, (string) $r->input('reason'));

        return $this->done("Cheque on {$feePayment->number} marked as returned. The receipt is reversed and the dues are open again.");
    }

    public function requestRefund(Request $r, FeePayment $feePayment): RedirectResponse
    {
        $this->collections->requestRefund($feePayment, $r->only(['amount', 'reason', 'mode']));

        return $this->done('Refund requested. Another approver has to approve it before any money goes out.');
    }

    public function approvals(): View
    {
        return view('fees::payments.approvals', [
            'concessions' => StudentFeeConcession::with(['concession', 'plan.student'])->where('status', 'pending')->get(),
            'refunds' => FeeRefund::with(['payment.student', 'requester'])->whereIn('status', ['requested', 'approved', 'failed'])->latest()->get(),
            'done' => FeeRefund::with(['payment.student'])->whereIn('status', ['processed', 'rejected'])->latest()->limit(20)->get(),
        ]);
    }

    public function approveRefund(FeeRefund $feeRefund): RedirectResponse
    {
        $this->collections->approveRefund($feeRefund);

        return $this->done('Refund approved. Pay it out to finish.');
    }

    public function rejectRefund(Request $r, FeeRefund $feeRefund): RedirectResponse
    {
        $r->validate(['reason' => ['required', 'min:5', 'max:200']]);
        $this->collections->rejectRefund($feeRefund, $r->reason);

        return $this->done('Refund rejected.');
    }

    public function processRefund(Request $r, FeeRefund $feeRefund): RedirectResponse
    {
        $r2 = $this->collections->processRefund($feeRefund, $r->only('reference'));

        return $this->done($r2->status === 'processed' ? 'Refund paid and posted.' : 'Refund sent to the gateway. It is posted when the gateway confirms it.');
    }

    public function settlement(Request $r, \Modules\Fees\Services\OnlinePayments $online): \Illuminate\Http\RedirectResponse
    {
        $p = fn ($k) => \Modules\Fees\Support::paise($r->input($k));
        $v = $online->settle((string) $r->input('gateway'), (string) $r->input('settlement_id'), (string) ($r->input('date') ?: today()->format('Y-m-d')), $p('gross'), $p('fee'), $p('gst'));

        return back()->with('status', "Settlement posted as {$v->number}.");
    }
}
