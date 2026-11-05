<?php

namespace Tests\Feature\Accounting;

use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\FinancialYear;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Services\AccountingGateway;
use Modules\Accounting\Services\AssetService;
use Modules\Accounting\Services\BankReconciliation;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\InvoiceService;
use Modules\Accounting\Services\LedgerService;

class InvoicesAndReportsTest extends AccountingTestCase
{
    private function party(string $code, string $type, array $extra = []): Ledger
    {
        return $this->in(fn () => app(LedgerService::class)->saveLedger($extra + ['code' => $code, 'name' => $code.' Pvt Ltd', 'type' => $type,
            'group_id' => \Modules\Accounting\Models\AccountGroup::where('code', $type === 'vendor' ? 'L11' : 'A14')->value('id')]));
    }

    private function invoice(string $kind, Ledger $party, array $lines, array $extra = []): Invoice
    {
        return $this->in(function () use ($kind, $party, $lines, $extra) {
            $inv = app(InvoiceService::class)->save($kind, $extra + ['party_ledger_id' => $party->id, 'date' => '2026-11-03',
                'lines' => array_map(fn ($l) => ['ledger_id' => $this->ledger($l[0])->id, 'description' => $l[0], 'quantity' => $l[2] ?? 1, 'rate' => $l[1], 'gst_bp' => $l[3] ?? 0], $lines)]);

            return app(InvoiceService::class)->submit($inv);
        });
    }

    public function test_sales_invoice_with_gst_in_the_same_state_and_another_state(): void
    {
        $this->in(fn () => app(LedgerService::class)->saveSettings(['gstin' => '09AAACS1234A1Z5', 'thresholds' => config('accounting.approval_thresholds')]));
        $local = $this->party('HALL-UP', 'customer', ['state_code' => '09']);
        $other = $this->party('HALL-DL', 'customer', ['gstin' => '07AABCH1234B1Z2']);

        $a = $this->invoice('sales', $local, [['MISC-INCOME', '10000', 1, 1800]]);
        $this->assertSame(['posted', 'INV/2026-27/00001', 1000000, 90000, 90000, 0, 1180000], [$a->status, $a->number, $a->subtotal, $a->cgst, $a->sgst, $a->igst, $a->total]);
        $b = $this->invoice('sales', $other, [['MISC-INCOME', '2500', 2, 1800]]);
        $this->assertSame([500000, 0, 0, 90000, '07'], [$b->subtotal, $b->cgst, $b->sgst, $b->igst, $b->place_of_supply]);
        $this->assertSame(-180000, $this->balance('GST-OUT-CGST') + $this->balance('GST-OUT-SGST') + $this->balance('GST-OUT-IGST') + 90000);
        $this->assertSame(1180000, $this->balance('HALL-UP'));
    }

    public function test_purchase_with_tds_once_the_vendors_year_crosses_the_threshold(): void
    {
        $section = $this->in(fn () => \Modules\Accounting\Models\TdsSection::where('code', '194J')->first());
        $vendor = $this->party('AUDITOR', 'vendor', ['pan' => 'ABCDE1234F', 'tds_section_id' => $section->id]);
        $first = $this->invoice('purchase', $vendor, [['REPAIRS', '30000']], ['party_invoice_no' => 'A-1']);
        $this->assertSame(0, $first->tds, 'Year total ₹30,000 is under ₹50,000');
        $second = $this->invoice('purchase', $vendor, [['REPAIRS', '30000', 1, 1800]], ['party_invoice_no' => 'A-2']);
        $this->assertSame([300000, 3540000, 3240000, '194J'], [$second->tds, $second->total, $second->net, $this->in(fn () => $second->tdsSection->code)]);
        $this->assertSame(-300000, $this->balance('TDS-PAY'));
        $this->assertSame(-(3000000 + 3240000), $this->balance('AUDITOR'));

        $this->assertThrows(fn () => $this->invoice('purchase', $vendor, [['REPAIRS', '10']], ['party_invoice_no' => 'A-2']), AccountingException::class, 'already entered');

        $noPan = $this->party('PAINTER', 'vendor', ['tds_section_id' => $this->in(fn () => \Modules\Accounting\Models\TdsSection::where('code', '194C')->value('id'))]);
        $p = $this->invoice('purchase', $noPan, [['REPAIRS', '40000']], ['party_invoice_no' => 'P-9']);
        $this->assertSame(800000, $p->tds, 'Over the ₹30,000 single-bill limit, without PAN: 20%');
    }

