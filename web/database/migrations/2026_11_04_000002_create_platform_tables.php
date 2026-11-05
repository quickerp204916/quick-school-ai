<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Platform-owned tables used by the Super Admin module. Not tenant-scoped. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('email', 191)->unique();
            $table->string('password');
            $table->string('role', 20);
            $table->boolean('is_active')->default(true);
            $table->text('mfa_secret')->nullable();
            $table->timestamp('mfa_confirmed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 200);
            $table->string('legal_name', 200)->nullable();
            $table->string('type', 20);
            $table->string('status', 20)->index();
            $table->string('contact_name', 150);
            $table->string('contact_email', 191);
            $table->string('contact_phone', 20)->nullable();
            $table->string('billing_gstin', 15)->nullable();
            $table->char('billing_state_code', 2)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('timezone', 40)->default('Asia/Kolkata');
            $table->string('locale', 10)->default('en_IN');
            $table->json('branding')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_type', 20)->nullable();   // manual | billing
            $table->string('suspension_reason', 500)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('domain', 191)->unique();
            $table->string('type', 20);                          // subdomain | custom
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->decimal('price_monthly', 12, 2);
            $table->decimal('price_yearly', 12, 2);
            $table->char('currency', 3)->default('INR');
            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature', 60);
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('limit_value')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'feature']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('plan_id')->constrained('plans');
            $table->string('status', 20);
            $table->string('billing_cycle', 10);                 // monthly | yearly
            $table->decimal('price', 12, 2);                     // price locked at signup or plan change
            $table->date('trial_ends_at')->nullable();
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->date('grace_until')->nullable();             // set when staff lift a billing suspension by hand
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'current_period_end']);
        });

        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 40)->unique();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('status', 10)->index();               // open | paid | void
            $table->char('currency', 3)->default('INR');
            $table->string('description', 300);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('cgst', 12, 2)->default(0);
            $table->decimal('sgst', 12, 2)->default(0);
            $table->decimal('igst', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->string('seller_gstin', 15)->nullable();
            $table->string('buyer_gstin', 15)->nullable();
            $table->string('buyer_name', 200);
            $table->char('place_of_supply', 2)->nullable();
            $table->string('sac', 10)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            // One invoice per subscription period, so re-running billing never bills twice.
            $table->unique(['subscription_id', 'period_start']);
        });

        Schema::create('platform_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('platform_invoices');
            $table->decimal('amount', 12, 2);
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->uuid('recorded_by')->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('tenant_feature_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('feature', 60);
            $table->boolean('enabled')->nullable();
            $table->unsignedInteger('limit_value')->nullable();
            $table->string('reason', 300);
            $table->timestamp('expires_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'feature']);
        });

        Schema::create('ai_credit_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->integer('amount');
            $table->string('kind', 20);
            $table->string('reference', 100)->nullable();
            $table->string('note', 300)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'created_at']);
            // Monthly grants use reference YYYY-MM, so a month is granted only once.
            $table->unique(['tenant_id', 'kind', 'reference']);
        });

        Schema::create('support_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('platform_admin_id')->constrained('platform_admins');
            $table->string('reason', 500);
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'ended_at']);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 150);
            $table->text('body');
            $table->string('level', 10);
            $table->string('audience', 10);                      // all | plan
            $table->foreignUuid('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('platform_admin_id')->nullable()->index();
            $table->string('action', 80)->index();
            $table->string('subject_type', 80)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->uuid('tenant_id')->nullable()->index();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('via', 10)->default('web');           // web | api | cli
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('platform_api_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('platform_admin_id')->constrained('platform_admins');
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid('platform_api_token_id');
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('status_code');
            $table->json('response');
            $table->timestamp('created_at')->nullable();
            $table->unique(['platform_api_token_id', 'idempotency_key'], 'platform_idem_unique');
        });
    }

    public function down(): void
    {
        foreach ([
            'platform_idempotency_keys', 'platform_api_tokens', 'platform_audit_logs', 'announcements', 'support_sessions',
            'ai_credit_entries', 'tenant_feature_overrides', 'platform_payments', 'platform_invoices', 'subscriptions',
            'plan_features', 'plans', 'tenant_domains', 'tenants', 'platform_admins',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
