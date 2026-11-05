<?php

use App\Core\Tenancy\TenantSchema as T;
use App\Core\Tenancy\TenantTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Double-entry accounting. Amounts are integer paise.
 *
 * vouchers hold the document (draft lines while it is a draft); ledger_entries are the posted
 * debits and credits. ledger_entries can never be updated or deleted (database triggers), so a
 * mistake is corrected only by posting a reversal.
 */
return new class extends Migration
{
    public const TENANT_TABLES = ['accounting_settings', 'financial_years', 'account_groups', 'cost_centres', 'tds_sections', 'tax_rates', 'ledgers',
        'vouchers', 'ledger_entries', 'invoices', 'invoice_lines', 'bank_statement_lines', 'budgets', 'fixed_assets', 'depreciation_postings'];

    public function up(): void
    {
        Schema::create('accounting_settings', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('gstin', 15)->nullable();
            $table->char('state_code', 2)->nullable();       // place of business for CGST+SGST vs IGST
            $table->string('tan', 10)->nullable();
            $table->string('pan', 10)->nullable();
            $table->date('locked_until')->nullable();        // nothing dated on or before this can be posted or reversed
            $table->json('approval_thresholds');             // voucher type => paise (0 = always, null = never)
            $table->boolean('allow_self_approval')->default(false);
            $table->timestamps();
            $table->unique('tenant_id');
        });

        Schema::create('financial_years', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('name', 9);                       // 2026-27
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 10)->default('open');   // open | closed
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by', 36)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('account_groups', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'parent_id', 'account_groups', nullable: true);
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('nature', 10);                    // asset | liability | equity | income | expense
            $table->string('cash_flow', 10)->nullable();     // operating | investing | financing (for the cash flow statement)
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('cost_centres', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'parent_id', 'cost_centres', nullable: true);
            $table->string('code', 20);
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('tds_sections', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('code', 10);                      // 194C, 194J ...
            $table->string('name', 150);
            $table->unsignedInteger('rate_bp');              // with PAN
            $table->unsignedInteger('rate_no_pan_bp');       // section 206AA
            $table->bigInteger('threshold_single')->default(0);   // paise; one bill above this attracts TDS
            $table->bigInteger('threshold_annual')->default(0);   // paise; or the year's total above this
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code', 'effective_from']);
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('name', 60);                      // GST 18%
            $table->unsignedInteger('rate_bp');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ledgers', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'group_id', 'account_groups');
            T::ref($table, 'branch_id', 'branches', nullable: true);
            T::ref($table, 'tds_section_id', 'tds_sections', nullable: true);
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('type', 12)->default('general');  // general | cash | bank | customer | vendor | student | employee | tax | gateway
            $table->string('system_key', 40)->nullable();    // fee_receivable, gst_output_cgst ... for automatic postings
            $table->bigInteger('opening_balance')->default(0);   // paise; positive = debit, negative = credit
            $table->string('gstin', 15)->nullable();
            $table->char('state_code', 2)->nullable();
            $table->string('pan', 10)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('account_number', 30)->nullable();
            $table->string('ifsc', 11)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'system_key']);
        });

        Schema::create('vouchers', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'financial_year_id', 'financial_years');
            T::ref($table, 'branch_id', 'branches', nullable: true);
            T::ref($table, 'reversal_of_id', 'vouchers', nullable: true);
            $table->string('type', 12);                      // payment | receipt | contra | journal | sales | purchase | credit_note | debit_note | expense | opening | reversal
            $table->string('number', 30)->nullable();        // given when posted, gapless per type and year
            $table->date('date');
            $table->string('status', 10)->default('draft');  // draft | pending | posted | rejected | reversed
            $table->string('narration', 500)->nullable();
            $table->string('reference', 60)->nullable();     // cheque, UTR, bill number
            $table->json('lines');                           // the debit and credit lines as entered
            $table->bigInteger('total')->default(0);         // paise, sum of debits
            $table->string('source_type', 40)->nullable();   // fee_payment, payroll_run, gateway_settlement, depreciation, invoice ...
            $table->string('source_id', 60)->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->uuid('reversed_by_id')->nullable();
            $table->string('created_by', 36)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('approved_by', 36)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_reason', 300)->nullable();
            $table->string('posted_by', 36)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->char('entries_hash', 64)->nullable();    // sha256 of the posted entries, for tamper evidence
            $table->timestamps();
            $table->unique(['tenant_id', 'type', 'financial_year_id', 'number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status', 'date']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'voucher_id', 'vouchers');
            T::ref($table, 'ledger_id', 'ledgers');
            T::ref($table, 'branch_id', 'branches', nullable: true);
            T::ref($table, 'cost_centre_id', 'cost_centres', nullable: true);
            $table->date('date');
            $table->unsignedSmallInteger('line_no');
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->string('narration', 300)->nullable();
            $table->string('bill_ref', 40)->nullable();      // invoice number this line settles
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'ledger_id', 'date']);
            $table->index(['tenant_id', 'bill_ref']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'party_ledger_id', 'ledgers');
            T::ref($table, 'branch_id', 'branches', nullable: true);
            T::ref($table, 'against_invoice_id', 'invoices', nullable: true);   // credit and debit notes
            T::ref($table, 'tds_section_id', 'tds_sections', nullable: true);
            T::ref($table, 'voucher_id', 'vouchers', nullable: true);
            $table->string('kind', 12);                      // sales | purchase | credit_note | debit_note | expense
            $table->string('number', 30)->nullable();
            $table->string('party_invoice_no', 40)->nullable();   // the supplier's bill number
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->char('place_of_supply', 2)->nullable();
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('cgst')->default(0);
            $table->bigInteger('sgst')->default(0);
            $table->bigInteger('igst')->default(0);
            $table->bigInteger('total')->default(0);         // subtotal + GST
            $table->bigInteger('tds')->default(0);
            $table->bigInteger('net')->default(0);           // total - TDS: what the party is owed or owes
            $table->string('status', 10)->default('draft');  // draft | pending | posted | cancelled
            $table->string('notes', 500)->nullable();
            $table->string('created_by', 36)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'kind', 'date']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'invoice_id', 'invoices', cascade: true);
            T::ref($table, 'ledger_id', 'ledgers');          // income, expense or asset ledger
            T::ref($table, 'cost_centre_id', 'cost_centres', nullable: true);
            $table->string('description', 200);
            $table->string('hsn_sac', 10)->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->bigInteger('rate');                      // paise per unit
            $table->bigInteger('amount');                    // paise, before GST
            $table->unsignedInteger('gst_bp')->default(0);
            $table->timestamps();
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'ledger_id', 'ledgers');
            T::ref($table, 'matched_entry_id', 'ledger_entries', nullable: true);
            $table->date('date');
            $table->string('description', 300);
            $table->string('reference', 60)->nullable();
            $table->bigInteger('amount');                    // paise; + money in, - money out
            $table->string('batch', 40);
            $table->timestamp('matched_at')->nullable();
            $table->string('matched_by', 36)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'matched_entry_id']);
        });

        Schema::create('budgets', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'financial_year_id', 'financial_years');
            T::ref($table, 'ledger_id', 'ledgers');
            T::ref($table, 'cost_centre_id', 'cost_centres', nullable: true);
            T::ref($table, 'branch_id', 'branches', nullable: true);
            $table->bigInteger('amount');                    // paise for the year
            $table->json('months')->nullable();              // 12 amounts, April first, if phased
            $table->timestamps();
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'asset_ledger_id', 'ledgers');
            T::ref($table, 'depreciation_ledger_id', 'ledgers');      // expense
            T::ref($table, 'accumulated_ledger_id', 'ledgers');       // contra-asset
            T::ref($table, 'branch_id', 'branches', nullable: true);
            T::ref($table, 'cost_centre_id', 'cost_centres', nullable: true);
            $table->string('code', 30);
            $table->string('name', 150);
            $table->date('put_to_use_on');
            $table->bigInteger('cost');
            $table->bigInteger('salvage')->default(0);
            $table->string('method', 4);                     // slm | wdv
            $table->unsignedInteger('rate_bp');              // per year
            $table->string('status', 10)->default('active'); // active | disposed
            $table->date('disposed_on')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('depreciation_postings', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'asset_id', 'fixed_assets');
            T::ref($table, 'voucher_id', 'vouchers');
            $table->char('period', 7);                       // 2026-11
            $table->bigInteger('amount');
            $table->timestamps();
            $table->unique(['tenant_id', 'asset_id', 'period']);
        });

        foreach (self::TENANT_TABLES as $t) {
            TenantTriggers::create($t);
        }
        self::immutableEntries();
    }

    /** Posted entries cannot change: no UPDATE or DELETE, and each line is one-sided and non-negative. */
    private static function immutableEntries(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_one_side CHECK (debit >= 0 AND credit >= 0 AND (debit = 0) <> (credit = 0))");
            DB::unprepared("CREATE OR REPLACE FUNCTION ledger_entries_immutable() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'Posted ledger entries cannot be changed. Post a reversal instead.'; END; \$\$ LANGUAGE plpgsql");
            DB::unprepared('CREATE TRIGGER ledger_entries_no_update BEFORE UPDATE OR DELETE ON ledger_entries FOR EACH ROW EXECUTE FUNCTION ledger_entries_immutable()');
        } elseif ($driver === 'mysql') {
            DB::unprepared('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_one_side CHECK (debit >= 0 AND credit >= 0 AND ((debit = 0) <> (credit = 0)))');
            foreach (['UPDATE', 'DELETE'] as $e) {
                DB::unprepared("CREATE TRIGGER ledger_entries_no_".strtolower($e)." BEFORE {$e} ON ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted ledger entries cannot be changed. Post a reversal instead.'");
            }
        } else {   // SQLite (local test runs)
            foreach (['UPDATE', 'DELETE'] as $e) {
                DB::unprepared("CREATE TRIGGER ledger_entries_no_".strtolower($e)." BEFORE {$e} ON ledger_entries BEGIN SELECT RAISE(ABORT, 'Posted ledger entries cannot be changed. Post a reversal instead.'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (['ledger_entries_no_update', 'ledger_entries_no_delete'] as $t) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t} ON ledger_entries");
            } else {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
            }
        }
        foreach (array_reverse(self::TENANT_TABLES) as $t) {
            TenantTriggers::drop($t);
            Schema::dropIfExists($t);
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ledger_entries_immutable()');
        }
    }
};
