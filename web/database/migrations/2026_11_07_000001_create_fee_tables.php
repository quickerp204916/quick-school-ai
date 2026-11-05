<?php

use App\Core\Tenancy\TenantSchema as T;
use App\Core\Tenancy\TenantTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fee management. Amounts are integer paise.
 *
 * A fee structure lists heads with an amount per period (monthly, quarterly, half-yearly, annual or one time).
 * A student's fee plan applies a structure and any concessions for a session; it generates one fee invoice per
 * instalment. Payments are allocated to invoices; every invoice, late fee, payment, refund and waiver posts to the
 * books through the accounting gateway, which is idempotent on the source document.
 */
return new class extends Migration
{
    public const TENANT_TABLES = ['fee_settings', 'fee_heads', 'fee_structures', 'fee_structure_items', 'fee_concessions', 'student_fee_plans',
        'student_fee_concessions', 'fee_invoices', 'fee_invoice_lines', 'fee_payment_orders', 'fee_payments', 'fee_payment_allocations',
        'fee_refunds', 'fee_reminders', 'fee_webhook_events'];

    public function up(): void
    {
        Schema::create('fee_settings', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->json('gateways')->nullable();              // enabled gateways: ["razorpay", "cashfree"]
            $table->string('razorpay_key_id', 60)->nullable();
            $table->text('razorpay_key_secret')->nullable();    // encrypted
            $table->text('razorpay_webhook_secret')->nullable(); // encrypted
            $table->string('cashfree_app_id', 80)->nullable();
            $table->text('cashfree_secret_key')->nullable();    // encrypted; also signs Cashfree webhooks
            $table->string('cashfree_env', 10)->default('sandbox'); // sandbox | production
            $table->json('reminder_days_before')->nullable();   // [7, 1]
            $table->json('reminder_days_after')->nullable();    // [1, 7, 15]
            $table->boolean('reminders_enabled')->default(true);
            $table->string('receipt_note', 300)->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
        });

        Schema::create('fee_heads', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('kind', 12)->default('recurring');  // recurring | one_time | deposit | optional
            T::ref($table, 'ledger_id', 'ledgers');              // income ledger (or deposit liability for refundable deposits)
            $table->boolean('is_refundable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('fee_structures', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('name', 150);
            T::ref($table, 'session_id', 'academic_sessions');
            T::ref($table, 'grade_level_id', 'grade_levels', nullable: true);
            T::ref($table, 'branch_id', 'branches', nullable: true);
            $table->unsignedTinyInteger('due_day')->default(10);        // day of the month an instalment falls due
            $table->string('late_fee_type', 10)->default('none');       // none | flat | per_day | percent
            $table->unsignedBigInteger('late_fee_value')->default(0);   // paise, or basis points for percent
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->unsignedBigInteger('late_fee_cap')->nullable();     // paise
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'session_id', 'name']);
        });

        Schema::create('fee_structure_items', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'structure_id', 'fee_structures', cascade: true);
            T::ref($table, 'fee_head_id', 'fee_heads');
            $table->string('frequency', 12);                 // monthly | quarterly | half_yearly | annual | one_time
            $table->unsignedBigInteger('amount');            // per period, paise
            $table->json('months')->nullable();              // monthly heads: months charged, e.g. [4,5,...,3]; null = every month
            $table->timestamps();
            $table->unique(['tenant_id', 'structure_id', 'fee_head_id']);
        });

        Schema::create('fee_concessions', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('name', 120);
            $table->string('category', 12);                  // discount | scholarship | sibling | staff_ward | other
            $table->string('type', 8);                       // percent | amount
            $table->unsignedBigInteger('value');             // basis points or paise per instalment
            $table->json('fee_head_ids')->nullable();        // null = all heads
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('student_fee_plans', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'student_id', 'students');
            T::ref($table, 'session_id', 'academic_sessions');
            T::ref($table, 'structure_id', 'fee_structures');
            $table->json('optional_head_ids')->nullable();   // optional heads the student takes (transport, hostel)
            $table->string('status', 10)->default('active'); // active | cancelled
            $table->string('created_by', 36)->nullable();
            $table->timestamps();
            // One active plan per student per session.
            $table->string('active_session_id', 36)->nullable()->storedAs("CASE WHEN status = 'active' THEN CAST(session_id AS CHAR(36)) END");
            $table->unique(['tenant_id', 'student_id', 'active_session_id'], 'student_fee_plans_one_active');
        });

        Schema::create('student_fee_concessions', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'plan_id', 'student_fee_plans', cascade: true);
            T::ref($table, 'concession_id', 'fee_concessions');
            $table->string('reason', 300)->nullable();
            $table->string('status', 10)->default('pending'); // pending | approved | rejected
            $table->string('requested_by', 36)->nullable();
            $table->string('approved_by', 36)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'plan_id', 'concession_id']);
        });

        Schema::create('fee_invoices', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('number', 30)->nullable();        // FEE/2026-27/00001, given when issued
            T::ref($table, 'student_id', 'students');
            T::ref($table, 'plan_id', 'student_fee_plans');
            T::ref($table, 'branch_id', 'branches', nullable: true);
            $table->unsignedSmallInteger('instalment');      // 1, 2, 3 ... within the plan
            $table->string('period', 40);                    // "April 2026", "Quarter 1", "Annual"
            $table->date('issue_date');
            $table->date('due_date');
            $table->unsignedBigInteger('gross')->default(0);
            $table->unsignedBigInteger('concession')->default(0);
            $table->unsignedBigInteger('late_fee')->default(0);
            $table->unsignedBigInteger('waived')->default(0);
            $table->unsignedBigInteger('paid')->default(0);
            $table->string('status', 10)->default('draft');  // draft | open | partial | paid | cancelled
            $table->string('voucher_id', 36)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'plan_id', 'instalment']);
            $table->index(['tenant_id', 'status', 'due_date']);
        });

        Schema::create('fee_invoice_lines', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'invoice_id', 'fee_invoices', cascade: true);
            T::ref($table, 'fee_head_id', 'fee_heads', nullable: true);  // null for late fee lines
            $table->string('description', 150);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('concession')->default(0);
            $table->string('kind', 10)->default('fee');      // fee | late_fee
            $table->timestamps();
        });

        Schema::create('fee_payment_orders', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'student_id', 'students');
            $table->string('gateway', 10);                   // razorpay | cashfree
            $table->string('gateway_order_id', 80)->nullable();
            $table->string('payment_session_id', 255)->nullable(); // Cashfree checkout
            $table->unsignedBigInteger('amount');
            $table->json('invoice_ids');
            $table->string('status', 10)->default('created'); // created | paid | failed | expired
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['gateway', 'gateway_order_id']);
        });

        Schema::create('fee_payments', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('number', 30)->nullable();        // receipt number RCT/2026-27/00001
            T::ref($table, 'student_id', 'students');
            T::ref($table, 'branch_id', 'branches', nullable: true);
            $table->date('date');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('advance')->default(0);  // part not allocated to any invoice
            $table->unsignedBigInteger('refunded')->default(0);
            $table->string('mode', 10);                      // cash | cheque | bank | upi | razorpay | cashfree
            $table->string('reference', 80)->nullable();     // cheque no, UTR, UPI ref
            $table->string('gateway_payment_id', 80)->nullable();
            T::ref($table, 'order_id', 'fee_payment_orders', nullable: true);
            $table->string('status', 10)->default('success'); // success | bounced
            $table->string('idempotency_key', 120);
            $table->string('voucher_id', 36)->nullable();
            $table->foreignUuid('received_by')->nullable()->constrained('users');
            $table->string('remarks', 300)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['mode', 'gateway_payment_id']);  // a gateway payment is recorded once, whichever tenant
        });

        Schema::create('fee_payment_allocations', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'payment_id', 'fee_payments', cascade: true);
            T::ref($table, 'invoice_id', 'fee_invoices');
            $table->unsignedBigInteger('amount');
            $table->string('via', 8)->default('payment');    // payment (at collection) | advance (later use of an advance)
            $table->timestamps();
        });

        Schema::create('fee_refunds', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'payment_id', 'fee_payments');
            $table->unsignedBigInteger('amount');
            $table->string('reason', 300);
            $table->string('mode', 10);                      // cash | bank | razorpay | cashfree (back to the source)
            $table->string('status', 10)->default('requested'); // requested | approved | processed | rejected | failed
            $table->string('gateway_refund_id', 80)->nullable();
            $table->string('reference', 80)->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('approval_voucher_id', 36)->nullable();
            $table->string('payout_voucher_id', 36)->nullable();
            $table->string('failure', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('fee_reminders', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'invoice_id', 'fee_invoices', cascade: true);
            $table->string('kind', 12);                      // before_due | overdue
            $table->smallInteger('offset_days');             // -7, -1, 1, 7 ...
            $table->string('channel', 10);                   // mail
            $table->string('recipient', 191);
            $table->timestamps();
            $table->unique(['tenant_id', 'invoice_id', 'kind', 'offset_days', 'channel'], 'fee_reminders_once');
        });

        Schema::create('fee_webhook_events', function (Blueprint $table) {
            T::base($table);
            $table->string('gateway', 10);
            $table->string('event_id', 120);                 // the gateway's event id (or a hash of the body)
            $table->string('event', 60);
            $table->boolean('verified');
            $table->string('result', 300)->nullable();
            $table->json('payload');
            $table->timestamps();
            $table->unique(['tenant_id', 'gateway', 'event_id']);
        });

        foreach (self::TENANT_TABLES as $t) {
            TenantTriggers::create($t);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TENANT_TABLES) as $t) {
            TenantTriggers::drop($t);
            Schema::dropIfExists($t);
        }
    }
};
