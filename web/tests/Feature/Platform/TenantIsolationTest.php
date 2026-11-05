<?php

namespace Tests\Feature\Platform;

use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantTriggers;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Identity\Models\TenantMembership;
use Modules\Identity\Models\User;
use Modules\Institution\Models\Branch;

/** Proves the database guards hold: triggers on MySQL, Row-Level Security on PostgreSQL. */
class TenantIsolationTest extends PlatformTestCase
{
    public function test_every_tenant_table_has_its_triggers(): void
    {
        $this->requireDatabaseGuard();
        $this->assertSame([], TenantTriggers::missing(['branches', 'academic_sessions', 'tenant_memberships', 'audit_logs']));
    }

    public function test_trigger_rejects_a_row_for_another_tenant_even_with_raw_sql(): void
    {
        $this->requireDatabaseGuard();
        $a = $this->provision();
        $b = $this->provision();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($this->guardMessage());

        TenantContext::run($a, fn () => DB::table('branches')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $b->id, 'code' => 'HACK', 'name' => 'Injected', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_trigger_rejects_writes_with_no_tenant_context(): void
    {
        $this->requireDatabaseGuard();
        $a = $this->provision();

        $this->expectException(QueryException::class);
        DB::table('branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a->id, 'code' => 'X', 'name' => 'X', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_trigger_rejects_moving_a_row_to_another_tenant(): void
    {
        $this->requireDatabaseGuard();
        $a = $this->provision();
        $b = $this->provision();

        $this->expectException(QueryException::class);
        TenantContext::run($a, fn () => DB::table('branches')->where('tenant_id', $a->id)->update(['tenant_id' => $b->id]));
    }

    public function test_queries_only_see_the_current_tenant(): void
    {
        $a = $this->provision();
        $b = $this->provision();
        TenantContext::run($b, fn () => Branch::create(['code' => 'EAST', 'name' => 'East campus']));

        $this->assertSame(1, TenantContext::run($a, fn () => Branch::count()));
        $this->assertSame(2, TenantContext::run($b, fn () => Branch::count()));
        $this->assertSame(3, TenantContext::acrossTenants(fn () => Branch::withoutGlobalScope('tenant')->count()));
    }

    public function test_model_queries_fail_closed_without_a_tenant(): void
    {
        $this->provision();

        $this->expectException(LogicException::class);
        Branch::count();
    }

    public function test_composite_foreign_key_blocks_another_tenants_branch(): void
    {
        $a = $this->provision();
        $b = $this->provision();
        $branchOfB = TenantContext::run($b, fn () => Branch::first());
        $user = User::create(['name' => 'Teacher', 'email' => 'teacher@a.test', 'password' => 'secret-secret']);

        $this->expectException(QueryException::class);
        TenantContext::run($a, fn () => TenantMembership::create(['user_id' => $user->id, 'role' => 'teacher', 'default_branch_id' => $branchOfB->id]));
    }

    public function test_context_is_restored_after_nested_runs_and_errors(): void
    {
        $this->requireDatabaseGuard();
        $a = $this->provision();
        $b = $this->provision();

        TenantContext::run($a, function () use ($a, $b) {
            try {
                TenantContext::run($b, fn () => throw new \RuntimeException('boom'));
            } catch (\RuntimeException) {
            }
            $this->assertSame($a->id, TenantContext::id());
            $this->assertSame($a->id, TenantContext::databaseValue());
        });

        $this->assertNull(TenantContext::id());
        $this->assertNull(TenantContext::databaseValue());
    }

    public function test_raw_sql_reads_only_see_the_current_tenant_on_postgresql(): void
    {
        $this->requirePostgres();
        $a = $this->provision();
        $b = $this->provision();

        $codes = TenantContext::run($a, fn () => DB::table('branches')->pluck('tenant_id')->unique()->values()->all());
        $this->assertSame([$a->id], $codes);
        $this->assertSame(0, DB::table('branches')->count(), 'No tenant set: RLS hides every row');
        $this->assertSame(2, TenantContext::acrossTenants(fn () => DB::table('branches')->count()));
    }

    public function test_cross_tenant_mode_is_read_only_on_postgresql(): void
    {
        $this->requirePostgres();
        $a = $this->provision();
        $b = $this->provision();

        $moved = TenantContext::run($a, fn () => TenantContext::acrossTenants(
            fn () => DB::table('branches')->where('tenant_id', $b->id)->update(['name' => 'Renamed'])
        ));

        $this->assertSame(0, $moved, 'Updates never reach another tenant, even in cross-tenant read mode');
        $this->assertSame(0, TenantContext::run($b, fn () => Branch::where('name', 'Renamed')->count()));
    }

    public function test_guard_also_covers_academic_tables(): void
    {
        $this->requireDatabaseGuard();
        $this->assertSame([], TenantTriggers::missing([
            'institution_profiles', 'tenant_counters', 'academic_terms', 'staff_members', 'grading_schemes',
            'grade_bands', 'programs', 'grade_levels', 'subjects', 'level_subjects', 'sections', 'section_subjects',
            'guardians', 'students', 'student_guardians', 'enrollments', 'admission_applications', 'student_transfers',
            'promotion_runs', 'holidays', 'bell_periods', 'timetable_entries', 'attendance_registers',
            'attendance_entries', 'exams', 'exam_papers', 'marks', 'report_cards', 'homework', 'homework_submissions',
            'certificate_templates', 'certificates', 'student_documents',
            'accounting_settings', 'financial_years', 'account_groups', 'cost_centres', 'tds_sections', 'tax_rates', 'ledgers', 'vouchers',
            'ledger_entries', 'invoices', 'invoice_lines', 'bank_statement_lines', 'budgets', 'fixed_assets', 'depreciation_postings',
            'ai_settings', 'ai_prompts', 'ai_conversations', 'ai_messages', 'ai_action_requests', 'ai_documents', 'ai_knowledge_sources', 'ai_call_logs',
            'fee_settings', 'fee_heads', 'fee_structures', 'fee_structure_items', 'fee_concessions', 'student_fee_plans', 'student_fee_concessions',
            'fee_invoices', 'fee_invoice_lines', 'fee_payment_orders', 'fee_payments', 'fee_payment_allocations', 'fee_refunds', 'fee_reminders', 'fee_webhook_events',
            'hr_departments', 'hr_designations', 'hr_shifts', 'employee_profiles', 'employee_documents', 'hr_attendance', 'hr_leave_types', 'hr_leave_allocations',
            'hr_leave_requests', 'salary_components', 'salary_structures', 'statutory_rules', 'payroll_runs', 'payslips', 'payroll_inputs', 'fnf_settlements',
        ]));
    }

    private function guardMessage(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'row-level security' : 'Tenant mismatch';
    }
}
