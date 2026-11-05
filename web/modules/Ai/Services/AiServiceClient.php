<?php

namespace Modules\Ai\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Ai\AiException;

/**
 * Signed HTTP calls to the AI service (ai-service/, FastAPI + LangGraph), and verification of its calls back.
 * Both directions carry X-QSA-Timestamp and X-QSA-Signature = hex HMAC-SHA256(key, "<timestamp>.<raw body>").
 */
final class AiServiceClient
{
    public function post(string $path, array $body, int $timeout = 120): array
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        try {
            $r = Http::timeout($timeout)->acceptJson()->withHeaders(self::sign($json))
                ->withBody($json, 'application/json')->post(rtrim((string) config('ai.service_url'), '/').$path);
        } catch (ConnectionException) {
            throw new AiException('The AI service could not be reached. Please try again in a minute.');
        }
        if ($r->failed()) {
            $detail = $r->json('detail');
            throw new AiException('The AI service could not answer: '.(is_string($detail) ? $detail : 'error '.$r->status()).'.');
        }

        return $r->json() ?? [];
    }

    public static function sign(string $body, ?int $timestamp = null): array
    {
        $key = (string) config('ai.service_key');
        if ($key === '') {
            throw new AiException('The AI service is not configured. Set QSA_AI_SERVICE_KEY on this app and on the AI service.');
        }
        $ts = (string) ($timestamp ?? time());

        return ['X-QSA-Timestamp' => $ts, 'X-QSA-Signature' => hash_hmac('sha256', $ts.'.'.$body, $key)];
    }

    /** A call from the AI service: valid signature, made within the last five minutes. */
    public static function verify(Request $request, int $tolerance = 300): bool
    {
        return self::verifyRaw($request->getContent(), (string) $request->header('X-QSA-Timestamp'), (string) $request->header('X-QSA-Signature'), $tolerance);
    }

    public static function verifyRaw(string $body, string $ts, string $sig, int $tolerance = 300): bool
    {
        $key = (string) config('ai.service_key');
        if ($key === '' || ! ctype_digit($ts) || $sig === '' || abs(time() - (int) $ts) > $tolerance) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $ts.'.'.$body, $key), $sig);
    }
}
