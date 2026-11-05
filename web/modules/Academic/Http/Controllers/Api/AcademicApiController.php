<?php

namespace Modules\Academic\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Academic\Models\AdmissionApplication;
use Modules\Academic\Models\Certificate;
use Modules\Academic\Models\Exam;
use Modules\Academic\Models\ExamPaper;
use Modules\Academic\Models\Homework;
use Modules\Academic\Models\HomeworkSubmission;
use Modules\Academic\Models\ReportCard;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\Student;
use Modules\Academic\Services\AdmissionService;
use Modules\Academic\Services\AttendanceService;
use Modules\Academic\Services\ExamService;
use Modules\Academic\Services\HomeworkService;
use Modules\Academic\Services\StudentService;
use Modules\Academic\Services\TimetableService;
use Modules\Academic\Support\CurrentMember;
use Modules\Institution\Models\AcademicSession;

/** Academic API v1 for the mobile app and integrations. Same rules and validation as the web screens. */
class AcademicApiController
{
    public function __construct(private readonly CurrentMember $member)
    {
    }

    /* ---------- Structure ---------- */

    public function sections(Request $request): JsonResponse
    {
        $session = $request->filled('session_id') ? AcademicSession::findOrFail($request->session_id) : AcademicSession::current();
        $visible = $this->member->visibleSectionIds();
        $sections = Section::with(['level', 'classTeacher'])->where('session_id', $session?->id)->where('is_active', true)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->get()->sortBy(fn ($s) => [$s->level->sequence, $s->name])->values();

        return response()->json(['session' => $session ? ['id' => $session->id, 'name' => $session->name] : null,
            'data' => $sections->map(fn ($s) => Present::section($s) + ['subjects' => $this->member->teaching()[$s->id] ?? null])]);
    }

    public function sectionStudents(Section $section): JsonResponse
    {
        $this->member->mustTeach($section);
        $rows = StudentService::rosterOn($section, today()->format('Y-m-d'));

        return response()->json(['data' => $rows->map(fn ($e) => ['id' => $e->student->id, 'admission_no' => $e->student->admission_no,
            'name' => $e->student->fullName(), 'roll_no' => $e->roll_no, 'gender' => $e->student->gender])->values()]);
    }

    /* ---------- Students ---------- */