    public function test_credit_note_is_limited_to_the_invoice_and_receipts_settle_bills(): void
    {
        $c = $this->party('PARENT-1', 'customer');
        $inv = $this->invoice('sales', $c, [['MISC-INCOME', '5000']]);
        $this->assertThrows(fn () => $this->invoice('credit_note', $c, [['MISC-INCOME', '6000']], ['against_invoice_id' => $inv->id]), AccountingException::class, 'more than what is left');
        $this->asOwner();
        $note = $this->in(fn () => app(\Modules\Accounting\Models\Voucher::class)::count());
        $cn = $this->in(fn () => app(InvoiceService::class)->save('credit_note', ['party_ledger_id' => $c->id, 'date' => '2026-11-03', 'against_invoice_id' => $inv->id,
            'lines' => [['ledger_id' => $this->ledger('MISC-INCOME')->id, 'description' => 'Overcharge', 'quantity' => 1, 'rate' => '1000']]]));
        $cn = $this->in(fn () => app(InvoiceService::class)->submit($cn));
        $this->assertSame('pending', $cn->status, 'Credit notes always need approval');
        $this->actingAs($this->member('admin'), 'web');
        $this->in(fn () => $this->vouchers()->approve(\Modules\Accounting\Models\Voucher::find($cn->voucher_id)));
        $this->asOwner();
        $this->assertSame('CRN/2026-27/00001', $this->in(fn () => $cn->fresh()->number));

        $this->postVoucher('receipt', [['BANK', '1500'], ['PARENT-1', '', '1500', ['bill_ref' => $inv->number]]]);
        $this->assertSame(250000, $this->in(fn () => app(InvoiceService::class)->outstanding($inv->fresh())));
    }

    public function test_reversing_an_invoice_cancels_it(): void
    {
        $inv = $this->invoice('sales', $this->party('X', 'customer'), [['MISC-INCOME', '100']]);
        $this->in(fn () => $this->vouchers()->reverse($inv->voucher, 'Issued in error'));
        $this->assertSame('cancelled', $this->in(fn () => $inv->fresh()->status));
    }

    public function test_reports_agree_with_each_other(): void
    {
        $this->in(fn () => app(LedgerService::class)->setOpeningBalances([$this->ledger('BANK')->id => '500000', $this->ledger('CASH')->id => '20000',
            $this->ledger('FA-FURNITURE')->id => '300000', $this->ledger('CAPITAL')->id => '-800000']));
        $this->assertSame(['debit' => 82000000, 'credit' => 80000000, 'difference' => 2000000], $this->in(fn () => app(LedgerService::class)->openingSummary()));
        $this->assertSame(0, $this->in(fn () => app(LedgerService::class)->balanceOpening())['difference']);

        $this->postVoucher('receipt', [['BANK', '120000'], ['FEE-TUITION', '', '120000']], '2026-10-05');
        $this->postVoucher('receipt', [['CASH', '8000'], ['FEE-LATE', '', '8000']], '2026-10-06');
        $this->postVoucher('payment', [['SALARY', '24000'], ['BANK', '', '24000']], '2026-10-30');   // ₹24,000 is under the approval threshold
        $this->postVoucher('payment', [['FA-COMPUTERS', '20000'], ['BANK', '', '20000']], '2026-10-31');
        $this->postVoucher('contra', [['BANK', '5000'], ['CASH', '', '5000']], '2026-11-01');

        $this->in(function () {
            $books = app(Books::class);
            $tb = $books->trialBalance('2026-04-01', '2026-11-30');
            $this->assertSame($tb['closing_dr'], $tb['closing_cr']);
            $this->assertSame($tb['debit'], $tb['credit']);

            $pl = $books->profitAndLoss('2026-04-01', '2026-11-30');
            $this->assertSame([12800000, 2400000, 10400000], [$pl['total_income'], $pl['total_expense'], $pl['surplus']]);

            $bs = $books->balanceSheet('2026-11-30');
            $this->assertSame(0, $bs['difference']);
            $this->assertSame(10400000, $bs['surplus']);

            $cf = $books->cashFlow('2026-04-01', '2026-11-30');
            $this->assertSame($cf['opening'] + $cf['net'], $cf['closing']);
            $this->assertSame(-2000000, $cf['sections']['investing']['total']);
            $this->assertSame(12800000 - 2400000, $cf['sections']['operating']['total']);

            $bank = $books->statement(Ledger::system('bank_main'), '2026-10-01', '2026-11-30');
            $this->assertSame([50000000, 12500000, 4400000, 58100000], [$bank['opening'], $bank['debit'], $bank['credit'], $bank['closing']]);
            $this->assertSame('Tuition fee', $bank['rows'][0]['against']);
        });
    }

