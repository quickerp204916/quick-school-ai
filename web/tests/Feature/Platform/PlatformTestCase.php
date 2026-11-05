<?php

namespace Tests\Feature\Platform;

use Carbon\Carbon;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\PlatformAdmin;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantProvisioner;
use Tests\TestCase;

abstract class PlatformTestCase extends TestCase
{
    use RefreshDatabase;

    private int $adminCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-04 10:00:00'));
        $this->seed(PlanSeeder::class);
    }

    protected function admin(string $role = 'super_admin', array $attrs = []): PlatformAdmin
    {
        $this->adminCount++;

        return PlatformAdmin::create($attrs + [
            'name' => ucfirst($role).' '.$this->adminCount,
            'email' => "{$role}{$this->adminCount}@quickerp.test",
            'password' => 'Correct-Horse-42',
            'role' => $role,
            'is_active' => true,
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_confirmed_at' => now(),
        ]);
    }

    /** Signed in with two-factor completed. */
    protected function signIn(?PlatformAdmin $admin = null): static
    {
        return $this->actingAs($admin ?? $this->admin(), 'platform')->withSession(['platform.mfa_passed' => true]);
    }

    protected function plan(string $code = 'starter'): Plan
    {
        return Plan::where('code', $code)->firstOrFail();
    }

    protected function provision(array $overrides = [], ?PlatformAdmin $by = null): Tenant
    {
        static $n = 0;
        $n++;

        return app(TenantProvisioner::class)->provision($overrides + [
            'code' => "school-{$n}",
            'name' => "Sunrise Public School {$n}",
            'type' => 'school',
            'plan_id' => $this->plan()->id,
            'billing_cycle' => 'monthly',
            'owner_name' => 'Sunita Rao',
            'owner_email' => "owner{$n}@sunrise.test",
            'billing_state_code' => '09',
            'city' => 'Noida',
        ], $by);
    }
}
