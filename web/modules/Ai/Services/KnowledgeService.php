<?php

namespace Modules\Ai\Services;

use App\Core\Tenancy\TenantContext;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Academic\Support\Rules;
use Modules\Ai\AiException;
use Modules\Ai\Models\AiKnowledgeSource;

/**
 * The institution's own documents for retrieval (RAG): fee rules, admission criteria, handbooks, circulars.
 * The text is kept here; the AI service splits it, embeds it and stores the vectors in PostgreSQL with pgvector,
 * tagged with the tenant and the roles allowed to retrieve it.
 */
final class KnowledgeService
{
    public function __construct(private readonly CurrentMember $member, private readonly AiServiceClient $client)
    {
    }

    public function save(array $data, ?AiKnowledgeSource $src = null): AiKnowledgeSource
    {
        $this->member->authorize('ai.configure');
        $d = Rules::check($data, ['title' => ['required', 'string', 'max:150'], 'content' => ['required', 'string', 'min:20', 'max:200000'],
            'roles' => ['nullable', 'array'], 'roles.*' => ['in:'.implode(',', array_keys(config('academic.roles')))]]);
        $src ??= new AiKnowledgeSource(['created_by' => $this->member->userId()]);
        $src->fill(['title' => $d['title'], 'content' => $d['content'], 'roles' => $d['roles'] ?? null, 'error' => null])->save();
        Audit::record('ai.knowledge_saved', $src, ['title' => $src->title]);

        return $this->index($src);
    }

    public function index(AiKnowledgeSource $src): AiKnowledgeSource
    {
        try {
            $r = $this->client->post('/v1/ingest', ['claims' => $this->claims(), 'source_type' => 'knowledge', 'source_id' => $src->id, 'title' => $src->title,
                'text' => $src->content, 'roles' => $src->roles ?? []]);
            $src->update(['chunks' => (int) ($r['chunks'] ?? 0), 'indexed_at' => now(), 'error' => null]);
        } catch (AiException $e) {
            $src->update(['error' => mb_substr($e->getMessage(), 0, 300)]);
        }

        return $src;
    }

    public function delete(AiKnowledgeSource $src): void
    {
        $this->member->authorize('ai.configure');
        try {
            $this->client->post('/v1/forget', ['claims' => $this->claims(), 'source_type' => 'knowledge', 'source_id' => $src->id, 'title' => $src->title, 'text' => '-']);
        } catch (AiException) {
            // The vectors are removed the next time the service is reachable: deleting is never blocked.
        }
        Audit::record('ai.knowledge_deleted', $src, ['title' => $src->title]);
        $src->delete();
    }

    private function claims(): array
    {
        return ['tenant_id' => TenantContext::id(), 'user_id' => (string) $this->member->userId(), 'role' => (string) $this->member->role()];
    }
}