    public function test_branch_and_cost_centre_reports(): void
    {
        $branch = $this->in(fn () => \Modules\Institution\Models\Branch::firstOrFail());
        $cc = $this->in(fn () => \Modules\Accounting\Models\CostCentre::where('code', 'TRANSPORT')->first());
        $this->postVoucher('payment', [['REPAIRS', '7000', '', ['cost_centre_id' => $cc->id]], ['CASH', '', '7000']], '2026-11-02', ['branch_id' => $branch->id]);
        $this->postVoucher('payment', [['REPAIRS', '3000'], ['CASH', '', '3000']]);
        $this->in(function () use ($branch, $cc) {
            $this->assertSame(700000, app(Books::class)->profitAndLoss('2026-04-01', '2027-03-31', $branch->id)['total_expense']);
            $this->assertSame(1000000, app(Books::class)->profitAndLoss('2026-04-01', '2027-03-31')['total_expense']);
            $row = app(Books::class)->costCentres('2026-04-01', '2027-03-31')->first();
            $this->assertSame([$cc->id, 700000], [$row['cost_centre']->id, $row['expense']]);
        });
    }

    public function test_budget_against_actual(): void
    {
        $this->in(fn () => app(LedgerService::class)->saveBudget(['financial_year_id' => FinancialYear::current()->id, 'ledger_id' => $this->ledger('ELECTRICITY')->id, 'amount' => '120000']));
        $this->postVoucher('payment', [['ELECTRICITY', '15000'], ['BANK', '', '15000']]);
        $r = $this->in(fn () => app(Books::class)->budgetVsActual(FinancialYear::current())->first());
        $this->assertSame([12000000, 1500000, 10500000, 12.5], [$r['budget']->amount, $r['actual'], $r['variance'], $r['used']]);
    }

