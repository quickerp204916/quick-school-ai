<?php

namespace Modules\Ai\Services;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\Academic\Support\CurrentMember;
use Modules\Ai\AiException;
use Modules\Ai\Models\AiActionRequest;
use Modules\Ai\Models\AiConversation;
use Modules\Ai\Models\AiMessage;
use Modules\Ai\Models\AiPrompt;
use Modules\Ai\Models\AiSetting;
use Modules\Ai\Tools\ToolRegistry;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\AiCredits;

/**
 * One chat turn: stores the question, sends it with the history, the agent's active prompt and the user's permitted tools
 * to the AI service, stores the reply with token usage, and charges AI credits for it. The service calls tools back through
 * a short-lived session bound to this user and institution.
 */
final class ChatService
{
    public function __construct(private readonly CurrentMember $member, private readonly AiServiceClient $client, private readonly AiSessions $sessions,
        private readonly ToolRegistry $tools, private readonly AiCredits $credits)
    {
    }

    public function tenant(): Tenant
    {
        return Tenant::findOrFail(TenantContext::id());
    }

    public function agents(): array
    {
        $s = AiSetting::current();

        return collect(config('ai.agents'))->filter(fn ($a, $k) => $s->agentEnabled($k))->all();
    }

    public function send(string $agent, string $text, ?AiConversation $conversation = null, ?string $language = null): AiMessage
    {
        $this->member->authorize('ai.use');
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 4000) {
            throw new AiException('Write a question of up to 4,000 characters.', 'message');
        }
        $settings = AiSetting::current();
        if ($conversation) {
            if ($conversation->user_id !== $this->member->userId()) {
                throw new \Illuminate\Auth\Access\AuthorizationException('This conversation belongs to someone else.');
            }
            $agent = $conversation->agent;
        }
        if (! $settings->agentEnabled($agent)) {
            throw new AiException('This assistant is switched off for your institution.', 'agent');
        }
        $tenant = $this->tenant();
        if ($this->credits->balance($tenant) <= 0) {
            throw new AiException('Your institution has no AI credits left. Ask the owner to top up.');
        }

        $conversation ??= AiConversation::create(['user_id' => $this->member->userId(), 'agent' => $agent, 'title' => Str::limit($text, 80, '…'),
            'language' => $language ?: $settings->language]);
        $conversation->touch();
        AiMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => $text]);

        $history = AiMessage::where('conversation_id', $conversation->id)->whereIn('role', ['user', 'assistant'])->orderByDesc('created_at')
            ->limit((int) config('ai.history_turns', 12) * 2)->get()->reverse()->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->values()->all();
        $tools = $this->tools->available($agent);
        [$provider, $model] = $settings->modelFor($agent);
        $prompt = AiPrompt::active($agent);
        $token = $this->sessions->issue($tenant->id, $this->member->userId(), $agent, array_column($tools, 'name'), $conversation->id);

        try {
            $out = $this->client->post('/v1/chat', ['agent' => $agent, 'provider' => $provider, 'model' => $model, 'prompt' => $prompt?->prompt,
                'language' => $conversation->language, 'messages' => $history, 'tools' => $tools,
                'tool_base_url' => Str::beforeLast(route('api.ai.tool', ['tenant' => $tenant->code, 'tool' => 'x']), '/x'), 'session_token' => $token,
                'claims' => ['tenant_id' => $tenant->id, 'user_id' => $this->member->userId(), 'role' => (string) $this->member->role()]]);
        } catch (AiException $e) {
            AiMessage::create(['conversation_id' => $conversation->id, 'role' => 'error', 'content' => $e->getMessage(), 'provider' => $provider, 'model' => $model]);
            throw $e;
        } finally {
            $this->sessions->revoke($token);
        }

        $in = (int) ($out['usage']['input_tokens'] ?? 0);
        $outTokens = (int) ($out['usage']['output_tokens'] ?? 0);
        $credits = self::creditsFor($model, $in + $outTokens);
        $approvals = AiActionRequest::where('conversation_id', $conversation->id)->where('created_at', '>=', now()->subMinutes((int) config('ai.session_minutes', 10)))
            ->whereIn('id', collect($out['approvals'] ?? [])->pluck('id')->filter()->all())->pluck('id')->all();
        $msg = AiMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => (string) ($out['reply'] ?? ''),
            'tool_calls' => ['calls' => $out['tool_calls'] ?? [], 'approvals' => $approvals], 'provider' => $provider, 'model' => $model,
            'input_tokens' => $in, 'output_tokens' => $outTokens, 'credits' => $credits, 'prompt_version' => $prompt?->version]);
        $this->charge($tenant, $credits, 'ai:'.$msg->id, ucfirst($agent).' assistant');

        return $msg;
    }

    /** Credits for a call: per 1,000 tokens by model, rounded up, at least 1. */
    public static function creditsFor(string $model, int $tokens): int
    {
        $rates = config('ai.credits_per_1k', []);   // model names contain dots, so not config("ai.credits_per_1k.{$model}")
        $rate = $rates[$model] ?? $rates['default'] ?? 2;

        return max(1, (int) ceil($tokens / 1000 * $rate));
    }

    /** Never takes the balance below zero: the last call is charged what is left. */
    public function charge(Tenant $tenant, int $credits, string $reference, string $note): void
    {
        $credits = min($credits, $this->credits->balance($tenant));
        if ($credits > 0) {
            $this->credits->consume($tenant, $credits, $reference, $note);
        }
    }
}
