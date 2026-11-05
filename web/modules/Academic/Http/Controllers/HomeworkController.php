<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\Homework;
use Modules\Academic\Models\Section;
use Modules\Academic\Services\HomeworkService;

class HomeworkController extends Controller
{
    public function __construct(private readonly HomeworkService $homework)
    {
    }

    public function index(Request $request): View
    {
        $session = $this->session();
        $visible = $this->member()->visibleSectionIds();
        $list = Homework::with(['section.level', 'subject', 'teacher'])->withCount(['submissions as done' => fn ($q) => $q->whereIn('status', ['submitted', 'late', 'checked'])])
            ->whereHas('section', fn ($q) => $q->where('session_id', $session->id))
            ->when($visible !== null, fn ($q) => $q->whereIn('section_id', $visible))
            ->when($request->filled('section'), fn ($q) => $q->where('section_id', $request->section))
            ->when($request->input('when') === 'due', fn ($q) => $q->where('due_on', '>=', today())->where('status', 'published'))
            ->orderByDesc('due_on')->paginate(30)->withQueryString();

        return view('academic::homework.index', ['list' => $list, 'sections' => $this->sections($session->id)]);
    }

    public function create(Request $request): View
    {
        return view('academic::homework.form', ['hw' => new Homework(['section_id' => $request->input('section'), 'assigned_on' => today(), 'due_on' => today()->addDays(2)]),
            'sections' => $this->sections($this->session()->id)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $hw = $this->homework->save(self::input($request));

        return $this->done('Homework set. Students and parents see it in the app.', route('app.homework.show', $hw));
    }

    public function show(Homework $homework): View
    {
        $homework->load(['section.level', 'subject', 'teacher']);
        $this->member()->mustTeach($homework->section);

        return view('academic::homework.show', ['hw' => $homework] + $this->homework->tracker($homework));
    }

    public function edit(Homework $homework): View
    {
        return view('academic::homework.form', ['hw' => $homework, 'sections' => $this->sections($homework->section->session_id)]);
    }

    public function update(Request $request, Homework $homework): RedirectResponse
    {
        $this->homework->save(self::input($request), $homework);

        return $this->done('Homework saved.', route('app.homework.show', $homework));
    }

    public function check(Request $request, Homework $homework): RedirectResponse
    {
        $n = $this->homework->check($homework, (array) $request->input('rows', []));

        return $this->done("Saved {$n} students.");
    }

    public function cancel(Homework $homework): RedirectResponse
    {
        $this->homework->cancel($homework);

        return $this->done('Homework cancelled.');
    }

    private function sections(string $sessionId)
    {
        $visible = $this->member()->visibleSectionIds();

        return Section::with(['level', 'subjects.subject'])->where('session_id', $sessionId)->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->get()->sortBy(fn ($s) => [$s->level->sequence, $s->name])->values();
    }

    /** The form sends section and subject as one "section_id|subject_id" choice. */
    private static function input(Request $request): array
    {
        $data = $request->all();
        if (! empty($data['section_subject']) && str_contains($data['section_subject'], '|')) {
            [$data['section_id'], $data['subject_id']] = explode('|', $data['section_subject'], 2);
        }

        return $data;
    }
}
