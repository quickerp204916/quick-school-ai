<?php

namespace Modules\Accounting\Services;

use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\Voucher;

/**
 * How other modules post to the books. Each call is idempotent on its source document, so a retried webhook,
 * a re-run job or a double click never posts twice. The calling module is responsible for its own approval
 * (for example payroll is approved before it is posted).
 */
final class AccountingGateway
{
    public function __construct(private readonly VoucherService $vouchers)
    {
    }

    /** Generic posting. Lines use 'key' (system ledger) or 'ledger_id', with 'debit' or 'credit' in paise. */
    public function post(string $type, string $sourceType, string $sourceId, string $date, array $lines, string $narration, ?string $branchId = null, ?string $reference = null): Voucher
    {
        return $this->vouchers->record($type, $date, $lines, $sourceType, $sourceId, $narration, $branchId, $reference);
    }

    /**
     * A fee payment. $mode: cash, bank, razorpay, cashfree. $credits: ledger keys or ids => paise (defaults to fees receivable).
     * Online payments go to the gateway's clearing ledger until the gateway settles them into the bank.
     */
    public function feeCollection(string $paymentId, string $date, int $amount, string $mode, array $credits = [], ?string $branchId = null, ?string $reference = null, ?string $bankLedgerId = null): Voucher
    {
        $debitLedger = match ($mode) {
            'cash' => Ledger::system('cash')->id,
            'bank' => $bankLedgerId ?? Ledger::system('bank_main')->id,
            'razorpay' => Ledger::system('gateway_razorpay')->id,
            'cashfree' => Ledger::system('gateway_cashfree')->id,
            default => throw new AccountingException("Unknown payment mode {$mode}."),
        };
        $credits = $credits ?: ['fee_receivable' => $amount];
        if (array_sum($credits) !== $amount) {
            throw new AccountingException('The fee allocation does not add up to the payment.');
        }
        $lines = [['ledger_id' => $debitLedger, 'debit' => $amount]];
        foreach ($credits as $ledger => $paise) {
            $lines[] = (\Illuminate\Support\Str::isUuid((string) $ledger) ? ['ledger_id' => $ledger] : ['key' => $ledger]) + ['credit' => $paise];
        }
        $type = in_array($mode, ['cash', 'bank'], true) ? 'receipt' : 'journal';

        return $this->post($type, 'fee_payment', $paymentId, $date, $lines, "Fee received ({$mode})", $branchId, $reference);
    }

    /** A gateway settlement: gross collected leaves the clearing ledger; the bank gets the net; fees and GST on fees are expensed. */
    public function gatewaySettlement(string $gateway, string $settlementId, string $date, int $gross, int $fee, int $gstOnFee, ?string $bankLedgerId = null): Voucher
    {
        if ($gross <= 0 || $fee < 0 || $gstOnFee < 0 || $fee + $gstOnFee >= $gross) {
            throw new AccountingException('The settlement amounts do not add up.');
        }
        $lines = [
            ['ledger_id' => $bankLedgerId ?? Ledger::system('bank_main')->id, 'debit' => $gross - $fee - $gstOnFee],
            ['key' => 'gateway_charges', 'debit' => $fee],
            ['key' => 'gst_input_igst', 'debit' => $gstOnFee],
            ['key' => "gateway_{$gateway}", 'credit' => $gross],
        ];

        return $this->post('receipt', 'gateway_settlement', "{$gateway}:{$settlementId}", $date, array_values(array_filter($lines, fn ($l) => ($l['debit'] ?? 1) !== 0)),
            ucfirst($gateway)." settlement {$settlementId}", null, $settlementId);
    }

    /** A refund paid back to a parent. */
    public function feeRefund(string $refundId, string $date, int $amount, string $mode, ?string $branchId = null, ?string $reference = null): Voucher
    {
        $credit = match ($mode) {
            'cash' => Ledger::system('cash')->id,
            'bank' => Ledger::system('bank_main')->id,
            'razorpay', 'cashfree' => Ledger::system("gateway_{$mode}")->id,
            default => throw new AccountingException("Unknown refund mode {$mode}."),
        };

        return $this->post(in_array($mode, ['cash', 'bank'], true) ? 'payment' : 'journal', 'fee_refund', $refundId, $date,
            [['key' => 'fee_refund_payable', 'debit' => $amount], ['ledger_id' => $credit, 'credit' => $amount]], "Fee refund ({$mode})", $branchId, $reference);
    }
}
