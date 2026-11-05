<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\PromotionRun;
use Modules\Academic\Models\Section;
use Modules\Academic\Services\PromotionService;
use Modules\Institution\Models\AcademicSession;

class PromotionController extends Controller
{
    public function __construct(private readonly PromotionService $promotion)
    {
    }

    public function index(Request $request): View
    {
        $from = $this->session($request->input('from_session'));
        $sessions = AcademicSession::orderByDesc('starts_on')->get();
        $to = $request->filled('to_session') ? AcademicSession::find($request->to_session) : $sessions->first(fn ($s) => $s->starts_on->gt($from->starts_on));
        $sections = Section::with('level')->withCount(['activeEnrollments as strength'])->where('session_id', $from->id)->get()->sortBy(fn ($s) => [$s->level->sequence, $s->name])->values();
        $section = $request->filled('section') ? $sections->firstWhere('id', $request->section) : null;

        return view('academic::promotion.index', ['from' => $from, 'to' => $to, 'sessions' => $sessions, 'sections' => $sections, 'section' => $section,
            'preview' => $section && $to ? $this->promotion->preview($section, $to) : null,
            'runs' => PromotionRun::with(['fromSection.level', 'toSession'])->latest()->limit(20)->get()]);
    }

    public function run(Request $request): RedirectResponse
    {
        $section = Section::findOrFail($request->input('section_id'));
        $to = AcademicSession::findOrFail($request->input('to_session_id'));
        $c = $this->promotion->run($section, $to, (array) $request->input('decisions', []));

        return $this->done("Done: {$c['promote']} promoted, {$c['retain']} retained, {$c['graduate']} graduated, {$c['leave']} left, {$c['skip']} left for later.");
    }
}