    public function students(Request $request): JsonResponse
    {
        $this->member->authorize('students.view');
        $visible = $this->member->visibleSectionIds();
        $page = Student::with('currentEnrollment.section.level')->where('status', $request->input('status', 'active'))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->whereLike('first_name', '%'.$request->q.'%')
                ->orWhereLike('last_name', '%'.$request->q.'%')->orWhereLike('admission_no', '%'.$request->q.'%')))
            ->when($request->filled('section_id') || $visible !== null, fn ($q) => $q->whereHas('enrollments', fn ($e) => $e->where('status', 'active')
                ->when($request->filled('section_id'), fn ($x) => $x->where('section_id', $request->section_id))
                ->when($visible !== null, fn ($x) => $x->whereIn('section_id', $visible))))
            ->orderBy('first_name')->paginate(min(100, (int) $request->input('per_page', 50)));

        return response()->json(['data' => collect($page->items())->map(fn ($s) => Present::student($s)),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function student(Student $student): JsonResponse
    {
        $this->member->mustSeeStudent($student);

        return response()->json(['data' => Present::student($student->load(['guardians', 'currentEnrollment.section.level']), true)]);
    }

    public function storeStudent(Request $request, StudentService $students): JsonResponse
    {
        $this->member->authorize('students.manage');
        $s = $students->create($request->all());

        return response()->json(['data' => Present::student($s->load(['guardians', 'currentEnrollment.section.level']), true)], 201);
    }

    public function updateStudent(Request $request, Student $student, StudentService $students): JsonResponse
    {
        $this->member->authorize('students.manage');
        $students->update($student, $request->all() + $student->only(['admission_no', 'first_name', 'gender']) + ['date_of_birth' => $student->date_of_birth->format('Y-m-d')]);

        return response()->json(['data' => Present::student($student->fresh(['guardians', 'currentEnrollment.section.level']), true)]);
    }

    /* ---------- Admissions ---------- */

    public function admissions(Request $request): JsonResponse
    {
        $this->member->authorize('admissions.manage');
        $apps = AdmissionApplication::with('level')->when($request->filled('stage'), fn ($q) => $q->where('stage', $request->stage))
            ->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $apps->map(fn ($a) => ['id' => $a->id, 'number' => $a->number, 'name' => $a->fullName(), 'level' => $a->level->name,
            'stage' => $a->stage, 'phone' => $a->phone, 'follow_up_on' => $a->follow_up_on?->format('Y-m-d'), 'created_at' => $a->created_at->toIso8601String()])]);
    }

    public function storeAdmission(Request $request, AdmissionService $admissions): JsonResponse
    {
        $this->member->authorize('admissions.manage');
        $a = $admissions->create($request->all(), $request->input('stage', 'enquiry'));

        return response()->json(['data' => ['id' => $a->id, 'number' => $a->number, 'stage' => $a->stage]], 201);
    }

    public function admissionStage(Request $request, AdmissionApplication $admission, AdmissionService $admissions): JsonResponse
    {
        $this->member->authorize('admissions.manage');
        $admissions->move($admission, (string) $request->input('stage'), $request->input('reason'));

        return response()->json(['data' => ['id' => $admission->id, 'stage' => $admission->stage]]);
    }

    /* ---------- Attendance ---------- */

    public function attendanceSheet(Request $request, AttendanceService $attendance): JsonResponse
    {
        $this->member->authorize('attendance.mark');
        $section = Section::findOrFail($request->input('section_id'));
        $this->member->mustTeach($section);
        $date = $request->input('date', today()->format('Y-m-d'));
        $sheet = $attendance->sheet($section, $date, $request->input('period_id'));

        return response()->json(['date' => $date, 'marked' => (bool) $sheet['register'], 'blocked' => $sheet['blocked'],
            'statuses' => config('academic.attendance.statuses'),
            'data' => $sheet['rows']->map(fn ($r) => ['student_id' => $r['student']->id, 'name' => $r['student']->fullName(), 'roll_no' => $r['roll_no'],
                'status' => $r['status'], 'remark' => $r['remark']])->values()]);
    }

    public function markAttendance(Request $request, AttendanceService $attendance): JsonResponse
    {
        $this->member->authorize('attendance.mark');
        $section = Section::findOrFail($request->input('section_id'));
        $register = $attendance->mark($section, $request->only(['date', 'period_id', 'subject_id', 'entries']));

        return response()->json(['data' => ['register_id' => $register->id, 'date' => $register->date->format('Y-m-d')]], 201);
    }

    public function studentAttendance(Request $request, Student $student, AttendanceService $attendance): JsonResponse
    {
        $this->member->mustSeeStudent($student);
        $session = AcademicSession::current();
        $from = $request->input('from', $session?->starts_on?->format('Y-m-d') ?? today()->startOfYear()->format('Y-m-d'));
        $to = $request->input('to', today()->format('Y-m-d'));
        $days = \Modules\Academic\Models\AttendanceEntry::query()->join('attendance_registers as r', 'r.id', '=', 'attendance_entries.register_id')
            ->where('attendance_entries.student_id', $student->id)->whereBetween('r.date', [$from, $to])->whereNull('r.period_id')
            ->orderBy('r.date')->get(['r.date', 'attendance_entries.status', 'attendance_entries.remark']);

        return response()->json(['summary' => $attendance->studentSummary($student->id, $from, $to),
            'days' => $days->map(fn ($d) => ['date' => substr((string) $d->date, 0, 10), 'status' => $d->status, 'remark' => $d->remark])]);
    }

    /* ---------- Timetable ---------- */

    public function sectionTimetable(Section $section, TimetableService $timetable): JsonResponse
    {
        if (in_array($this->member->role(), ['parent', 'student'], true)) {
            $ok = \Modules\Academic\Models\Enrollment::where('section_id', $section->id)->where('status', 'active')->whereIn('student_id', $this->member->linkedStudentIds())->exists();
            abort_unless($ok, 403, 'You cannot view this timetable.');
        } else {
            $this->member->authorize('timetable.view');
        }
        $g = $timetable->sectionGrid($section);

        return response()->json(['days' => $g['days'], 'periods' => $g['periods']->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'starts_at' => substr($p->starts_at, 0, 5),
            'ends_at' => substr($p->ends_at, 0, 5), 'is_break' => $p->is_break])->values(),
            'entries' => collect($g['cells'])->map(fn ($e) => ['day' => $e->day_of_week, 'period_id' => $e->period_id, 'subject' => $e->subject->name,
                'teacher' => $e->teacher?->name, 'room' => $e->room])->values()]);
    }

    public function myTimetable(TimetableService $timetable): JsonResponse
    {
        $staff = $this->member->staff();
        abort_unless($staff, 404, 'Only staff have a personal timetable.');
        $g = $timetable->teacherGrid($staff, AcademicSession::current()->id);

        return response()->json(['days' => $g['days'], 'load' => $g['load'],
            'entries' => collect($g['cells'])->map(fn ($e) => ['day' => $e->day_of_week, 'period' => $e->period->name, 'starts_at' => substr($e->period->starts_at, 0, 5),
                'section' => $e->section->label(), 'section_id' => $e->section_id, 'subject' => $e->subject->name, 'room' => $e->room])->values()]);
    }

    /* ---------- Exams and marks ---------- */

    public function exams(): JsonResponse
    {
        $this->member->authorize('marks.enter');
        $session = AcademicSession::current();
        $papers = ExamPaper::with(['exam', 'section.level', 'subject'])->whereHas('exam', fn ($q) => $q->where('session_id', $session?->id)->where('status', 'open'))->get()
            ->filter(fn ($p) => $this->member->teaches($p->section, $p->subject_id));

        return response()->json(['data' => $papers->map(fn ($p) => ['paper_id' => $p->id, 'exam' => $p->exam->name, 'section' => $p->section->label(),
            'subject' => $p->subject->name, 'max_marks' => (float) $p->max_marks, 'pass_marks' => (float) $p->pass_marks, 'exam_date' => $p->exam_date?->format('Y-m-d')])->values()]);
    }

    public function paperMarks(ExamPaper $paper, ExamService $exams): JsonResponse
    {
        $this->member->authorize('marks.enter');
        $paper->load('section', 'exam', 'subject');
        $this->member->mustTeach($paper->section, $paper->subject_id);

        return response()->json(['paper' => ['id' => $paper->id, 'max_marks' => (float) $paper->max_marks, 'status' => $paper->exam->status],
            'data' => collect($exams->sheet($paper))->map(fn ($r) => ['student_id' => $r['student']->id, 'name' => $r['student']->fullName(), 'roll_no' => $r['roll_no'],
                'marks' => $r['mark']?->marks !== null ? (float) $r['mark']->marks : null, 'absent' => (bool) $r['mark']?->is_absent])->values()]);
    }

    public function saveMarks(Request $request, ExamPaper $paper, ExamService $exams): JsonResponse
    {
        $this->member->authorize('marks.enter');

        return response()->json(['saved' => $exams->enterMarks($paper, (array) $request->input('entries', []))]);
    }

    public function studentReportCards(Student $student): JsonResponse
    {
        $this->member->mustSeeStudent($student);

        return response()->json(['data' => ReportCard::with('section.level')->where('student_id', $student->id)->orderByDesc('published_at')->get()->map(fn ($c) => Present::reportCard($c))]);
    }

    /* ---------- Homework ---------- */

    public function homework(Request $request): JsonResponse
    {
        $studentId = $request->input('student_id');
        if ($studentId) {
            $student = Student::findOrFail($studentId);
            $this->member->mustSeeStudent($student);
            $sectionId = $student->currentEnrollment?->section_id;
            $subs = HomeworkSubmission::where('student_id', $student->id)->pluck('status', 'homework_id');
            $list = Homework::with(['subject', 'section.level', 'teacher'])->where('section_id', $sectionId)->where('status', 'published')
                ->where('due_on', '>=', today()->subDays(14))->orderBy('due_on')->get();

            return response()->json(['data' => $list->map(fn ($h) => Present::homework($h, $subs[$h->id] ?? 'pending'))]);
        }
        $this->member->authorize('homework.manage');
        $visible = $this->member->visibleSectionIds();
        $list = Homework::with(['subject', 'section.level', 'teacher'])->when($visible !== null, fn ($q) => $q->whereIn('section_id', $visible))
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->section_id))
            ->where('due_on', '>=', today()->subDays(14))->orderBy('due_on')->get();

        return response()->json(['data' => $list->map(fn ($h) => Present::homework($h))]);
    }

    public function storeHomework(Request $request, HomeworkService $homework): JsonResponse
    {
        $this->member->authorize('homework.manage');
        $h = $homework->save($request->all());

        return response()->json(['data' => Present::homework($h->load('subject', 'section.level', 'teacher'))], 201);
    }

    public function submitHomework(Request $request, Homework $homework, HomeworkService $svc): JsonResponse
    {
        $student = Student::findOrFail($request->input('student_id'));
        abort_unless(in_array($this->member->role(), ['parent', 'student'], true) && in_array($student->id, $this->member->linkedStudentIds(), true), 403, 'Only the student or their parent can submit.');
        $request->validate(['content' => ['nullable', 'string', 'max:5000']]);
        $sub = $svc->submit($homework, $student, $request->input('content'));

        return response()->json(['data' => ['status' => $sub->status, 'submitted_at' => $sub->submitted_at->toIso8601String()]], 201);
    }

    /* ---------- Certificates ---------- */

    public function studentCertificates(Student $student): JsonResponse
    {
        $this->member->mustSeeStudent($student);

        return response()->json(['data' => Certificate::where('student_id', $student->id)->where('status', 'issued')->orderByDesc('issued_on')->get()
            ->map(fn ($c) => ['id' => $c->id, 'number' => $c->number, 'type' => $c->type, 'title' => $c->data['title'] ?? null, 'issued_on' => $c->issued_on->format('Y-m-d'), 'body' => $c->body])]);
    }

    /* Supporting documents and photos (multipart uploads: fields "type" and "file") */

    public function admissionDocuments(AdmissionApplication $admission): JsonResponse
    {
        $this->member->authorize('admissions.manage');

        return response()->json(['data' => $admission->uploads->map(fn ($d) => Present::document($d)),
            'checklist' => collect(config('academic.admission.documents'))->map(fn ($l, $k) => ['label' => $l, 'received' => (bool) ($admission->documents[$k] ?? false),
                'required' => in_array($k, \Modules\Academic\Support\Institution::profile()->required_documents ?? [], true)])]);
    }

    public function uploadAdmissionDocument(Request $request, AdmissionApplication $admission, \Modules\Academic\Services\DocumentService $docs): JsonResponse
    {
        $this->member->authorize('admissions.manage');

        return response()->json(['data' => Present::document($docs->upload($admission, (string) $request->input('type'), $request->file('file')))], 201);
    }

    public function studentDocuments(Student $student): JsonResponse
    {
        $this->member->mustSeeStudent($student);

        return response()->json(['data' => $student->documents->map(fn ($d) => Present::document($d))]);
    }

    public function uploadStudentDocument(Request $request, Student $student, \Modules\Academic\Services\DocumentService $docs): JsonResponse
    {
        $this->member->authorize('students.manage');

        return response()->json(['data' => Present::document($docs->upload($student, (string) $request->input('type'), $request->file('file')))], 201);
    }

    public function studentPhoto(Student $student, \Modules\Academic\Services\DocumentService $docs)
    {
        $this->member->mustSeeStudent($student);

        return $docs->photo($student);
    }

    public function document(Request $request, \Modules\Academic\Models\StudentDocument $document, \Modules\Academic\Services\DocumentService $docs)
    {
        $document->student_id ? $this->member->mustSeeStudent($document->student) : $this->member->authorize('admissions.manage');

        return $docs->stream($document, $request->boolean('download'));
    }
}
