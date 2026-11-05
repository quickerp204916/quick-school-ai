<?php

namespace Modules\Ai\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Academic\Support\CurrentMember;
use Modules\Ai\Models\AiActionRequest;
use Modules\Ai\Models\AiConversation;
use Modules\Ai\Models\AiMessage;
use Modules\Ai\Services\ChatService;
use Modules\Ai\Services\Proposals;

/** AI assistants for the mobile app (staff and parents). */
class AiApiController
{
    public function __construct(private readonly CurrentMember $member)
    {
    }

    public function agents(ChatService $chat): JsonResponse
    {
        $this->member->authorize('ai.use');

        return response()->json(['data' => collect($chat->agents())->map(fn ($a, $k) => ['key' => $k, 'label' => $a['label'], 'description' => $a['description']])->values(),
            'languages' => config('ai.languages')]);
    }

    public function conversations(): JsonResponse
    {
        $this->member->authorize('ai.use');

        return response()->json(['data' => AiConversation::where('user_id', $this->member->userId())->latest('updated_at')->limit(50)->get(['id', 'agent', 'title', 'language', 'updated_at'])]);
    }

    public function conversation(AiConversation $aiConversation): JsonResponse
    {
        abort_unless($aiConversation->user_id === $this->member->userId(), 404);

        return response()->json(['data' => ['id' => $aiConversation->id, 'agent' => $aiConversation->agent, 'title' => $aiConversation->title,
            'messages' => $aiConversation->messages()->get()->map(fn ($m) => self::message($m))]]);
    }

    public function send(Request $request, ChatService $chat): JsonResponse
    {
        $c = $request->filled('conversation_id') ? AiConversation::where('user_id', $this->member->userId())->findOrFail($request->conversation_id) : null;
        $m = $chat->send((string) $request->input('agent'), (string) $request->input('message'), $c, $request->input('language'));

        return response()->json(['data' => ['conversation_id' => $m->conversation_id] + self::message($m)], 201);
    }

    public function approvals(Proposals $proposals): JsonResponse
    {
        $this->member->authorize('ai.approve');

        return response()->json(['data' => AiActionRequest::where('status', 'pending')->latest()->get()
            ->map(fn ($r) => $r->present() + ['can_decide' => $proposals->canDecide($r), 'requested_at' => $r->created_at->toIso8601String()])]);
    }

    public function approve(AiActionRequest $aiRequest, Proposals $proposals): JsonResponse
    {
        $r = $proposals->approve($aiRequest);

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->status, 'result' => $r->result]]);
    }

    public function reject(Request $request, AiActionRequest $aiRequest, Proposals $proposals): JsonResponse
    {
        $r = $proposals->reject($aiRequest, (string) $request->input('reason'));

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->status]]);
    }

    private static function message(AiMessage $m): array
    {
        return ['id' => $m->id, 'role' => $m->role, 'content' => $m->content, 'approvals' => AiActionRequest::whereIn('id', $m->tool_calls['approvals'] ?? [])->get()->map->present(),
            'tools_used' => collect($m->tool_calls['calls'] ?? [])->pluck('tool')->unique()->values(), 'credits' => $m->credits, 'created_at' => $m->created_at->toIso8601String()];
    }
}
