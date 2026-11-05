<?php

namespace Modules\Ai\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Academic\Http\Controllers\Controller;
use Modules\Academic\Support\Audit;
use Modules\Ai\Models\AiKnowledgeSource;
use Modules\Ai\Models\AiMessage;
use Modules\Ai\Models\AiPrompt;
use Modules\Ai\Models\AiSetting;
use Modules\Ai\Services\ChatService;
use Modules\Ai\Services\KnowledgeService;
use Modules\Ai\Services\PromptService;
use Modules\Platform\Models\AiCreditEntry;
use Modules\Platform\Services\AiCredits;

/** Owner and admin: models per assistant, languages, prompts, knowledge base, usage and credits. */
class AiAdminController extends Controller
{
    public function settings(): View
    {
        $this->member()->authorize('ai.configure');

        return view('ai::settings', ['s' => AiSetting::current(), 'agents' => config('ai.agents'), 'providers' => config('ai.providers'), 'languages' => config('ai.languages')]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $this->member()->authorize('ai.configure');
        $models = [];
        foreach (array_keys(config('ai.agents')) as $agent) {
            $choice = (string) $request->input("model.{$agent}", '');
            [$provider, $model] = array_pad(explode(':', $choice, 2), 2, '');
            if ($choice !== '' && in_array($model, config("ai.providers.{$provider}.models", []), true)) {
                $models[$agent] = ['provider' => $provider, 'model' => $model];
            }
        }
        $request->validate(['language' => ['required', Rule::in(array_keys(config('ai.languages')))]]);
        $enabled = array_values(array_intersect((array) $request->input('agents', []), array_keys(config('ai.agents'))));
        AiSetting::current()->update(['enabled' => $request->boolean('enabled'), 'models' => $models, 'agents_enabled' => $enabled, 'language' => $request->language]);
        Audit::record('ai.settings_saved', AiSetting::current(), ['models' => $models, 'agents' => $enabled]);

        return $this->done('AI settings saved.');
    }

    public function prompts(Request $request): View
    {
        $this->member()->authorize('ai.configure');
        $agent = array_key_exists((string) $request->agent, config('ai.agents')) ? $request->agent : array_key_first(config('ai.agents'));

        return view('ai::prompts', ['agents' => config('ai.agents'), 'agent' => $agent, 'versions' => AiPrompt::where('agent', $agent)->orderByDesc('version')->get()]);
    }

    public function savePrompt(Request $request, PromptService $prompts): RedirectResponse
    {
        $p = $prompts->save((string) $request->agent, (string) $request->prompt, $request->note, $request->boolean('activate', true));

        return $this->done("Saved as version {$p->version}".($p->is_active ? ' and made active.' : '.'));
    }

    public function activatePrompt(AiPrompt $aiPrompt, PromptService $prompts): RedirectResponse
    {
        $prompts->activate($aiPrompt);

        return $this->done("Version {$aiPrompt->version} is now active.");
    }

    public function defaultPrompt(Request $request, PromptService $prompts): RedirectResponse
    {
        $prompts->useDefault((string) $request->agent);

        return $this->done('Using the built-in prompt.');
    }

    public function knowledge(): View
    {
        $this->member()->authorize('ai.configure');

        return view('ai::knowledge', ['sources' => AiKnowledgeSource::latest()->get(), 'roles' => config('academic.roles')]);
    }

    public function saveKnowledge(Request $request, KnowledgeService $svc): RedirectResponse
    {
        $src = $svc->save($request->only('title', 'content', 'roles'), $request->filled('id') ? AiKnowledgeSource::findOrFail($request->id) : null);

        return $this->done($src->error ? 'Saved, but indexing failed: '.$src->error : "Saved and indexed in {$src->chunks} part(s).");
    }

    public function reindex(AiKnowledgeSource $aiSource, KnowledgeService $svc): RedirectResponse
    {
        $this->member()->authorize('ai.configure');
        $src = $svc->index($aiSource);

        return $this->done($src->error ? 'Indexing failed: '.$src->error : "Indexed in {$src->chunks} part(s).");
    }

    public function deleteKnowledge(AiKnowledgeSource $aiSource, KnowledgeService $svc): RedirectResponse
    {
        $svc->delete($aiSource);

        return $this->done('Removed from the knowledge base.');
    }

    public function usage(ChatService $chat, AiCredits $credits): View
    {
        $this->member()->authorize('ai.configure');
        $tenant = $chat->tenant();
        $since = now()->startOfMonth();
        $base = AiMessage::query()->join('ai_conversations as c', 'c.id', '=', 'ai_messages.conversation_id')->where('ai_messages.role', 'assistant')->where('ai_messages.created_at', '>=', $since);

        return view('ai::usage', ['balance' => $credits->balance($tenant), 'used' => $credits->usedBetween($since, now(), $tenant),
            'byAgent' => (clone $base)->selectRaw('c.agent as agent, count(*) as turns, sum(ai_messages.input_tokens + ai_messages.output_tokens) as tokens, sum(ai_messages.credits) as credits')->groupBy('c.agent')->get(),
            'byUser' => (clone $base)->join('users as u', 'u.id', '=', 'c.user_id')->selectRaw('u.name as name, count(*) as turns, sum(ai_messages.credits) as credits')->groupBy('u.name')->orderByDesc('credits')->limit(20)->get(),
            'entries' => AiCreditEntry::where('tenant_id', $tenant->id)->latest()->limit(30)->get()]);
    }
}
