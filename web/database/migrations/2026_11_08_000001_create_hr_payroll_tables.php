<?php

use App\Core\Tenancy\TenantSchema as T;
use App\Core\Tenancy\TenantTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR and payroll. The employee master is staff_members (shared with the academic module) plus an HR profile.
 * Amounts are integer paise. Salary structures and statutory rules are effective-dated and never edited once a
 * payroll has used them; approved payslips keep a snapshot of everything they were computed from.
 */
return new class extends Migration
{
    public const TENANT_TABLES = ['hr_departments', 'hr_designations', 'hr_shifts', 'employee_profiles', 'employee_documents', 'hr_attendance',
        'hr_leave_types', 'hr_leave_allocations', 'hr_leave_requests', 'salary_components', 'salary_structures', 'statutory_rules',
        'payroll_runs', 'payslips', 'payroll_inputs', 'fnf_settlements'];

    public function up(): void
    {
        Schema::create('hr_departments', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('code', 20);
            $table->string('name', 100);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('hr_designations', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('name', 100);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_shifts', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('name', 60);
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->json('weekly_off');                       // [0] = Sunday
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('employee_profiles', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'staff_member_id', 'staff_members', cascade: true);
            T::ref($table, 'department_id', 'hr_departments', nullable: true);
            T::ref($table, 'designation_id', 'hr_designations', nullable: true);
            T::ref($table, 'shift_id', 'hr_shifts', nullable: true);
            $table->string('employment_type', 12)->default('permanent'); // permanent | contract | probation | part_time
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            $table->text('pan')->nullable();                  // encrypted
            $table->string('uan', 12)->nullable();            // EPFO universal account number
            $table->string('esi_number', 17)->nullable();
            $table->text('bank_account')->nullable();         // encrypted
            $table->string('bank_ifsc', 11)->nullable();
            $table->string('bank_name', 80)->nullable();
            $table->string('account_holder', 150)->nullable();
            $table->boolean('pf_applicable')->default(true);
            $table->boolean('esi_applicable')->default(true);
            $table->char('pt_state', 2)->nullable();
            $table->string('tax_regime', 3)->default('new');  // new | old
            $table->unsignedBigInteger('declared_80c')->default(0);   // old regime: investments declared, paise a year
            $table->unsignedSmallInteger('notice_days')->default(30);
            $table->date('resigned_on')->nullable();
            $table->date('relieved_on')->nullable();
            $table->string('exit_reason', 200)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'staff_member_id']);
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'staff_member_id', 'staff_members', cascade: true);
            $table->string('type', 20);
            $table->string('title', 150);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->date('expires_on')->nullable();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('hr_attendance', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'staff_member_id', 'staff_members', cascade: true);
            $table->date('date');
            $table->string('status', 10);                     // present | absent | half_day | leave | holiday | weekly_off
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_minutes')->default(0);   // approved overtime
            $table->boolean('paid_leave')->default(true);     // for status leave: false = leave without pay
            $table->string('source', 10)->default('manual');  // manual | import | leave
            $table->string('leave_request_id', 36)->nullable();
            $table->string('remarks', 200)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'staff_member_id', 'date']);
        });

        Schema::create('hr_leave_types', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('code', 10);
            $table->string('name', 80);
            $table->decimal('days_per_year', 5, 1)->default(0);
            $table->boolean('is_paid')->default(true);
            $table->decimal('carry_forward_max', 5, 1)->default(0);
            $table->boolean('encashable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('hr_leave_allocations', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'staff_member_id', 'staff_members', cascade: true);
            T::ref($table, 'leave_type_id', 'hr_leave_types');
            $table->string('year', 7);                        // financial year 2026-27
            $table->decimal('days', 5, 1);                    // allotted plus carried forward
            $table->timestamps();
            $table->unique(['tenant_id', 'staff_member_id', 'leave_type_id', 'year']);
        });

        Schema::create('hr_leave_requests', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'staff_member_id', 'staff_members', cascade: true);
            T::ref($table, 'leave_type_id', 'hr_leave_types');
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('half_day')->default(false);
            $table->decimal('days', 5, 1);
            $table->string('reason', 300);
            $table->string('status', 10)->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 200)->nullable();
            $table->timestamps();
        });

        Schema::create('salary_components', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('code', 12);
            $table->string('name', 80);
            $table->string('kind', 10);                       // earning | deduction
            $table->boolean('taxable')->default(true);
            $table->boolean('pf_wage')->default(false);
            $table->boolean('esi_wage')->default(false);
            $table->boolean('is_computed')->default(false);   // OT, PF, ESI, PT, TDS: worked out by the engine
            T::ref($table, 'ledger_id', 'ledgers');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('salary_structures', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'staff_member_id', 'staff_members', cascade: true);
            $table->date('effective_from');
            $table->json('components');                       // {"BASIC": 2500000, "HRA": 1000000, ...} monthly paise
            $table->unsignedBigInteger('monthly_gross');
            $table->string('note', 200)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['tenant_id', 'staff_member_id', 'effective_from']);
        });

        Schema::create('statutory_rules', function (Blueprint $table) {
            T::base($table);
            $table->string('code', 12);                       // pf | esi | pt | tds | overtime | gratuity
            $table->date('effective_from');
            $table->json('config');
            $table->string('note', 200)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['tenant_id', 'code', 'effective_from']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->char('period', 7);                        // 2026-11
            $table->string('status', 10)->default('draft');   // draft | processed | approved | paid
            $table->unsignedInteger('employees')->default(0);
            $table->unsignedBigInteger('gross')->default(0);
            $table->unsignedBigInteger('deductions')->default(0);
            $table->unsignedBigInteger('employer_cost')->default(0);
            $table->unsignedBigInteger('net')->default(0);
            $table->foreignUuid('processed_by')->nullable()->constrained('users');
            $table->timestamp('processed_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->string('voucher_id', 36)->nullable();     // salary accrual journal
            $table->string('payment_voucher_id', 36)->nullable();
            $table->string('payment_reference', 80)->nullable();
            $table->timestamp('bank_file_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'period']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'run_id', 'payroll_runs', cascade: true);
            T::ref($table, 'staff_member_id', 'staff_members');
            T::ref($table, 'structure_id', 'salary_structures');
            $table->unsignedTinyInteger('days_in_month');
            $table->decimal('payable_days', 5, 1);            // days in service this month
            $table->decimal('lop_days', 5, 1)->default(0);    // loss of pay
            $table->decimal('paid_days', 5, 1);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->json('earnings');                         // {"BASIC": paise, ...}
            $table->json('deductions');
            $table->json('employer');                         // {"PF": .., "EPS": .., "ESI": ..}
            $table->unsignedBigInteger('gross');
            $table->unsignedBigInteger('total_deductions');
            $table->unsignedBigInteger('net');
            $table->json('snapshot');                         // structure, rules and profile values used
            $table->timestamps();
            $table->unique(['tenant_id', 'run_id', 'staff_member_id']);
        });

        Schema::create('payroll_inputs', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'staff_member_id', 'staff_members', cascade: true);
            $table->char('period', 7);
            $table->string('component', 12);                  // BONUS, ADVANCE, OTHER
            $table->unsignedBigInteger('amount');
            $table->string('note', 200)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('fnf_settlements', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            T::ref($table, 'staff_member_id', 'staff_members');
            $table->date('relieved_on');
            $table->decimal('salary_days', 5, 1)->default(0);
            $table->unsignedBigInteger('salary')->default(0);
            $table->decimal('encash_days', 5, 1)->default(0);
            $table->unsignedBigInteger('leave_encashment')->default(0);
            $table->decimal('service_years', 5, 2)->default(0);
            $table->unsignedBigInteger('gratuity')->default(0);
            $table->unsignedBigInteger('notice_recovery')->default(0);
            $table->unsignedBigInteger('advance_recovery')->default(0);
            $table->unsignedBigInteger('other_deductions')->default(0);
            $table->unsignedBigInteger('tds')->default(0);
            $table->bigInteger('net');                        // can be negative when recoveries exceed dues
            $table->json('workings');
            $table->string('status', 10)->default('draft');   // draft | approved | paid
            $table->foreignUuid('prepared_by')->nullable()->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->string('voucher_id', 36)->nullable();
            $table->string('payment_voucher_id', 36)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'staff_member_id']);
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
