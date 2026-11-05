<?php

namespace Tests\Feature\Platform;

use App\Core\Audit\AuditLog;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Modules\Identity\Models\TenantMembership;
use Modules\Institution\Models\AcademicSession;
use Modules\Institution\Models\Branch;
use Modules\Platform\Exceptions\PlatformRuleException;
use Modules\Platform\Mail\OwnerInvitation;
use Modules\Platform\Models\PlatformAuditLog;
use Modules\Platform\Models\Tenant;

class TenantProvisioningTest extends PlatformTestCase
{
    public function test_provisioning_creates_the_whole_institution(): void
    {
        $admin = $this->admin();
        $tenant = $this->provision(['code' => 'sunrise-noida', 'name' => 'Sunrise Public School'], $admin);

        $this->assertSame('trial', $tenant->status);
        $this->assertSame('sunrise-noida.quickschool.app', $tenant->primaryDomain());
        $this->assertSame('trialing', $tenant->subscription->status);
        $this->assertSame('2026-11-18', $tenant->subscription->trial_ends_at->toDateString());
        $this->assertSame('2999.00', $tenant->subscription->price);

        TenantContext::run($tenant, function () {
            $this->assertSame(1, Branch::count());
            $this->assertTrue(Branch::first()->is_primary);
            $this->assertSame('2026-27', AcademicSession::where('is_current', true)->value('name'));
            $owner = TenantMembership::with('user')->where('role', 'owner')->first();
            $this->assertNotNull($owner);
            $this->assertSame(Branch::first()->id, $owner->default_branch_id);
            $this->assertTrue(AuditLog::where('action', 'tenant.provisioned')->exists());
        });

        $this->assertTrue(PlatformAuditLog::where('action', 'tenant.provisioned')->where('tenant_id', $tenant->id)->where('platform_admin_id', $admin->id)->exists());
        $ownerEmail = $tenant->contact_email;
        Mail::assertSent(OwnerInvitation::class, fn (OwnerInvitation $m) => $m->hasTo($ownerEmail)
            && str_starts_with($m->link, 'https://sunrise-noida.quickschool.app/set-password?token='));
    }

    public function test_plan_without_trial_is_billed_at_once(): void
    {
        $plan = $this->plan('starter');
        $plan->update(['trial_days' => 0]);

        $tenant = $this->provision(['plan_id' => $plan->id]);

        $this->assertSame('active', $tenant->status);
        $this->assertSame('active', $tenant->subscription->status);
        $this->assertSame(1, $tenant->invoices()->count());
    }

    public function test_reserved_and_duplicate_codes_are_refused(): void
    {
        $this->provision(['code' => 'green-valley']);

        $this->expectException(PlatformRuleException::class);
        $this->provision(['code' => 'green-valley', 'owner_email' => 'other@school.test']);
    }

    public function test_console_onboarding_form_validates_input(): void
    {
        $this->signIn()->post(route('platform.tenants.store'), [
            'code' => 'admin', 'name' => 'X', 'type' => 'school', 'plan_id' => $this->plan()->id, 'billing_cycle' => 'monthly',
            'owner_name' => 'A', 'owner_email' => 'not-an-email', 'billing_gstin' => '27AAACQ1234A1Z5', 'billing_state_code' => '09',
        ])->assertSessionHasErrors(['code', 'owner_email', 'billing_state_code']);

        $this->assertSame(0, Tenant::count());
    }

    public function test_console_onboarding_creates_and_redirects(): void
    {
        $response = $this->signIn()->post(route('platform.tenants.store'), [
            'code' => 'Delhi-Model-School', 'name' => 'Delhi Model School', 'type' => 'school', 'plan_id' => $this->plan('growth')->id,
            'billing_cycle' => 'yearly', 'owner_name' => 'Anil Mehra', 'owner_email' => 'Anil@DMS.test', 'billing_gstin' => '07aaacd1234a1z5',
            'billing_state_code' => '07',
        ]);

        $tenant = Tenant::where('code', 'delhi-model-school')->firstOrFail();
        $response->assertRedirect(route('platform.tenants.show', $tenant));
        $this->assertSame('07AAACD1234A1Z5', $tenant->billing_gstin);
        $this->assertSame('anil@dms.test', $tenant->contact_email);
        $this->assertSame('79990.00', $tenant->subscription->price);
    }
}
