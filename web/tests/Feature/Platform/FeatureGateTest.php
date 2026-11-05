<?php

namespace Tests\Feature\Platform;

use InvalidArgumentException;
use Modules\Platform\Models\TenantFeatureOverride;
use Modules\Platform\Services\FeatureGate;

class FeatureGateTest extends PlatformTestCase
{
    public function test_plan_values_apply(): void
    {
        $tenant = $this->provision();   // Starter
        $gate = FeatureGate::for($tenant);

        $this->assertSame(300, $gate->limit('students_max'));
        $this->assertFalse($gate->enabled('transport'));
        $this->assertTrue($gate->enabled('whatsapp'));
        $this->assertTrue($gate->allowsAnother('students_max', 299));
        $this->assertFalse($gate->allowsAnother('students_max', 300));
    }

    public function test_override_wins_until_it_expires(): void
    {
        $tenant = $this->provision();
        TenantFeatureOverride::create(['tenant_id' => $tenant->id, 'feature' => 'transport', 'enabled' => true, 'reason' => 'Pilot', 'expires_at' => now()->addDays(10)]);
        TenantFeatureOverride::create(['tenant_id' => $tenant->id, 'feature' => 'students_max', 'enabled' => true, 'limit_value' => null, 'reason' => 'Unlimited for launch']);

        $gate = FeatureGate::for($tenant);
        $this->assertTrue($gate->enabled('transport'));
        $this->assertNull($gate->limit('students_max'));
        $this->assertSame('override', $gate->all()['transport']['source']);

        $this->travel(11)->days();
        $this->assertFalse(FeatureGate::for($tenant)->enabled('transport'));
    }

    public function test_override_via_console(): void
    {
        $tenant = $this->provision();

        $this->signIn()->post(route('platform.tenants.overrides.store', $tenant), [
            'feature' => 'branches_max', 'value' => '3', 'reason' => 'Second campus opening',
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, FeatureGate::for($tenant)->limit('branches_max'));
    }

    public function test_unknown_feature_is_an_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FeatureGate::for($this->provision())->enabled('teleportation');
    }
}
