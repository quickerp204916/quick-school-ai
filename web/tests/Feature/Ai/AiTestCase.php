<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Http;
use Modules\Ai\Services\AiServiceClient;
use Modules\Ai\Services\AiSessions;
use Modules\Identity\Models\User;
use Modules\Platform\Services\AiCredits;
use Tests\Feature\Fees\FeeTestCase;

/**
 * An institution with books, fees and HR, 500 AI credits and the AI service at http://ai.test.
 * The AI service itself is faked with Http::fake in these tests only; production calls the real service.
 */
abstract class AiTestCase extends FeeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.service_url' => 'http://ai.test', 'ai.service_key' => 'test-shared-key']);
        app(AiCredits::class)->grantOnce($this->tenant, 500, 'top_up', 'test-credits', 'Test credits');
    }

    protected function credits(): int
    {
        return app(AiCredits::class)->balance($this->tenant->fresh());
    }

    /** A chat session token as the AI service would receive it. */
    protected function chatSession(User $user, string $agent, ?array $tools = null, ?string $conversationId = null): string
    {
        return $this->in(function () use ($user, $agent, $tools, $conversationId) {
            $this->actingAs($user, 'web');
            $tools ??= array_column(app(\Modules\Ai\Tools\ToolRegistry::class)->available($agent), 'name');

            return app(AiSessions::class)->issue($this->tenant->id, $user->id, $agent, $tools, $conversationId);
        });
    }

    /** A signed tool call from the AI service. */
    protected function tool(string $name, array $args, ?string $token, bool $sign = true, ?\Modules\Platform\Models\Tenant $tenant = null)
    {
        $body = json_encode(['arguments' => (object) $args]);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + ($token ? ['HTTP_X_AI_SESSION' => $token] : []);
        if ($sign) {
            foreach (AiServiceClient::sign($body) as $k => $v) {
                $headers['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
            }
        }

        return $this->call('POST', $this->api("ai/tools/{$name}", $tenant), [], [], [], $headers, $body);
    }

    /** Fakes the AI service's chat endpoint with a fixed reply and usage. */
    protected function fakeChat(string $reply = 'Done.', int $in = 1500, int $out = 500, ?callable $inspect = null): void
    {
        Http::fake(['ai.test/v1/chat' => function ($request) use ($reply, $in, $out, $inspect) {
            $extra = $inspect ? ($inspect($request) ?? []) : [];

            return Http::response(['reply' => $reply, 'tool_calls' => $extra['tool_calls'] ?? [], 'approvals' => $extra['approvals'] ?? [],
                'usage' => ['input_tokens' => $in, 'output_tokens' => $out], 'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'steps' => 1]);
        }]);
    }
}
