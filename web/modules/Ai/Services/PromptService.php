<?php

namespace Modules\Ai\Services;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Ai\AiException;
use Modules\Ai\Models\AiPrompt;

/** Versioned prompts per agent. Saving makes a new version; one version per agent is active; old versions are kept. */
final class PromptService
{
    public function __construct(private readonly CurrentMember $member)
    {
    }

    public function save(string $agent, string $prompt, ?string $note = null, bool $activate = true): AiPrompt
    {
        $this->member->authorize('ai.configure');
        if (! array_key_exists($agent, config('ai.agents'))) {
            throw new AiException('Unknown assistant.', 'agent');
        }
        $prompt = trim($prompt);
        if (mb_strlen($prompt) < 20 || mb_strlen($prompt) > 8000) {
            throw new AiException('A prompt needs 20 to 8,000 characters.', 'prompt');
        }

        return DB::transaction(function () use ($agent, $prompt, $note, $activate) {
            $version = \Modules\Academic\Support\Numbering::next('AI-PROMPT', $agent);   // locked counter: two saves never get the same version
            $p = AiPrompt::create(['agent' => $agent, 'version' => $version, 'prompt' => $prompt, 'note' => $note ? mb_substr($note, 0, 200) : null,
                'is_active' => false, 'created_by' => $this->member->userId()]);
            Audit::record('ai.prompt_saved', $p, ['agent' => $agent, 'version' => $version]);

            return $activate ? $this->activate($p) : $p;
        });
    }

    public function activate(AiPrompt $p): AiPrompt
    {
        $this->member->authorize('ai.configure');
        DB::transaction(function () use ($p) {
            AiPrompt::where('agent', $p->agent)->where('is_active', true)->update(['is_active' => false]);
            $p->update(['is_active' => true]);
        });
        Audit::record('ai.prompt_activated', $p, ['agent' => $p->agent, 'version' => $p->version]);

        return $p;
    }

    /** Back to the built-in prompt of the AI service. */
    public function useDefault(string $agent): void
    {
        $this->member->authorize('ai.configure');
        AiPrompt::where('agent', $agent)->where('is_active', true)->update(['is_active' => false]);
        Audit::record('ai.prompt_default', null, ['agent' => $agent]);
    }
}
