<?php

namespace Tests\Feature\Accounting;

use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\Voucher;
use Modules\Accounting\Services\VoucherService;
use Tests\Feature\Academic\AcademicTestCase;

/** An institution with its books, signed in as the owner unless a test says otherwise. Today is Wed 4 Nov 2026 (FY 2026-27). */
abstract class AccountingTestCase extends AcademicTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asOwner();
    }

    protected function ledger(string $code): Ledger
    {
        return $this->in(fn () => Ledger::where('code', $code)->firstOrFail());
    }

    protected function vouchers(): VoucherService
    {
        return app(VoucherService::class);
    }

    /** Posts a manual voucher through draft and submit. Amounts in rupees. */
    protected function postVoucher(string $type, array $lines, string $date = '2026-11-02', array $extra = []): Voucher
    {
        return $this->in(function () use ($type, $lines, $date, $extra) {
            $rows = array_map(fn ($l) => ['ledger_id' => $this->ledger($l[0])->id, 'debit' => $l[1] ?? '', 'credit' => $l[2] ?? ''] + ($l[3] ?? []), $lines);
            $v = $this->vouchers()->saveDraft($extra + ['type' => $type, 'date' => $date, 'lines' => $rows, 'narration' => 'Test']);

            return $this->vouchers()->submit($v);
        });
    }

    protected function balance(string $code, string $upTo = '2027-03-31'): int
    {
        return $this->in(fn () => app(\Modules\Accounting\Services\Books::class)->balance($this->ledger($code), $upTo));
    }
}
