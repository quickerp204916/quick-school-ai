<?php

namespace Modules\Fees\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Modules\Academic\Models\Student;
use Modules\Academic\Support\CurrentMember;
use Modules\Fees\Models\FeeInvoice;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Models\FeePaymentOrder;
use Modules\Fees\Models\FeeSetting;
use Modules\Fees\Services\CollectionService;
use Modules\Fees\Services\FeePlanService;
use Modules\Fees\Services\FeeReports;
use Modules\Fees\Services\OnlinePayments;

/**
 * Fees API. Parents: their children's dues and receipts, and online payment. Staff: counter collection (send an
 * Idempotency-Key header) and the outstanding list. Amounts are paise, with display strings alongside.
 */
class FeesApiController
{
    public function __construct(private readonly CurrentMember $member)
    {
    }

    public static function presentInvoice(FeeInvoice $i): array
    {
        return ['id' => $i->id, 'number' => $i->number, 'period' => $i->period, 'issue_date' => $i->issue_date->format('Y-m-d'), 'due_date' => $i->due_date->format('Y-m-d'),
            'status' => $i->status, 'overdue' => $i->isOverdue(), 'gross' => $i->gross, 'concession' => $i->concession, 'late_fee' => $i->late_fee, 'waived' => $i->waived,
            'total' => $i->total(), 'paid' => $i->paid, 'balance' => $i->balance(), 'balance_display' => \Modules\Accounting\Support\Amount::format($i->balance()),
            'lines' => $i->relationLoaded('lines') ? $i->lines->map(fn ($l) => ['description' => $l->description, 'amount' => $l->amount, 'concession' => $l->concession])->all() : null];
    }

    public static function presentPayment(FeePayment $p): array
    {
        return ['id' => $p->id, 'number' => $p->number, 'date' => $p->date->format('Y-m-d'), 'amount' => $p->amount, 'amount_display' => \Modules\Accounting\Support\Amount::format($p->amount),
            'mode' => $p->mode, 'reference' => $p->reference, 'status' => $p->status, 'advance' => $p->advance, 'refunded' => $p->refunded,
            'allocations' => $p->allocations->map(fn ($a) => ['invoice' => $a->invoice?->number, 'period' => $a->invoice?->period, 'amount' => $a->amount])->all(),
            'receipt_url' => URL::temporarySignedRoute('app.fees.receipt', now()->addDays(7), ['feePayment' => $p->id])];
    }

    public function student(Student $student): JsonResponse
    {
        $this->member->mustSeeStudent($student);
        $invoices = FeeInvoice::with('lines')->where('student_id', $student->id)->whereNot('status', 'draft')->whereNot('status', 'cancelled')->orderBy('due_date')->get();
        $s = FeeSetting::current();

        return response()->json(['student' => ['id' => $student->id, 'name' => $student->fullName(), 'admission_no' => $student->admission_no],
            'balance' => $invoices->sum(fn ($i) => $i->balance()), 'invoices' => $invoices->map(fn ($i) => self::presentInvoice($i))->values(),
            'upcoming' => FeeInvoice::where('student_id', $student->id)->where('status', 'draft')->orderBy('due_date')->get()->map(fn ($i) => ['period' => $i->period, 'due_date' => $i->due_date->format('Y-m-d'), 'amount' => $i->gross - $i->concession])->values(),
            'payments' => FeePayment::with('allocations.invoice')->where('student_id', $student->id)->orderByDesc('date')->get()->map(fn ($p) => self::presentPayment($p))->values(),
            'gateways' => array_values(array_filter(['razorpay', 'cashfree'], fn ($g) => $s->enabled($g)))]);
    }

    public function collect(Request $request, Student $student, CollectionService $collections): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        $existing = $key ? FeePayment::where('idempotency_key', 'api:'.$key)->first() : null;
        $p = $collections->collect($student, ['idempotency_key' => $key ? 'api:'.$key : null] + $request->only(['amount', 'date', 'mode', 'reference', 'invoice_ids', 'remarks']));

        return response()->json(['data' => self::presentPayment($p->load('allocations.invoice'))], $existing ? 200 : 201)->header('Idempotent-Replayed', $existing ? 'true' : 'false');
    }

    public function createOrder(Request $request, OnlinePayments $online): JsonResponse
    {
        $student = Student::findOrFail($request->input('student_id'));

        return response()->json(['data' => $online->createOrder($student, array_filter((array) $request->input('invoice_ids', [])), (string) $request->input('gateway', 'razorpay'))], 201);
    }

    /** Called by the app after checkout. Records the payment only if the gateway confirms it. */
    public function confirm(Request $request, FeePaymentOrder $feeOrder, OnlinePayments $online): JsonResponse
    {
        $p = $online->confirm($feeOrder, $request->all());

        return $p ? response()->json(['status' => 'paid', 'data' => self::presentPayment($p->load('allocations.invoice'))])
            : response()->json(['status' => 'pending', 'message' => 'The gateway has not confirmed this payment yet. It is recorded automatically when it does.'], 202);
    }

    public function payment(FeePayment $feePayment): JsonResponse
    {
        $this->member->mustSeeStudent($feePayment->student);

        return response()->json(['data' => self::presentPayment($feePayment->load('allocations.invoice'))]);
    }

    public function outstanding(Request $request, FeeReports $reports): JsonResponse
    {
        $this->member->authorize('fees.view');

        return response()->json(['data' => $reports->outstanding($request->input('as_on', today()->format('Y-m-d')), $request->input('section'))
            ->map(fn ($r) => ['student_id' => $r['student']->id, 'name' => $r['student']->fullName(), 'admission_no' => $r['student']->admission_no,
                'class' => $r['student']->currentEnrollment?->section?->label(), 'total' => $r['total']] + array_intersect_key($r, FeeReports::BUCKETS))->values()]);
    }
}
