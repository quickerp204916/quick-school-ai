<?php

namespace Modules\Ai\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A short-lived token for one chat turn. The AI service presents it on every tool call; it is bound to one
 * institution, one user and the tools that user may call, so the service can never act for anyone else.
 */
final class AiSessions
{
    public function issue(string $tenantId, string $userId, string $agent, array $tools, ?string $conversationId = null): string
    {
        $token = Str::random(64);
        Cache::put($this->key($token), ['tenant_id' => $tenantId, 'user_id' => $userId, 'agent' => $agent, 'tools' => array_values($tools),
            'conversation_id' => $conversationId], now()->addMinutes((int) config('ai.session_minutes', 10)));

        return $token;
    }

    public function resolve(?string $token): ?array
    {
        return $token ? Cache::get($this->key($token)) : null;
    }

    public function revoke(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'ai-session:'.hash('sha256', $token);
    }
}
