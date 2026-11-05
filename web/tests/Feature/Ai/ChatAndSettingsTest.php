<?php

namespace Tests\Feature\Ai;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Ai\AiException;
use Modules\Ai\Models\AiConversation;
use Modules\Ai\Models\AiMessage;
use Modules\Ai\Models\AiPrompt;
use Modules\Ai\Models\AiSetting;
use Modules\Ai\Services\AiServiceClient;
use Modules\Ai\Services\ChatService;
use Modules\Ai\Services\DocumentReader;
use Modules\Ai\Services\KnowledgeService;
use Modules\Ai\Services\PromptService;

/** Chat turns, credits, prompts, models, knowledge base and document reading against the AI service's HTTP contract. */
class ChatAndSettingsTest extends AiTestCase
{
    private function chat(): ChatService
    {
        return app(ChatService::class);
    }

    public function test_a_chat_turn_sends_history_prompt_and_only_permitted_tools_then_charges_credits(): void
    {
        $sent = null;
        $this->fakeChat('Fee collection this month is ₹5,000.00.', 1500, 500, function ($request) use (&$sent) {
            $sent = $request;
        });
        $this->actingAs($accountant = $this->member('accountant'), 'web');
        $this->asOwner();
        $this->in(fn () => app(PromptService::class)->save('accounting', 'You are the accounts assistant for Sunrise Public School. Be brief.', 'First version'));
        $this->actingAs($accountant, 'web');

        $msg = $this->in(fn () => $this->chat()->send('accounting', 'How much fee did we collect this month?'));

        $body = json_decode($sent->body(), true);
        $this->assertTrue(AiServiceClient::verifyRaw($sent->body(), $sent->header('X-QSA-Timestamp')[0], $sent->header('X-QSA-Signature')[0]), 'Requests to the AI service are signed');
        $this->assertSame(['accounting', 'openai', 'gpt-4.1-mini', 'accountant'], [$body['agent'], $body['provider'], $body['model'], $body['claims']['role']]);
        $this->assertSame('You are the accounts assistant for Sunrise Public School. Be brief.', $body['prompt']);
        $this->assertSame([['role' => 'user', 'content' => 'How much fee did we collect this month?']], $body['messages']);
        $names = array_column($body['tools'], 'name');
        $this->assertContains('fees.collections', $names);
        $this->assertNotContains('payroll.runs', $names);
        $this->assertStringEndsWith('/api/v1/ai/tools', $body['tool_base_url']);

        // The session token stops working when the turn ends.
        $this->tool('fees.collections', [], $body['session_token'])->assertForbidden();

        $this->assertSame(['assistant', 'Fee collection this month is ₹5,000.00.', 2, 1], [$msg->role, $msg->content, $msg->credits, $msg->prompt_version], json_encode($msg->toArray()));
        $this->assertSame(498, $this->credits());
        $this->assertSame(2, $this->in(fn () => AiMessage::where('conversation_id', $msg->conversation_id)->count()));

        // A follow-up carries the history.
        $this->in(fn () => $this->chat()->send('ignored', 'And last month?', AiConversation::find($msg->conversation_id)));
        $this->assertCount(3, json_decode($sent->body(), true)['messages']);
    }

    public function test_credits_models_and_switches(): void
    {
        $this->assertSame(1, ChatService::creditsFor('gpt-4.1-mini', 300));
        $this->assertSame(25, ChatService::creditsFor('gpt-4.1', 5000));
        $this->assertSame(4, ChatService::creditsFor('unknown-model', 2000));

        $this->fakeChat();
        $this->in(fn () => AiSetting::current()->update(['models' => ['school' => ['provider' => 'gemini', 'model' => 'gemini-2.5-pro']], 'agents_enabled' => ['school']]));
        $this->in(fn () => $this->chat()->send('school', 'How many students today?'));
        Http::assertSent(fn ($r) => $r['provider'] === 'gemini' && $r['model'] === 'gemini-2.5-pro');
        $this->assertThrows(fn () => $this->in(fn () => $this->chat()->send('payroll', 'Hi')), AiException::class, 'switched off');

        // No credits: refused before the AI service is called.
        $this->in(fn () => $this->chat()->charge($this->tenant, 10000, 'drain', 'Drain'));
        $this->assertSame(0, $this->credits());
        $this->assertThrows(fn () => $this->in(fn () => $this->chat()->send('school', 'Hi')), AiException::class, 'no AI credits');

        // Someone else's conversation can't be continued.
        $c = $this->in(fn () => AiConversation::firstOrFail());
        $this->actingAs($this->member('admin'), 'web');
        $this->assertThrows(fn () => $this->in(fn () => $this->chat()->send('school', 'Hi', $c)), \Illuminate\Auth\Access\AuthorizationException::class);
    }