    public function test_bank_statement_import_matching_and_reconciliation(): void
    {
        $this->postVoucher('receipt', [['BANK', '15000'], ['FEE-TUITION', '', '15000']], '2026-10-28', ['reference' => 'UTR77']);
        $this->postVoucher('payment', [['ELECTRICITY', '4200'], ['BANK', '', '4200']], '2026-10-29', ['reference' => 'CHQ 000123']);
        $this->postVoucher('payment', [['REPAIRS', '900'], ['BANK', '', '900']], '2026-10-30', ['reference' => 'CHQ 000124']);
        $csv = "Date,Description,Ref No,Withdrawal,Deposit\n29/10/2026,NEFT FEES UTR77,UTR77,,15000.00\n31/10/2026,CHQ PAID 000123,000123,4200.00,\n31/10/2026,SMS CHARGES,,17.70,\n";
        $bank = $this->ledger('BANK');
        $this->in(function () use ($bank, $csv) {
            $svc = app(BankReconciliation::class);
            $this->assertSame(['imported' => 3, 'skipped' => 0], $svc->import($bank, $csv));
            $this->assertSame(['imported' => 0, 'skipped' => 3], $svc->import($bank, $csv), 'Re-importing the same file adds nothing');
            $this->assertSame(2, $svc->autoMatch($bank));
            $brs = $svc->statement($bank, '2026-10-31');
            $this->assertSame([990000, 1, 1], [$brs['books'], $brs['payments_not_presented']->count(), $brs['not_in_books']->count()]);
            $this->assertSame(990000 + 90000 - 1770, $brs['bank'], 'Books + cheque not presented − bank charges not booked = bank');
        });
        $this->assertThrows(fn () => $this->in(fn () => app(BankReconciliation::class)->import($bank, "Date,Amount\n1/1/2026,5")), AccountingException::class, 'Description');
    }

    public function test_depreciation_posts_once_per_month(): void
    {
        $this->in(fn () => app(AssetService::class)->register(['code' => 'BUS-1', 'name' => 'School bus', 'asset_ledger_id' => $this->ledger('FA-VEHICLES')->id,
            'put_to_use_on' => '2026-06-01', 'cost' => '1200000', 'salvage' => '0', 'method' => 'slm', 'rate' => '15']));
        $v = $this->in(fn () => app(AssetService::class)->run('2026-10'));
        $this->assertNotNull($v);
        $this->assertSame(1500000, $this->balance('DEPRECIATION'), '₹12,00,000 × 15% ÷ 12 = ₹15,000');
        $this->assertNull($this->in(fn () => app(AssetService::class)->run('2026-10')), 'Running again posts nothing');
        $this->assertSame(1500000, $this->balance('DEPRECIATION'));
    }

    public function test_fee_collections_through_a_gateway_settle_into_the_bank(): void
    {
        $this->in(function () {
            $gw = app(AccountingGateway::class);
            $gw->feeCollection('PAY-1', '2026-11-01', 1000000, 'razorpay');
            $gw->feeCollection('PAY-1', '2026-11-01', 1000000, 'razorpay');   // webhook retried
            $gw->feeCollection('PAY-2', '2026-11-01', 500000, 'cash');
            $gw->gatewaySettlement('razorpay', 'setl_9', '2026-11-03', 1000000, 20000, 3600);
        });
        $this->assertSame([0, 976400, -1500000, 500000, 20000, 3600], [$this->balance('GW-RAZORPAY'), $this->balance('BANK'), $this->balance('FEE-REC'),
            $this->balance('CASH'), $this->balance('GW-CHARGES'), $this->balance('GST-IN-IGST')]);
    }

    public function test_screens_and_permissions(): void
    {
        $this->postVoucher('payment', [['ELECTRICITY', '1200'], ['BANK', '', '1200']]);
        foreach (['accounts', 'accounts/vouchers', 'accounts/vouchers/create?type=payment', 'accounts/approvals', 'accounts/chart', 'accounts/opening',
            'accounts/invoices?kind=sales', 'accounts/invoices/create?kind=purchase', 'accounts/expenses', 'accounts/bank', 'accounts/budgets', 'accounts/assets', 'accounts/settings',
            'accounts/reports/trial-balance', 'accounts/reports/profit-loss', 'accounts/reports/balance-sheet', 'accounts/reports/cash-flow', 'accounts/reports/cash-book',
            'accounts/reports/bank-book', 'accounts/reports/ledger', 'accounts/reports/day-book', 'accounts/reports/budget', 'accounts/reports/cost-centres', 'accounts/reports/outstanding'] as $page) {
            $r = $this->get($this->at($page));
            $this->assertSame(200, $r->status(), "GET {$page}: ".($r->exception?->getMessage() ?? ''));
        }
        $this->actingAs($this->member('teacher'), 'web')->get($this->at('accounts'))->assertForbidden();
    }
}
