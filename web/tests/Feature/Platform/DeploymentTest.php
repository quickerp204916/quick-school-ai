<?php

namespace Tests\Feature\Platform;

class DeploymentTest extends PlatformTestCase
{
    public function test_tls_check_allows_platform_host_and_live_institutions_only(): void
    {
        config(['platform.base_domain' => 'quickschool.app']);
        $tenant = $this->provision();

        $this->get('/internal/tls-check?domain=quickschool.app')->assertOk();
        $this->get('/internal/tls-check?domain='.$tenant->code.'.quickschool.app')->assertOk();
        $this->get('/internal/tls-check?domain=nosuchschool.quickschool.app')->assertNotFound();
        $this->get('/internal/tls-check?domain=a.b.quickschool.app')->assertNotFound();
        $this->get('/internal/tls-check?domain=evil.example.com')->assertNotFound();
        $this->get('/internal/tls-check')->assertNotFound();

        $tenant->forceFill(['status' => 'archived'])->save();
        $this->get('/internal/tls-check?domain='.$tenant->code.'.quickschool.app')->assertNotFound();
    }

    public function test_health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}
