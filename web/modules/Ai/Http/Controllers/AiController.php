<?php

namespace Modules\Ai\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Http\Controllers\Controller;
use Modules\Ai\Models\AiActionRequest;
use Modules\Ai\Models\AiCallLog;
use Modules\Ai\Models\AiConversation;
use Modules\Ai\Models\AiDocument;
use Modules\Ai\Services\AnomalyDetector;
use Modules\Ai\Services\ChatService;
use Modules\Ai\Services\DocumentReader;
use Modules\Ai\Services\Proposals;
use Modules\Platform\Services\AiCredits;

class AiController extends Controller
{
    public function index(ChatService $chat, AiCredits $credits): View
    {
        $this->member()->authorize('ai.use');

        return view('ai::index', ['agents' => $chat->agents(), 'credits' => $credits->balance($chat->tenant()),
            'conversations' => AiConversation::where('user_id', $this->member()->userId())->latest('updated_at')->limit(12)->get(),
            'pending' => $this->member()->can('ai.approve') ? AiActionRequest::where('status', 'pending')->count() : 0]);
    }

    public function chat(Request $request, ChatService $chat): View
    {
        $this->member()->authorize('ai.use');
        $conversation = $request->filled('c') ? AiConversation::where('user_id', $this->member()->userId())->findOrFail($request->c) : null;
        $agents = $chat->agents();
        $agent = $conversation?->agent ?? (array_key_exists((string) $request->agent, $agents) ? $request->agent : array_key_first($agents));
        $messages = $conversation?->messages()->get() ?? collect();
        $ids = $messages->flatMap(fn ($m) => $m->tool_calls['approvals'] ?? [])->all();

        return view('ai::chat', ['agents' => $agents, 'agent' => $agent, 'conversation' => $conversation, 'messages' => $messages,
            'approvals' => AiActionRequest::whereIn('id', $ids)->get()->keyBy('id'), 'languages' => config('ai.languages'),
            'history' => AiConversation::where('user_id', $this->member()->userId())->latest('updated_at')->limit(20)->get()]);
    }

    public function send(Request $request, ChatService $chat): RedirectResponse
    {
        $c = $request->filled('conversation_id') ? AiConversation::where('user_id', $this->member()->userId())->findOrFail($request->conversation_id) : null;
        $msg = $chat->send((string) $request->input('agent'), (string) $request->input('message'), $c, $request->input('language'));

        return redirect()->route('app.ai.chat', ['c' => $msg->conversation_id]);
    }

    public function approvals(Proposals $proposals): View
    {
        $this->member()->authorize('ai.approve');

        return view('ai::approvals', ['pending' => AiActionRequest::with('requester')->where('status', 'pending')->latest()->get(),
            'decided' => AiActionRequest::with('requester')->where('status', '!=', 'pending')->latest('decided_at')->limit(30)->get(), 'proposals' => $proposals]);
    }

    public function approve(AiActionRequest $aiRequest, Proposals $proposals): RedirectResponse
    {
        $r = $proposals->approve($aiRequest);

        return $this->done('Approved. '.$r->result);
    }

    public function reject(Request $request, AiActionRequest $aiRequest, Proposals $proposals): RedirectResponse
    {
        $proposals->reject($aiRequest, (string) $request->input('reason'));

        return $this->done('Rejected. Nothing was posted.');
    }

    public function documents(): View
    {
        $this->member()->authorize('ai.use');
        $q = AiDocument::query()->when(! $this->member()->can('ai.approve'), fn ($q) => $q->where('uploaded_by', $this->member()->userId()));

        return view('ai::documents', ['documents' => $q->latest()->limit(50)->get(), 'types' => config('ai.document_types')]);
    }

    public function upload(Request $request, DocumentReader $reader): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file']]);
        $doc = $reader->upload((string) $request->input('doc_type'), $request->file('file'));

        return redirect()->route('app.ai.documents.show', $doc)->with('status', $doc->status === 'extracted' ? 'Read. Check the fields below.' : 'Could not read it: '.$doc->error);
    }

    public function document(AiDocument $aiDocument): View
    {
        $this->canSee($aiDocument);

        return view('ai::document', ['doc' => $aiDocument, 'types' => config('ai.document_types')]);
    }

    public function review(Request $request, AiDocument $aiDocument, DocumentReader $reader): RedirectResponse
    {
        $this->canSee($aiDocument);
        $reader->review($aiDocument, (array) $request->input('fields', []));

        return $this->done('Marked as checked.');
    }

    public function anomalies(Request $request, AnomalyDetector $detector): View
    {
        $this->member()->authorize('accounts.view');
        $from = $request->input('from') ?: (\Modules\Accounting\Models\FinancialYear::current()?->starts_on->format('Y-m-d') ?? today()->startOfYear()->format('Y-m-d'));
        $to = $request->input('to') ?: today()->format('Y-m-d');

        return view('ai::anomalies', ['from' => $from, 'to' => $to, 'findings' => $detector->scan($from, $to)]);
    }

    public function calls(): View
    {
        abort_unless($this->member()->can('fees.view') || $this->member()->can('admissions.manage'), 403);

        return view('ai::calls', ['calls' => AiCallLog::with('student')->latest()->limit(100)->get()]);
    }

    private function canSee(AiDocument $d): void
    {
        $this->member()->authorize('ai.use');
        abort_unless($d->uploaded_by === $this->member()->userId() || $this->member()->can('ai.approve'), 403);
    }
}