    public function test_a_service_failure_is_recorded_and_costs_nothing(): void
    {
        Http::fake(['ai.test/*' => Http::response(['detail' => 'OPENAI_API_KEY is not set on the AI service.'], 422)]);
        $this->assertThrows(fn () => $this->in(fn () => $this->chat()->send('school', 'Hello')), AiException::class, 'OPENAI_API_KEY');
        $this->assertSame(['user', 'error'], $this->in(fn () => AiMessage::orderBy('created_at')->pluck('role')->all()));
        $this->assertSame(500, $this->credits());
        config(['ai.service_key' => '']);
        $this->assertThrows(fn () => $this->in(fn () => $this->chat()->send('school', 'Hello')), AiException::class, 'not configured');
    }

    public function test_prompts_are_versioned(): void
    {
        $svc = app(PromptService::class);
        $a = $this->in(fn () => $svc->save('payroll', 'Explain payslips simply, in two short paragraphs at most.'));
        $b = $this->in(fn () => $svc->save('payroll', 'Explain payslips simply and cite the statutory rule used.', 'Cite rules'));
        $this->assertSame([1, 2], [$a->version, $b->version]);
        $this->assertSame(2, $this->in(fn () => AiPrompt::active('payroll')->version));
        $this->in(fn () => $svc->activate($a->fresh()));
        $this->assertSame(1, $this->in(fn () => AiPrompt::active('payroll')->version));
        $this->in(fn () => $svc->useDefault('payroll'));
        $this->assertNull($this->in(fn () => AiPrompt::active('payroll')));
        $this->assertSame(2, $this->in(fn () => AiPrompt::where('agent', 'payroll')->count()), 'Old versions are kept');
        $this->actingAs($this->member('accountant'), 'web');
        $this->assertThrows(fn () => $this->in(fn () => $svc->save('payroll', str_repeat('x', 30))), \Illuminate\Auth\Access\AuthorizationException::class);
    }

    public function test_knowledge_is_indexed_by_the_ai_service_with_roles(): void
    {
        Http::fake(['ai.test/v1/ingest' => Http::response(['chunks' => 3]), 'ai.test/v1/forget' => Http::response(['deleted' => 3])]);
        $src = $this->in(fn () => app(KnowledgeService::class)->save(['title' => 'Fee refund policy', 'content' => 'Fees once paid are refundable only within 30 days of admission...', 'roles' => ['accountant', 'parent']]));
        $this->assertSame(3, $src->chunks);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/ingest') && $r['roles'] === ['accountant', 'parent'] && $r['claims']['tenant_id'] === $this->tenant->id);
        $this->in(fn () => app(KnowledgeService::class)->delete($src));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/forget') && $r['source_id'] === $src->id);
    }

    public function test_document_reading_is_a_proposal_to_review(): void
    {
        Storage::fake('local');
        Http::fake(['ai.test/v1/extract' => Http::response(['fields' => ['vendor_name' => 'Bright Print Works', 'invoice_number' => 'BPW/2291', 'total' => 50400],
            'missing' => ['vendor_gstin'], 'warnings' => []])]);
        $doc = $this->in(fn () => app(DocumentReader::class)->upload('vendor_invoice', UploadedFile::fake()->image('bill.jpg')));
        $this->assertSame(['extracted', 'BPW/2291'], [$doc->status, $doc->extracted['fields']['invoice_number']]);
        $this->assertSame(495, $this->credits());
        Http::assertSent(fn ($r) => $r['doc_type'] === 'vendor_invoice' && $r['mime'] === 'image/jpeg' && base64_decode($r['data_base64'], true) !== false);

        $doc = $this->in(fn () => app(DocumentReader::class)->review($doc, ['vendor_gstin' => '09AABCB1234K1Z5']));
        $this->assertSame(['reviewed', '09AABCB1234K1Z5'], [$doc->status, $doc->extracted['fields']['vendor_gstin']]);
        $this->assertThrows(fn () => $this->in(fn () => app(DocumentReader::class)->upload('vendor_invoice', UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload'))), AiException::class, 'PDF');
    }
}
