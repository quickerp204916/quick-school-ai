<?php

namespace Modules\Fees\Services;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Models\Student;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Accounting\Models\Voucher;
use Modules\Accounting\Services\AccountingGateway;
use Modules\Accounting\Services\VoucherService;
use Modules\Accounting\Support\Amount;
use Modules\Fees\FeeException;
use Modules\Fees\Models\FeeInvoice;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Models\FeePaymentAllocation;
use Modules\Fees\Models\FeeRefund;
use Modules\Fees\Support;

/**
 * Collecting fees, allocating them to invoices, advances, bounced cheques and refunds.
 *
 * Every payment carries an idempotency key (the counter form's token, the API's Idempotency-Key header or the
 * gateway's payment id), so a double click, a retried request or a repeated webhook records it once.
 */
final class CollectionService
{
    /** How each payment mode reaches the books. */
    private const BOOK_MODE = ['cash' => 'cash', 'cheque' => 'bank', 'bank' => 'bank', 'upi' => 'bank', 'razorpay' => 'razorpay', 'cashfree' => 'cashfree'];

    public function __construct(private readonly CurrentMember $member, private readonly AccountingGateway $books, private readonly FeePlanService $plans)
    {
    }

    /**
     * Records a payment. $d: amount, date, mode, reference, invoice_ids (optional: pay these first), idempotency_key, remarks.
     * Oldest dues are paid first; anything left over is kept as an advance and used on the next invoice.
     * $system: called from a verified gateway confirmation, with no signed-in staff member.
     */
    public function collect(Student $student, array $d, bool $system = false): FeePayment
    {
        if (! $system) {
            $this->member->authorize('fees.collect');
        }
        $key = trim((string) ($d['idempotency_key'] ?? ''));
        if ($key === '' || mb_strlen($key) > 120) {
            throw new FeeException('This payment has no idempotency key. Reload the form and try again.', 'idempotency_key');
        }
        $amount = Support::paise($d['amount'] ?? null);
        $mode = (string) ($d['mode'] ?? '');
        if ($existing = FeePayment::where('idempotency_key', $key)->first()) {
            if ($existing->amount !== $amount || $existing->student_id !== $student->id) {
                throw new FeeException("This request was already recorded as receipt {$existing->number} for ".Amount::format($existing->amount).'. Nothing new was recorded.');
            }

            return $existing;
        }
        if ($amount <= 0) {
            throw new FeeException('Enter the amount received.', 'amount');
        }
        if (! array_key_exists($mode, FeePayment::MODES)) {
            throw new FeeException('Choose how the fee was paid.', 'mode');
        }
        if (! $system && in_array($mode, ['razorpay', 'cashfree'], true)) {
            throw new FeeException('Online payments are recorded automatically when the gateway confirms them.', 'mode');
        }
        $date = $d['date'] ?? today()->format('Y-m-d');
        if ($date > today()->format('Y-m-d')) {
            throw new FeeException('A payment cannot be dated in the future.', 'date');
        }
        $reference = trim((string) ($d['reference'] ?? '')) ?: null;
        if (in_array($mode, ['cheque', 'bank', 'upi'], true) && ! $reference) {
            throw new FeeException(['cheque' => 'Enter the cheque number and bank.', 'bank' => 'Enter the UTR or transaction reference.', 'upi' => 'Enter the UPI reference number.'][$mode], 'reference');
        }
        if ($student->status !== 'active' && ! $system && ! $this->member->can('fees.approve')) {
            throw new FeeException('This student is no longer active. Ask an administrator to record the payment.');
        }

        $this->plans->applyLateFees($student->id, $date);

        $payment = DB::transaction(function () use ($student, $d, $key, $amount, $mode, $date, $reference, $system) {
            $first = array_filter((array) ($d['invoice_ids'] ?? []));
            $open = FeeInvoice::where('student_id', $student->id)->whereIn('status', ['open', 'partial'])->lockForUpdate()->get()
                ->sortBy(fn ($i) => [in_array($i->id, $first, true) ? 0 : 1, $i->due_date->format('Y-m-d'), $i->instalment])->values();
            if ($first && $open->whereIn('id', $first)->count() !== count(array_unique($first))) {
                throw new FeeException('One of the chosen invoices is not open for this student.', 'invoice_ids');
            }
            $left = $amount;
            $alloc = [];
            foreach ($open as $inv) {
                if ($left === 0 || (! empty($d['only_chosen']) && ! in_array($inv->id, $first, true))) {
                    continue;
                }
                $take = min($left, $inv->balance());
                if ($take > 0) {
                    $alloc[] = [$inv, $take];
                    $left -= $take;
                }
            }
            $p = FeePayment::create(['number' => Support::number('RCT', $date), 'student_id' => $student->id, 'branch_id' => $student->branch_id,
                'date' => $date, 'amount' => $amount, 'advance' => $left, 'mode' => $mode, 'reference' => $reference,
                'gateway_payment_id' => $d['gateway_payment_id'] ?? null, 'order_id' => $d['order_id'] ?? null, 'status' => 'success',
                'idempotency_key' => $key, 'received_by' => $system ? null : $this->member->userId(), 'remarks' => $d['remarks'] ?? null]);
            foreach ($alloc as [$inv, $take]) {
                $p->allocations()->create(['invoice_id' => $inv->id, 'amount' => $take, 'via' => 'payment']);
            }
            $credits = array_filter(['fee_receivable' => $amount - $left, 'fee_advance' => $left]);
            $v = $this->books->feeCollection($p->id, $date, $amount, self::BOOK_MODE[$mode], $credits, $student->branch_id, $p->number);
            $p->forceFill(['voucher_id' => $v->id])->saveQuietly();
            foreach ($alloc as [$inv]) {
                $this->plans->refresh($inv);
            }
            Audit::record('fees.payment_received', $p, ['amount' => $amount, 'mode' => $mode, 'number' => $p->number]);

            return $p;
        });

        return $payment->load('allocations.invoice');
    }

