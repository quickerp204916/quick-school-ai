<?php

namespace Tests\Feature\Ai;

use Modules\Ai\Services\AnomalyDetector;

/** Rule-based anomaly checks, the AI screens and the mobile API. */
class AnomaliesAndScreensTest extends AiTestCase
{
    public function test_unusual_entries_are_found(): void
    {
        $this->postVoucher('payment', [['REPAIRS', '18000'], ['BANK', '', '18000']], '2026-10-05');
        $this->postVoucher('payment', [['REPAIRS', '18000'], ['BANK', '', '18000']], '2026-10-08');       // same party and amount within a week
        $this->postVoucher('payment', [['RENT', '15000'], ['BANK', '', '15000']], '2026-10-20');
        $this->postVoucher('payment', [['RENT', '14000'], ['BANK', '', '14000']], '2026-10-20');          // split under the ₹25,000 limit
        $this->postVoucher('payment', [['STATIONERY', '12000'], ['CASH', '', '12000']], '2026-10-22');    // cash above ₹10,000
        $this->postVoucher('payment', [['ELECTRICITY', '900'], ['BANK', '', '900']], '2026-10-23');       // ordinary

        $rules = collect($this->in(fn () => app(AnomalyDetector::class)->scan('2026-04-01', '2026-11-04')))->pluck('rule')->all();
        $this->assertContains('duplicate_payment', $rules);
        $this->assertContains('split_below_limit', $rules);
        $this->assertContains('cash_over_limit', $rules);
        $this->assertCount(3, $rules, 'The ordinary payment is not flagged: '.implode(', ', $rules));

        $r = $this->tool('accounting.anomalies', ['from' => '2026-04-01'], $this->chatSession($this->member('accountant'), 'accounting'))->assertOk();
        $this->assertSame(3, $r->json('count'));
        $this->assertSame('high', $r->json('findings.0.severity'));
    }

    public function test_screens_render(): void
    {
        $this->fakeChat('Hello from the assistant.');
        $this->post($this->at('ai/chat'), ['agent' => 'school', 'message' => 'Good morning'])->assertRedirect();
        $c = $this->in(fn () => \Modules\Ai\Models\AiConversation::firstOrFail());
        foreach (['ai' => 'School Assistant', 'ai/chat?c='.$c->id => 'Hello from the assistant.', 'ai/approvals' => 'Nothing is waiting', 'ai/documents' => 'Upload a document',
            'ai/anomalies' => 'Nothing unusual', 'ai/calls' => 'No calls logged', 'ai/settings' => 'Voice Calling Assistant', 'ai/prompts' => 'built-in prompt',
            'ai/knowledge' => 'Knowledge base', 'ai/usage' => 'Credits left'] as $path => $text) {
            $this->get($this->at($path))->assertOk()->assertSee($text, false);
        }
        $this->actingAs($this->member('teacher'), 'web');
        $this->get($this->at('ai/settings'))->assertForbidden();
        $this->get($this->at('ai/anomalies'))->assertForbidden();
        $this->get($this->at('ai/chat?c='.$c->id))->assertNotFound();
    }

    public function test_mobile_api(): void
    {
        $this->fakeChat('You have ₹0.00 due.');
        $user = $this->member('accountant');
        $user->forceFill(['password' => 'Secret-pass-123'])->save();
        $token = $this->postJson($this->api('auth/token'), ['email' => $user->email, 'password' => 'Secret-pass-123', 'device_name' => 'Phone'])->json('token');

        $this->withToken($token)->getJson($this->api('ai/agents'))->assertOk()->assertJsonFragment(['key' => 'accounting']);
        $m = $this->withToken($token)->postJson($this->api('ai/chat'), ['agent' => 'accounting', 'message' => 'What is due?'])->assertCreated();
        $this->withToken($token)->getJson($this->api('ai/conversations/'.$m->json('data.conversation_id')))->assertOk()->assertJsonPath('data.messages.1.content', 'You have ₹0.00 due.');
        $this->withToken($token)->getJson($this->api('ai/approvals'))->assertOk()->assertJsonPath('data', []);
    }
}
