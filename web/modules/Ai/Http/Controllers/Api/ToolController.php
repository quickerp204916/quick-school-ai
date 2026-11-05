<?php

namespace Modules\Ai\Http\Controllers\Api;

use App\Core\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Academic\Exceptions\AcademicRuleException;
use Modules\Academic\Support\CurrentMember;
use Modules\Ai\Services\AiServiceClient;
use Modules\Ai\Services\AiSessions;
use Modules\Ai\Services\ToolContext;
use Modules\Ai\Tools\ToolRegistry;
use Modules\Identity\Models\TenantMembership;
use Modules\Identity\Models\User;

/**
 * Tool calls from the AI service. Three checks before anything runs: the request is signed with the shared key,
 * the session token is live and was issued for this institution, and the tool was offered to that user in that chat.
 * The tool then runs as the user, so their role's permissions and section limits apply exactly as in the app.
 */
class ToolController
{
    public function __invoke(Request $request, string $tool, AiSessions $sessions, ToolRegistry $tools, CurrentMember $member, ToolContext $context): JsonResponse
    {
        if (! AiServiceClient::verify($request)) {
            return response()->json(['message' => 'Invalid or missing request signature.'], 401);
        }
        $session = $sessions->resolve($request->header('X-AI-Session'));
        if (! $session || $session['tenant_id'] !== TenantContext::id()) {
            return response()->json(['message' => 'The AI session has expired or belongs to another institution.'], 403);
        }
        if (! in_array($tool, $session['tools'], true) || ! $tools->exists($tool)) {
            return response()->json(['message' => "Tool {$tool} is not available in this chat."], 403);
        }
        $user = User::find($session['user_id']);
        if (! $user || ! TenantMembership::where('user_id', $user->id)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'This user is no longer an active member.'], 403);
        }
        auth()->guard()->setUser($user);
        $context->conversationId = $session['conversation_id'];

        try {
            $result = $tools->run($tool, (array) $request->input('arguments', []));
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage() ?: 'Your role cannot use this tool.'], 403);
        } catch (AcademicRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first() ?? $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