    /** Uses advances (oldest first) against open invoices: Dr Fees received in advance, Cr Fees receivable. */
    public function applyAdvances(?string $studentId = null): int
    {
        $n = 0;
        $students = FeePayment::where('status', 'success')->where('advance', '>', 0)->when($studentId, fn ($q) => $q->where('student_id', $studentId))->distinct()->pluck('student_id');
        foreach ($students as $sid) {
            DB::transaction(function () use ($sid, &$n) {
                $payments = FeePayment::where('student_id', $sid)->where('status', 'success')->where('advance', '>', 0)->orderBy('date')->lockForUpdate()->get();
                $open = FeeInvoice::where('student_id', $sid)->whereIn('status', ['open', 'partial'])->orderBy('due_date')->orderBy('instalment')->lockForUpdate()->get();
                foreach ($open as $inv) {
                    foreach ($payments as $p) {
                        $take = min($p->advance, $inv->balance());
                        if ($take <= 0) {
                            continue;
                        }
                        $a = $p->allocations()->create(['invoice_id' => $inv->id, 'amount' => $take, 'via' => 'advance']);
                        $this->books->post('journal', 'fee_advance_use', $a->id, max($inv->issue_date->format('Y-m-d'), $p->date->format('Y-m-d')),
                            [['key' => 'fee_advance', 'debit' => $take], ['key' => 'fee_receivable', 'credit' => $take, 'bill_ref' => $inv->number]],
                            "Advance from {$p->number} used on {$inv->number}", $inv->branch_id, $p->number);
                        $p->update(['advance' => $p->advance - $take]);
                        $this->plans->refresh($inv);
                        $n++;
                    }
                }
            });
        }

        return $n;
    }

    /** A cheque that came back unpaid: the receipt is reversed in the books and the invoices are due again. */
    public function bounce(FeePayment $p, string $reason): FeePayment
    {
        $this->member->authorize('fees.approve');
        if ($p->mode !== 'cheque' || $p->status !== 'success') {
            throw new FeeException('Only a successful cheque payment can be marked as bounced.');
        }
        if ($p->allocations()->where('via', 'advance')->exists() || $p->refunds()->whereNot('status', 'rejected')->exists()) {
            throw new FeeException('Part of this cheque was already used as an advance or refunded. Reverse those first.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new FeeException('Give the bank\'s reason for the return.', 'reason');
        }

        return DB::transaction(function () use ($p, $reason) {
            app(VoucherService::class)->reverse(Voucher::findOrFail($p->voucher_id), "Cheque returned: {$reason}");
            $p->update(['status' => 'bounced']);
            foreach ($p->allocations()->with('invoice')->get() as $a) {
                $this->plans->refresh($a->invoice);
            }
            Audit::record('fees.cheque_bounced', $p, ['reason' => $reason]);

            return $p;
        });
    }

    /* ---------------- refunds (maker requests, a different person approves) ---------------- */

    public function requestRefund(FeePayment $p, array $d): FeeRefund
    {
        $this->member->authorize('fees.collect');
        $amount = Support::paise($d['amount'] ?? null);
        if ($amount <= 0 || $amount > $p->refundable()) {
            throw new FeeException('A refund must be more than zero and no more than '.Amount::format($p->refundable()).' still refundable on this receipt.', 'amount');
        }
        if (mb_strlen(trim((string) ($d['reason'] ?? ''))) < 5) {
            throw new FeeException('Give the reason for the refund.', 'reason');
        }
        $mode = in_array($p->mode, ['razorpay', 'cashfree'], true) ? $p->mode : (($d['mode'] ?? 'bank') === 'cash' ? 'cash' : 'bank');
        $r = FeeRefund::create(['payment_id' => $p->id, 'amount' => $amount, 'reason' => trim($d['reason']), 'mode' => $mode, 'status' => 'requested',
            'requested_by' => $this->member->userId()]);
        Audit::record('fees.refund_requested', $r, ['amount' => $amount, 'receipt' => $p->number]);

        return $r;
    }

