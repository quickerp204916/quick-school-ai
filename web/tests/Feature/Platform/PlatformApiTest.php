<?php

namespace Tests\Feature\Platform;

use Modules\Platform\Models\PlatformApiToken;
use Modules\Platform\Models\Tenant;

class PlatformApiTest extends PlatformTestCase
{
    private function token(array $abilities): string
    {
        [, $plain] = PlatformApiToken::issue($this->admin(), 'Test', $abilities);

        return $plain;
    }

    private function body(array $overrides = []): array
    {
        return $overrides + [
            'code' => 'api-school', 'name' => 'API School', 'type' => 'school', 'plan_id' => $this->plan()->id, 'billing_cycle' => 'monthly',
            'owner_name' => 'Meera Iyer', 'owner_email' => 'meera@api.test', 'billing_state_code' => '29',
        ];
    }

    public function test_requests_without_a_valid_token_are_refused(): void
    {
        $this->getJson('/api/v1/platform/tenants')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->withToken('qsa_wrong')->getJson('/api/v1/platform/tenants')->assertStatus(401)->assertJsonPath('error.code', 'invalid_token');
        // Token is checked before the tenant lookup, so unknown IDs reveal nothing.
        $this->getJson('/api/v1/platform/tenants/00000000-0000-0000-0000-000000000000')->assertStatus(401);
    }

    public function test_read_token_lists_and_shows_tenants(): void
    {
        $tenant = $this->provision();
        $token = $this->token(['read']);

        $this->withToken($token)->getJson('/api/v1/platform/tenants')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', $tenant->code);
        $this->withToken($token)->getJson('/api/v1/platform/tenants/'.$tenant->id)->assertOk()
            ->assertJsonPath('data.features.students_max.value', 300);
        $this->withToken($token)->getJson('/api/v1/platform/plans')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_read_token_cannot_write(): void
    {
        $this->withToken($this->token(['read']))->withHeader('Idempotency-Key', 'k1')
            ->postJson('/api/v1/platform/tenants', $this->body())->assertStatus(403)->assertJsonPath('error.code', 'insufficient_scope');
    }

    public function test_create_tenant_is_idempotent(): void
    {
        $token = $this->token(['read', 'write']);

        $this->withToken($token)->postJson('/api/v1/platform/tenants', $this->body())->assertStatus(400)
            ->assertJsonPath('error.code', 'idempotency_key_required');

        $first = $this->withToken($token)->withHeader('Idempotency-Key', 'signup-123')->postJson('/api/v1/platform/tenants', $this->body())->assertStatus(201);
        $second = $this->withToken($token)->withHeader('Idempotency-Key', 'signup-123')->postJson('/api/v1/platform/tenants', $this->body())->assertStatus(201);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, Tenant::count());

        $this->withToken($token)->withHeader('Idempotency-Key', 'signup-123')
            ->postJson('/api/v1/platform/tenants', $this->body(['name' => 'Different']))->assertStatus(409);
    }

    public function test_validation_errors_are_json(): void
    {
        $this->withToken($this->token(['write']))->withHeader('Idempotency-Key', 'bad-1')
            ->postJson('/api/v1/platform/tenants', $this->body(['code' => 'www', 'owner_email' => 'nope']))
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['code', 'owner_email']]]);
    }

    public function test_revoked_token_stops_working(): void
    {
        [$model, $plain] = PlatformApiToken::issue($this->admin(), 'Old', ['read']);
        $model->update(['revoked_at' => now()]);

        $this->withToken($plain)->getJson('/api/v1/platform/tenants')->assertStatus(401);
    }

    public function test_record_payment_over_the_api(): void
    {
        $plan = $this->plan();
        $plan->update(['trial_days' => 0]);
        $invoice = $this->provision(['plan_id' => $plan->id])->invoices()->first();

        $this->withToken($this->token(['write']))->withHeader('Idempotency-Key', 'pay-1')
            ->postJson("/api/v1/platform/invoices/{$invoice->id}/payments", ['amount' => '3538.82', 'method' => 'gateway', 'reference' => 'pay_ABC', 'paid_on' => '2026-11-04'])
            ->assertStatus(201)->assertJsonPath('data.invoice.status', 'paid')->assertJsonPath('data.invoice.balance', '0.00');
    }
}
