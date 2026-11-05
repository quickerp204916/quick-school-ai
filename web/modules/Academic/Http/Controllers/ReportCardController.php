<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\Exam;
use Modules\Academic\Models\ReportCard;
use Modules\Academic\Models\Section;
use Modules\Academic\Services\ReportCardService;
use Modules\Academic\Support\Institution;

class ReportCardController extends Controller
{
    public function __construct(private readonly ReportCardService $cards)
    {
    }

    /** Choose a section and exams with weights; preview the computed results; publish. */
    public function index(Request $request): View
    {
        $session = $this->session();
        $visible = $this->member()->visibleSectionIds();
        $sections = Section::with('level')->where('session_id', $session->id)->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->get()->sortBy(fn ($s) => [$s->level->sequence, $s->name])->values();
        $section = $request->filled('section') ? $sections->firstWhere('id', $request->section) : null;
        $exams = Exam::where('session_id', $session->id)->orderBy('starts_on')->get();

        $preview = null;
        $weights = [];
        if ($section && $request->filled('exams')) {
            $weights = ReportCardService::weights($request->only('exams'));
            $preview = $this->cards->compute($section, $weights);
        }

        return view('academic::report-cards.index', ['sections' => $sections, 'section' => $section, 'exams' => $exams, 'weights' => $weights,
            'preview' => $preview, 'session' => $session,
            'published' => $section ? ReportCard::where('section_id', $section->id)->selectRaw('title, count(*) as n, max(published_at) as at')->groupBy('title')->get() : collect()]);
    }

    public function publish(Request $request, Section $section): RedirectResponse
    {
        $n = $this->cards->publish($section, $request->only(['exams', 'title', 'remarks']));

        return $this->done("{$n} report cards published.", route('app.report-cards.list', ['section' => $section->id, 'title' => $request->input('title')]));
    }

    public function list(Request $request): View
    {
        $section = Section::with('level', 'session')->findOrFail($request->input('section'));
        $this->member()->mustTeach($section);
        $cards = ReportCard::with('student')->where('section_id', $section->id)->where('title', $request->input('title'))->get()
            ->sortBy(fn ($c) => [$c->rank ?? 9999, $c->student->first_name]);

        return view('academic::report-cards.list', ['section' => $section, 'title' => $request->input('title'), 'cards' => $cards]);
    }

    public function show(ReportCard $card): View
    {
        $card->load(['student.guardians', 'section.level', 'session']);
        $this->member()->mustSeeStudent($card->student);

        return view('academic::report-cards.show', ['card' => $card, 'tenant' => Institution::tenant(), 'profile' => Institution::profile()]);
    }
}