    /**
     * Approval moves the money out of the student's dues into Fee refunds payable: the advance first, then the newest
     * invoices paid by this receipt become due again. The person who asked cannot approve.
     */
    public function approveRefund(FeeRefund $r): FeeRefund
    {
        $this->member->authorize('fees.approve');
        if ($r->status !== 'requested') {
            throw new FeeException('This refund has already been decided.');
        }
        if ($r->requested_by === $this->member->userId()) {
            throw new FeeException('You requested this refund, so another approver has to approve it.');
        }

        return DB::transaction(function () use ($r) {
            $p = FeePayment::lockForUpdate()->findOrFail($r->payment_id);
            if ($r->amount > $p->amount - $p->refunded) {
                throw new FeeException('This receipt does not have that much left to refund.');
            }
            $fromAdvance = min($p->advance, $r->amount);
            $fromDues = $r->amount - $fromAdvance;
            $touched = [];
            foreach ($p->allocations()->orderByDesc('created_at')->with('invoice')->get() as $a) {
                if ($fromDues - array_sum($touched) <= 0) {
                    break;
                }
                $cut = min($a->amount, $fromDues - array_sum($touched));
                $a->update(['amount' => $a->amount - $cut]);
                $touched[$a->invoice_id] = ($touched[$a->invoice_id] ?? 0) + $cut;
            }
            if (array_sum($touched) !== $fromDues) {
                throw new FeeException('The refund is more than this receipt paid.');
            }
            $v = $this->books->post('journal', 'fee_refund_approval', $r->id, today()->format('Y-m-d'), array_values(array_filter([
                $fromAdvance ? ['key' => 'fee_advance', 'debit' => $fromAdvance] : null,
                $fromDues ? ['key' => 'fee_receivable', 'debit' => $fromDues] : null,
                ['key' => 'fee_refund_payable', 'credit' => $r->amount],
            ])), "Refund approved on {$p->number}: {$r->reason}", $p->branch_id, $p->number);
            $p->update(['advance' => $p->advance - $fromAdvance, 'refunded' => $p->refunded + $r->amount]);
            foreach (array_keys($touched) as $id) {
                $this->plans->refresh(FeeInvoice::find($id));
            }
            $r->update(['status' => 'approved', 'approved_by' => $this->member->userId(), 'approved_at' => now(), 'approval_voucher_id' => $v->id]);
            Audit::record('fees.refund_approved', $r);

            return $r;
        });
    }

    public function rejectRefund(FeeRefund $r, string $reason): FeeRefund
    {
        $this->member->authorize('fees.approve');
        if ($r->status !== 'requested') {
            throw new FeeException('This refund has already been decided.');
        }
        $r->update(['status' => 'rejected', 'failure' => $reason]);
        Audit::record('fees.refund_rejected', $r, ['reason' => $reason]);

        return $r;
    }

    /**
     * Pays an approved refund. Gateway refunds go back through the gateway's API; cash and bank refunds need the
     * payout reference. The payout is posted once the money has actually gone (immediately, or when the gateway confirms).
     */
    public function processRefund(FeeRefund $r, array $d = []): FeeRefund
    {
        $this->member->authorize('fees.approve');
        if (! in_array($r->status, ['approved', 'failed'], true)) {
            throw new FeeException('Only an approved refund can be paid out.');
        }
        $p = $r->payment;
        if (in_array($r->mode, ['razorpay', 'cashfree'], true)) {
            try {
                $res = app(OnlinePayments::class)->gateway($r->mode)->refund($p, $r);
            } catch (\Modules\Fees\Gateways\GatewayException $e) {
                $r->update(['status' => 'failed', 'failure' => mb_substr($e->getMessage(), 0, 300)]);
                Audit::record('fees.refund_failed', $r, ['error' => $e->getMessage()]);
                throw new FeeException('The gateway refused the refund: '.$e->getMessage());
            }
            $r->update(['gateway_refund_id' => $res['id'], 'failure' => null, 'status' => 'approved']);
            if ($res['processed']) {
                $this->refundPaid($r, $res['id']);
            }

            return $r->fresh();
        }
        $ref = trim((string) ($d['reference'] ?? ''));
        if ($r->mode === 'bank' && $ref === '') {
            throw new FeeException('Enter the UTR or cheque number of the refund payment.', 'reference');
        }

        return $this->refundPaid($r, $ref ?: null);
    }

    /** The refund money has left: Dr Fee refunds payable, Cr cash, bank or the gateway's clearing ledger. Safe to call twice. */
    public function refundPaid(FeeRefund $r, ?string $reference = null): FeeRefund
    {
        return DB::transaction(function () use ($r, $reference) {
            $r = FeeRefund::lockForUpdate()->find($r->id);
            if ($r->status === 'processed') {
                return $r;
            }
            $v = $this->books->feeRefund($r->id, today()->format('Y-m-d'), $r->amount, $r->mode, $r->payment->branch_id, $reference ?? $r->gateway_refund_id);
            $r->update(['status' => 'processed', 'processed_at' => now(), 'reference' => $reference ?? $r->reference, 'payout_voucher_id' => $v->id]);
            Audit::record('fees.refund_paid', $r);

            return $r;
        });
    }
}
