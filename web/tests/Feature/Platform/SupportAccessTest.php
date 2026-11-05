<?php

namespace Tests\Feature\Platform;

use App\Core\Audit\AuditLog;
use App\Core\Tenancy\TenantContext;
use Modules\Platform\Exceptions\PlatformRuleException;
use Modules\Platform\Services\AiCredits;
use Modules\Platform\Services\SupportAccess;

class SupportAccessTest extends PlatformTestCase
{
    public function test_session_is_logged_in_the_institutions_own_audit_log(): void
    {
        $tenant = $this->provision();
        $support = $this->admin('support');

        $session = app(SupportAccess::class)->start($support, $tenant, 'Ticket 1042: receipts not printing', 30);

        $this->assertTrue($session->isActive());
        $log = TenantContext::run($tenant, fn () => AuditLog::where('action', 'support.session_started')->first());
        $this->assertNotNull($log);
        $this->assertSame('platform_admin', $log->actor_type);
        $this->assertSame('Ticket 1042: receipts not printing', $log->changes['reason']);

        $this->travel(31)->minutes();
        $this->assertFalse($session->fresh()->isActive());
    }

    public function test_rules_on_duration_reason_and_who_may_end_it(): void
    {
        $tenant = $this->provision();
        $support = $this->admin('support');
        $access = app(SupportAccess::class);

        // Reason too short; then a valid reason but longer than the 120-minute maximum.
        foreach ([[$support, 'slow', 30], [$support, 'Ticket 1042: long enough reason', 240]] as [$a, $reason, $mins]) {
            try {
                $access->start($a, $tenant, $reason, $mins);
                $this->fail('Session should have been refused.');
            } catch (PlatformRuleException) {
            }
        }

        $session = $access->start($support, $tenant, 'Ticket 1042: long enough reason', 30);

        try {
            $access->end($session, $this->admin('support'));
            $this->fail('Another support user ended the session.');
        } catch (PlatformRuleException) {
        }

        $access->end($session, $this->admin('super_admin'));
        $this->assertNotNull($session->fresh()->ended_at);
    }

    public function test_ai_credit_consumption_stops_at_zero(): void
    {
        $tenant = $this->provision();
        $credits = app(AiCredits::class);
        $credits->grant($tenant, 100, 'top_up', 'Trial top-up');

        $credits->consume($tenant, 60, 'chat-1');
        $this->expectException(PlatformRuleException::class);
        $credits->consume($tenant, 50, 'chat-2');
    }
}
