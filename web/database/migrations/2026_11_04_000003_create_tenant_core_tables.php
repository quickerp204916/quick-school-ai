<?php

use App\Core\Tenancy\TenantTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First tenant-scoped tables, created when an institution is provisioned.
 * Each has tenant_id, a unique (tenant_id, id) key for composite foreign keys,
 * and the tenant write triggers.
 */
return new class extends Migration
{
    public const TENANT_TABLES = ['branches', 'academic_sessions', 'tenant_memberships', 'audit_logs'];

    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 20);
            $table->string('name', 150);
            $table->boolean('is_primary')->default(false);
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('state_code', 2)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('academic_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('name', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('role', 30);
            $table->string('status', 20)->default('active');
            $table->uuid('default_branch_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'user_id']);
            // Tenant-scoped foreign key: the branch must belong to the same institution.
            $table->foreign(['tenant_id', 'default_branch_id'], 'memberships_branch_same_tenant')
                ->references(['tenant_id', 'id'])->on('branches');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('actor_type', 20);                    // user | platform_admin | system
            $table->string('actor_id', 64)->nullable();
            $table->string('action', 80);
            $table->string('subject_type', 80)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'created_at']);
        });

        foreach (self::TENANT_TABLES as $table) {
            TenantTriggers::create($table);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            TenantTriggers::drop($table);
            Schema::dropIfExists($table);
        }
    }
};
