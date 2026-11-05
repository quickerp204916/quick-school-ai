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

/** Proves the MySQL substitute for Row-Level Security holds. */
class TenantIsolationTest extends PlatformTestCase
{
    public function test_every_tenant_table_has_its_triggers(): void
    {
        $this->assertSame([], TenantTriggers::missing(['branches', 'academic_sessions', 'tenant_memberships', 'audit_logs']));
    }

    public function test_trigger_rejects_a_row_for_another_tenant_even_with_raw_sql(): void
    {
        $a = $this->provision();
        $b = $this->provision();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Tenant mismatch');

        TenantContext::run($a, fn () => DB::table('branches')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $b->id, 'code' => 'HACK', 'name' => 'Injected', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_trigger_rejects_writes_with_no_tenant_context(): void
    {
        $a = $this->provision();

        $this->expectException(QueryException::class);
        DB::table('branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a->id, 'code' => 'X', 'name' => 'X', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_trigger_rejects_moving_a_row_to_another_tenant(): void
    {
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
        $this->assertSame(3, Branch::withoutGlobalScope('tenant')->count());
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
        $a = $this->provision();
        $b = $this->provision();

        TenantContext::run($a, function () use ($a, $b) {
            try {
                TenantContext::run($b, fn () => throw new \RuntimeException('boom'));
            } catch (\RuntimeException) {
            }
            $this->assertSame($a->id, TenantContext::id());
            $this->assertSame($a->id, DB::selectOne('SELECT @app_tenant_id AS t')->t);
        });

        $this->assertNull(TenantContext::id());
        $this->assertNull(DB::selectOne('SELECT @app_tenant_id AS t')->t);
    }
}
