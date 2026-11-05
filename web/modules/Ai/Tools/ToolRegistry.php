<?php

namespace Modules\Ai\Tools;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Models\AdmissionApplication;
use Modules\Academic\Models\AttendanceEntry;
use Modules\Academic\Models\Enrollment;
use Modules\Academic\Models\ExamPaper;
use Modules\Academic\Models\GradeLevel;
use Modules\Academic\Models\Homework;
use Modules\Academic\Models\Mark;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Models\Student;
use Modules\Academic\Services\AdmissionService;
use Modules\Academic\Support\CurrentMember;
use Modules\Accounting\Models\FinancialYear;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\Voucher;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\InvoiceService;
use Modules\Accounting\Support\Amount;
use Modules\Ai\AiException;
use Modules\Ai\Models\AiCallLog;
use Modules\Ai\Models\AiDocument;
use Modules\Ai\Services\AnomalyDetector;
use Modules\Ai\Services\Proposals;
use Modules\Fees\Models\FeeInvoice;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Services\FeeReports;
use Modules\Hr\Models\PayrollRun;
use Modules\Hr\Services\LeaveService;
use Modules\Institution\Models\AcademicSession;
use Modules\Institution\Models\Branch;

/**
 * The ERP tools the AI agents may call. Every tool names the permission it needs; the list offered to a chat is
 * filtered by the agent and by what the signed-in user's role may do, and every call is checked again when it runs.
 * Tools only read, except:
 *  - admission.create_enquiry and voice.log_call write ordinary records the user could enter themselves;
 *  - propose_* tools never post anything: they create a request that an authorised person approves in the ERP.
 */
final class ToolRegistry
{
    private ?array $tools = null;

    public function __construct(private readonly CurrentMember $member)
    {
    }

    /** Tool specs for one agent, limited to what this user may call. */
    public function available(string $agent): array
    {
        $prefixes = config("ai.agents.{$agent}.tools", []);

        return collect($this->all())->filter(fn ($t, $name) => collect($prefixes)->contains(fn ($p) => str_ends_with($p, '.') ? str_starts_with($name, $p) : $name === $p))
            ->filter(fn ($t) => $this->allowed($t))
            ->map(fn ($t, $name) => ['name' => $name, 'description' => $t['description'], 'parameters' => $t['parameters']])->values()->all();
    }

    public function exists(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /** Runs a tool as the current user. Permission is checked here, not only when the list was built. */
    public function run(string $name, array $args): array
    {
        $tool = $this->all()[$name] ?? throw new AiException("Unknown tool {$name}.");
        if (! $this->allowed($tool)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Your role cannot use this tool.');
        }

        return ($tool['run'])($args);
    }

    private function allowed(array $tool): bool
    {
        $p = $tool['permission'];

        return $p === null ? ($tool['when'])() : $this->member->can($p);
    }

    private function all(): array
    {
        return $this->tools ??= array_merge($this->schoolTools(), $this->academicTools(), $this->admissionTools(), $this->feeTools(),
            $this->accountingTools(), $this->payrollTools(), $this->documentTools(), $this->voiceTools());
    }

    /* ---------------- helpers ---------------- */

    private static function schema(array $props = [], array $required = []): array
    {
        return ['type' => 'object', 'properties' => (object) $props] + ($required ? ['required' => $required] : []);
    }

    private static function str(string $d): array
    {
        return ['type' => 'string', 'description' => $d];
    }

    private static function date(string $d): array
    {
        return ['type' => 'string', 'description' => $d.' (YYYY-MM-DD)'];
    }

    private static function money(int $paise): array
    {
        return ['paise' => $paise, 'display' => Amount::format($paise)];
    }

    private static function day(mixed $v, string $default): string
    {
        $v = is_string($v) ? trim($v) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $default;
    }

    private function session(): AcademicSession
    {
        return AcademicSession::current() ?? AcademicSession::orderByDesc('starts_on')->firstOrFail();
    }

    private function fyStart(): string
    {
        return FinancialYear::current()?->starts_on->format('Y-m-d') ?? today()->startOfYear()->format('Y-m-d');
    }

    /** Matches every word of a name against first or last name (portable across MySQL, PostgreSQL and SQLite). */
    private static function nameLike($q, string $text): void
    {
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $w) {
            if ($w !== '') {
                $q->where(fn ($x) => $x->whereLike('first_name', "%{$w}%")->orWhereLike('last_name', "%{$w}%"));
            }
        }
    }

    /** Students the user may see, from an id, admission number or name. */
    private function findStudent(array $a): Student
    {
        $q = trim((string) ($a['student'] ?? $a['student_id'] ?? ''));
        if ($q === '') {
            throw new AiException('Say which student: an admission number or a name.');
        }
        $s = Str::isUuid($q) ? Student::find($q) : (Student::where('admission_no', $q)->first()
            ?? Student::where(fn ($w) => self::nameLike($w, $q))->orderBy('first_name')->first());
        if (! $s || ! $this->member->canSeeStudent($s)) {
            throw new AiException("No student matching \"{$q}\" that you can see.");
        }

        return $s;
    }

    private function studentRow(Student $s): array
    {
        $e = $s->currentEnrollment;

        return ['id' => $s->id, 'admission_no' => $s->admission_no, 'name' => $s->fullName(), 'class' => $e?->section?->label(), 'roll_no' => $e?->roll_no, 'status' => $s->status];
    }

    private function attendanceRate(array $studentIds, string $from, string $to): array
    {
        $rows = AttendanceEntry::query()->join('attendance_registers as r', 'r.id', '=', 'attendance_entries.register_id')
            ->whereIn('attendance_entries.student_id', $studentIds)->whereBetween('r.date', [$from, $to])
            ->selectRaw("attendance_entries.student_id as sid, count(*) as n, sum(case when attendance_entries.status in ('present','late') then 1 when attendance_entries.status = 'half_day' then 0.5 else 0 end) as p")
            ->groupBy('attendance_entries.student_id')->get();

        return $rows->mapWithKeys(fn ($r) => [$r->sid => ['marked' => (int) $r->n, 'percent' => $r->n ? round($r->p * 100 / $r->n, 1) : null]])->all();
    }

    private function feeBalance(string $studentId): int
    {
        return (int) FeeInvoice::where('student_id', $studentId)->whereIn('status', ['open', 'partial'])->get()->sum(fn ($i) => $i->balance());
    }

    private function visibleSections(): \Illuminate\Database\Eloquent\Builder
    {
        $ids = $this->member->visibleSectionIds();

        return Section::with('level')->where('session_id', $this->session()->id)->where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids));
    }

    /* ---------------- school ---------------- */

    private function schoolTools(): array
    {
        return [
            'school.overview' => ['permission' => 'reports.view', 'description' => 'Today at a glance: students, sections, attendance marked today, and (if you may see fees) collections this month and dues.',
                'parameters' => self::schema(), 'run' => function () {
                    $today = today()->format('Y-m-d');
                    $sections = $this->visibleSections()->get();
                    $active = Enrollment::whereIn('section_id', $sections->pluck('id'))->where('status', 'active')->count();
                    $att = AttendanceEntry::query()->join('attendance_registers as r', 'r.id', '=', 'attendance_entries.register_id')
                        ->whereIn('r.section_id', $sections->pluck('id'))->where('r.date', $today)->selectRaw('attendance_entries.status as s, count(*) as n')->groupBy('attendance_entries.status')->pluck('n', 's');
                    $out = ['date' => $today, 'session' => $this->session()->name, 'active_students' => $active, 'sections' => $sections->count(),
                        'attendance_today' => ['present' => (int) (($att['present'] ?? 0) + ($att['late'] ?? 0)), 'absent' => (int) ($att['absent'] ?? 0), 'on_leave' => (int) ($att['leave'] ?? 0)]];
                    if ($this->member->can('fees.view')) {
                        $c = app(FeeReports::class)->collections(today()->startOfMonth()->format('Y-m-d'), $today);
                        $out['fees'] = ['collected_this_month' => self::money($c['total']), 'outstanding' => self::money((int) FeeInvoice::whereIn('status', ['open', 'partial'])->get()->sum(fn ($i) => $i->balance()))];
                    }

                    return $out;
                }],
            // Parents and students: only their own children.
            'school.my_children' => ['permission' => null, 'when' => fn () => in_array($this->member->role(), ['parent', 'student'], true),
                'description' => 'For a parent or student: their own children with class, attendance this session and fees due.',
                'parameters' => self::schema(), 'run' => function () {
                    $ids = $this->member->linkedStudentIds();
                    $s = $this->session();
                    $att = $this->attendanceRate($ids, $s->starts_on->format('Y-m-d'), today()->format('Y-m-d'));

                    return ['children' => Student::with('currentEnrollment.section.level')->whereIn('id', $ids)->get()->map(fn ($st) => $this->studentRow($st) + [
                        'attendance_percent' => $att[$st->id]['percent'] ?? null, 'fees_due' => self::money($this->feeBalance($st->id))])->all()];
                }],
            'students.search' => ['permission' => 'students.view', 'description' => 'Find students by name or admission number. Returns up to 15 you are allowed to see.',
                'parameters' => self::schema(['query' => self::str('Name or admission number')], ['query']), 'run' => function (array $a) {
                    $q = trim((string) ($a['query'] ?? ''));
                    $ids = $this->member->visibleSectionIds();
                    $list = Student::with('currentEnrollment.section.level')->where(fn ($w) => $w->whereLike('admission_no', "%{$q}%")
                        ->orWhere(fn ($x) => self::nameLike($x, $q)))
                        ->when($ids !== null, fn ($w) => $w->whereHas('currentEnrollment', fn ($e) => $e->whereIn('section_id', $ids)))->limit(15)->get();

                    return ['count' => $list->count(), 'students' => $list->map(fn ($s) => $this->studentRow($s))->all()];
                }],
            'students.profile' => ['permission' => 'students.view', 'description' => 'One student: class, guardians, attendance this session and (if you may see fees) the fee balance.',
                'parameters' => self::schema(['student' => self::str('Admission number, name or id')], ['student']), 'run' => function (array $a) {
                    $s = $this->findStudent($a)->load('currentEnrollment.section.level', 'guardians');
                    $sess = $this->session();
                    $out = $this->studentRow($s) + ['admitted_on' => $s->admitted_on?->format('Y-m-d'),
                        'guardians' => $s->guardians->map(fn ($g) => ['name' => $g->name, 'relation' => $g->pivot->relation ?? null, 'phone' => $g->phone])->all(),
                        'attendance_this_session' => $this->attendanceRate([$s->id], $sess->starts_on->format('Y-m-d'), today()->format('Y-m-d'))[$s->id] ?? null];
                    if ($this->member->can('fees.view')) {
                        $out['fees_due'] = self::money($this->feeBalance($s->id));
                    }

                    return $out;
                }],
        ];
    }

    /* ---------------- academic ---------------- */

    private function academicTools(): array
    {
        return [
            'academic.attendance_summary' => ['permission' => 'attendance.view', 'description' => 'Attendance by section for a date range, and students below a threshold (default 75%).',
                'parameters' => self::schema(['from' => self::date('Start, default first of this month'), 'to' => self::date('End, default today'),
                    'below' => ['type' => 'number', 'description' => 'Flag students under this percent']]), 'run' => function (array $a) {
                    $from = self::day($a['from'] ?? null, today()->startOfMonth()->format('Y-m-d'));
                    $to = self::day($a['to'] ?? null, today()->format('Y-m-d'));
                    $below = (float) ($a['below'] ?? 75);
                    $sections = $this->visibleSections()->get();
                    $rows = [];
                    $flagged = [];
                    foreach ($sections as $sec) {
                        $ids = Enrollment::where('section_id', $sec->id)->where('status', 'active')->pluck('student_id')->all();
                        $rates = $this->attendanceRate($ids, $from, $to);
                        $vals = array_filter(array_column($rates, 'percent'), fn ($v) => $v !== null);
                        $rows[] = ['section' => $sec->label(), 'students' => count($ids), 'average_percent' => $vals ? round(array_sum($vals) / count($vals), 1) : null];
                        foreach ($rates as $sid => $r) {
                            if ($r['percent'] !== null && $r['percent'] < $below) {
                                $flagged[] = ['student_id' => $sid, 'section' => $sec->label(), 'percent' => $r['percent']];
                            }
                        }
                    }
                    $names = Student::whereIn('id', array_column($flagged, 'student_id'))->get()->keyBy('id');
                    usort($flagged, fn ($x, $y) => $x['percent'] <=> $y['percent']);

                    return ['from' => $from, 'to' => $to, 'sections' => $rows, 'below_threshold' => array_map(fn ($f) => $f + ['name' => $names[$f['student_id']]?->fullName()], array_slice($flagged, 0, 30))];
                }],
            'academic.homework_due' => ['permission' => 'homework.manage', 'description' => 'Homework due in the next days for the sections you can see, with how many submissions are in.',
                'parameters' => self::schema(['days' => ['type' => 'integer', 'description' => 'How many days ahead, default 7']]), 'run' => function (array $a) {
                    $to = today()->addDays(max(1, min(60, (int) ($a['days'] ?? 7))))->format('Y-m-d');
                    $list = Homework::with('section.level')->whereIn('section_id', $this->visibleSections()->pluck('id'))->whereBetween('due_on', [today()->format('Y-m-d'), $to])
                        ->withCount('submissions')->orderBy('due_on')->limit(40)->get();

                    return ['homework' => $list->map(fn ($h) => ['title' => $h->title, 'section' => $h->section->label(), 'due_on' => $h->due_on->format('Y-m-d'), 'submissions' => $h->submissions_count])->all()];
                }],
            'academic.exam_results' => ['permission' => 'marks.enter', 'description' => 'Results of an exam for the sections you can see: average, pass rate and top students per paper.',
                'parameters' => self::schema(['exam' => self::str('Exam name, for example "Half yearly"; default the latest exam')]), 'run' => function (array $a) {
                    $exam = \Modules\Academic\Models\Exam::where('session_id', $this->session()->id)
                        ->when(! empty($a['exam']), fn ($q) => $q->whereLike('name', '%'.$a['exam'].'%'))->orderByDesc('starts_on')->first()
                        ?? throw new AiException('No exam found in this session.');
                    $papers = ExamPaper::with(['section.level', 'subject'])->where('exam_id', $exam->id)->whereIn('section_id', $this->visibleSections()->pluck('id'))->get();
                    $out = [];
                    foreach ($papers as $p) {
                        $marks = Mark::where('paper_id', $p->id)->where('is_absent', false)->whereNotNull('marks')->get();
                        if ($marks->isEmpty()) {
                            continue;
                        }
                        $top = $marks->sortByDesc('marks')->take(3);
                        $names = Student::whereIn('id', $top->pluck('student_id'))->get()->keyBy('id');
                        $out[] = ['section' => $p->section->label(), 'subject' => $p->subject->name, 'max' => (float) $p->max_marks, 'average' => round((float) $marks->avg('marks'), 1),
                            'pass_percent' => round($marks->filter(fn ($m) => (float) $m->marks >= (float) $p->pass_marks)->count() * 100 / $marks->count(), 1),
                            'top' => $top->map(fn ($m) => ['name' => $names[$m->student_id]?->fullName(), 'marks' => (float) $m->marks])->values()->all()];
                    }

                    return ['exam' => $exam->name, 'status' => $exam->status, 'papers' => $out];
                }],
        ];
    }

    /* ---------------- admissions ---------------- */

    private function admissionTools(): array
    {
        return [
            'admission.funnel' => ['permission' => 'admissions.manage', 'description' => 'Admissions this session by stage and by source, with the conversion rate.',
                'parameters' => self::schema(), 'run' => fn () => ['session' => $this->session()->name] + app(AdmissionService::class)->funnel($this->session()->id)],
            'admission.follow_ups' => ['permission' => 'admissions.manage', 'description' => 'Open enquiries and applications whose follow-up date is today or overdue.',
                'parameters' => self::schema(), 'run' => function () {
                    $list = AdmissionApplication::with('level')->whereNotIn('stage', ['admitted', 'rejected', 'withdrawn'])->whereNotNull('follow_up_on')
                        ->where('follow_up_on', '<=', today()->format('Y-m-d'))->orderBy('follow_up_on')->limit(40)->get();

                    return ['follow_ups' => $list->map(fn ($x) => ['number' => $x->number, 'name' => $x->fullName(), 'class' => $x->level?->name, 'stage' => $x->stage,
                        'phone' => $x->phone, 'follow_up_on' => $x->follow_up_on->format('Y-m-d')])->all()];
                }],
            'admission.create_enquiry' => ['permission' => 'admissions.manage', 'description' => 'Record a new admission enquiry (a normal record your role could enter by hand).',
                'parameters' => self::schema(['child_first_name' => self::str('Child\'s first name'), 'child_last_name' => self::str('Child\'s last name'),
                    'class' => self::str('Class or level applied for, for example "Class 5"'), 'parent_name' => self::str('Parent name'),
                    'relation' => self::str('father, mother or guardian'), 'phone' => self::str('10-digit mobile number'),
                    'source' => self::str('walk_in, phone, website, referral, social or event'), 'follow_up_on' => self::date('Next follow-up')],
                    ['child_first_name', 'class', 'parent_name', 'phone']), 'run' => function (array $a) {
                    $level = GradeLevel::where('is_active', true)->where(fn ($q) => $q->where('code', $a['class'] ?? '')->orWhereLike('name', (string) ($a['class'] ?? '')))->first()
                        ?? throw new AiException('No class called "'.($a['class'] ?? '').'". Use the name shown in the institution setup.');
                    $relation = in_array($a['relation'] ?? '', array_keys(config('academic.relations')), true) ? $a['relation'] : array_key_first(config('academic.relations'));
                    $app = app(AdmissionService::class)->create(['session_id' => $this->session()->id,
                        'branch_id' => Branch::where('is_active', true)->orderByDesc('is_primary')->value('id'), 'grade_level_id' => $level->id,
                        'first_name' => $a['child_first_name'], 'last_name' => $a['child_last_name'] ?? null,
                        'guardians' => [['name' => $a['parent_name'], 'relation' => $relation, 'phone' => preg_replace('/\D/', '', (string) $a['phone'])]],
                        'source' => in_array($a['source'] ?? '', array_keys(config('academic.admission.sources')), true) ? $a['source'] : 'phone',
                        'follow_up_on' => self::day($a['follow_up_on'] ?? null, today()->addDays(2)->format('Y-m-d'))], 'enquiry');

                    return ['created' => true, 'number' => $app->number, 'name' => $app->fullName(), 'class' => $level->name];
                }],
        ];
    }

    /* ---------------- fees ---------------- */

    private function feeTools(): array
    {
        return [
            'fees.dues' => ['permission' => 'fees.view', 'description' => 'Fees outstanding as on a date: total, ageing buckets and the students who owe the most.',
                'parameters' => self::schema(['as_on' => self::date('Default today'), 'limit' => ['type' => 'integer', 'description' => 'How many students, default 20']]), 'run' => function (array $a) {
                    $rows = app(FeeReports::class)->outstanding(self::day($a['as_on'] ?? null, today()->format('Y-m-d')));
                    $sum = fn ($k) => (int) $rows->sum($k);

                    return ['total' => self::money($sum('total')), 'students' => $rows->count(),
                        'ageing' => collect(['not_due', 'd30', 'd60', 'd90', 'd90p'])->mapWithKeys(fn ($k) => [$k => self::money($sum($k))])->all(),
                        'top' => $rows->take(max(1, min(100, (int) ($a['limit'] ?? 20))))->map(fn ($r) => $this->studentRow($r['student']) + ['due' => self::money($r['total']),
                            'overdue_90_plus' => self::money($r['d90p']), 'guardian_phone' => $r['student']->guardians->first()?->phone])->values()->all()];
                }],
            'fees.collections' => ['permission' => 'fees.view', 'description' => 'Fees collected between two dates, by payment mode, with refunds.',
                'parameters' => self::schema(['from' => self::date('Default first of this month'), 'to' => self::date('Default today')]), 'run' => function (array $a) {
                    $from = self::day($a['from'] ?? null, today()->startOfMonth()->format('Y-m-d'));
                    $to = self::day($a['to'] ?? null, today()->format('Y-m-d'));
                    $c = app(FeeReports::class)->collections($from, $to);

                    return ['from' => $from, 'to' => $to, 'total' => self::money($c['total']), 'refunded' => self::money($c['refunded']), 'receipts' => $c['list']->where('status', 'success')->count(),
                        'by_mode' => collect($c['by_mode'])->map(fn ($v) => self::money((int) $v))->all()];
                }],
            'fees.student_account' => ['permission' => 'fees.view', 'description' => 'A student\'s fee account: open invoices with balances and recent receipts.',
                'parameters' => self::schema(['student' => self::str('Admission number, name or id')], ['student']), 'run' => function (array $a) {
                    $s = $this->findStudent($a);

                    return $this->studentRow($s) + ['due' => self::money($this->feeBalance($s->id)),
                        'open_invoices' => FeeInvoice::where('student_id', $s->id)->whereIn('status', ['open', 'partial'])->orderBy('due_date')->get()
                            ->map(fn ($i) => ['id' => $i->id, 'number' => $i->number, 'period' => $i->period, 'due_date' => $i->due_date->format('Y-m-d'), 'balance' => self::money($i->balance())])->all(),
                        'receipts' => FeePayment::where('student_id', $s->id)->orderByDesc('date')->limit(10)->get()
                            ->map(fn ($p) => ['id' => $p->id, 'number' => $p->number, 'date' => $p->date->format('Y-m-d'), 'mode' => $p->mode, 'status' => $p->status,
                                'amount' => self::money($p->amount), 'refundable' => self::money($p->refundable())])->all()];
                }],
            'fees.propose_refund' => ['permission' => 'fees.collect', 'description' => 'Propose refunding part of a fee receipt. Creates a request for an approver; nothing is refunded until a person approves it.',
                'parameters' => self::schema(['receipt' => self::str('Receipt number or id'), 'amount' => self::str('Amount in rupees, for example "1500"'), 'reason' => self::str('Why')], ['receipt', 'amount', 'reason']),
                'run' => function (array $a) {
                    $p = FeePayment::where('number', $a['receipt'] ?? '')->orWhere(fn ($q) => Str::isUuid((string) ($a['receipt'] ?? '')) ? $q->whereKey($a['receipt']) : $q->whereRaw('1 = 0'))->first()
                        ?? throw new AiException('No receipt '.($a['receipt'] ?? '').'.');
                    $amount = Amount::parse((string) ($a['amount'] ?? ''));
                    if ($amount <= 0 || $amount > $p->refundable()) {
                        throw new AiException('The refund must be more than zero and at most '.Amount::format($p->refundable()).' on receipt '.$p->number.'.');
                    }

                    return app(Proposals::class)->propose('fees.refund', 'fees.approve', 'Refund '.Amount::format($amount).' on receipt '.$p->number.' ('.$p->student->fullName().'): '.trim((string) $a['reason']),
                        ['payment_id' => $p->id, 'amount' => $amount, 'reason' => trim((string) $a['reason'])]);
                }],
            'fees.propose_waiver' => ['permission' => 'fees.collect', 'description' => 'Propose waiving part of a fee invoice (for example a late fee). Creates a request for an approver.',
                'parameters' => self::schema(['invoice' => self::str('Fee invoice number or id'), 'amount' => self::str('Amount in rupees'), 'reason' => self::str('Why')], ['invoice', 'amount', 'reason']),
                'run' => function (array $a) {
                    $inv = FeeInvoice::where('number', $a['invoice'] ?? '')->orWhere(fn ($q) => Str::isUuid((string) ($a['invoice'] ?? '')) ? $q->whereKey($a['invoice']) : $q->whereRaw('1 = 0'))->first()
                        ?? throw new AiException('No fee invoice '.($a['invoice'] ?? '').'.');
                    $amount = Amount::parse((string) ($a['amount'] ?? ''));
                    if ($amount <= 0 || $amount > $inv->balance()) {
                        throw new AiException('The waiver must be more than zero and at most the balance of '.Amount::format($inv->balance()).'.');
                    }

                    return app(Proposals::class)->propose('fees.waiver', 'fees.approve', 'Waive '.Amount::format($amount).' on '.$inv->number.' ('.$inv->student->fullName().'): '.trim((string) $a['reason']),
                        ['invoice_id' => $inv->id, 'amount' => $amount, 'reason' => trim((string) $a['reason'])]);
                }],
        ];
    }

    /* ---------------- accounting ---------------- */

    private function accountingTools(): array
    {
        $tree = function ($nodes) use (&$tree) {
            return collect($nodes)->filter(fn ($n) => $n['total'] !== 0)->map(fn ($n) => ['group' => $n['group']->name, 'total' => self::money($n['total']),
                'ledgers' => collect($n['ledgers'])->map(fn ($l) => ['name' => $l['ledger']->name, 'amount' => self::money($l['amount'])])->values()->all(),
                'subgroups' => $tree($n['children'] ?? [])])->values()->all();
        };
        $range = fn (array $a) => [self::day($a['from'] ?? null, $this->fyStart()), self::day($a['to'] ?? null, today()->format('Y-m-d'))];

        return [
            'accounting.trial_balance' => ['permission' => 'accounts.view', 'description' => 'Trial balance for a period: each ledger\'s opening, debits, credits and closing balance.',
                'parameters' => self::schema(['from' => self::date('Default start of financial year'), 'to' => self::date('Default today')]), 'run' => function (array $a) use ($range) {
                    [$from, $to] = $range($a);
                    $tb = app(Books::class)->trialBalance($from, $to);

                    return ['from' => $from, 'to' => $to, 'agrees' => $tb['debit'] === $tb['credit'] && $tb['closing_dr'] === $tb['closing_cr'], 'total_debit' => self::money($tb['debit']), 'total_credit' => self::money($tb['credit']),
                        'rows' => $tb['rows']->map(fn ($r) => ['ledger' => $r['ledger']->name, 'closing' => self::money($r['closing'])])->values()->all()];
                }],
            'accounting.profit_loss' => ['permission' => 'accounts.view', 'description' => 'Income and expenditure (profit and loss) for a period, by group and ledger.',
                'parameters' => self::schema(['from' => self::date('Default start of financial year'), 'to' => self::date('Default today')]), 'run' => function (array $a) use ($range, $tree) {
                    [$from, $to] = $range($a);
                    $pl = app(Books::class)->profitAndLoss($from, $to);

                    return ['from' => $from, 'to' => $to, 'income' => $tree($pl['income']), 'expense' => $tree($pl['expense']),
                        'total_income' => self::money($pl['total_income']), 'total_expense' => self::money($pl['total_expense']), 'surplus' => self::money($pl['surplus'])];
                }],
            'accounting.balance_sheet' => ['permission' => 'accounts.view', 'description' => 'Balance sheet as on a date.',
                'parameters' => self::schema(['as_on' => self::date('Default today')]), 'run' => function (array $a) use ($tree) {
                    $on = self::day($a['as_on'] ?? null, today()->format('Y-m-d'));
                    $bs = app(Books::class)->balanceSheet($on);

                    return ['as_on' => $on, 'assets' => $tree($bs['assets']), 'liabilities' => $tree($bs['liabilities']), 'equity' => $tree($bs['equity']),
                        'surplus' => self::money($bs['surplus']), 'total_assets' => self::money($bs['total_assets']), 'balances' => $bs['difference'] === 0];
                }],
            'accounting.ledger' => ['permission' => 'accounts.view', 'description' => 'A ledger\'s balance and its latest entries. Search by ledger name or code.',
                'parameters' => self::schema(['ledger' => self::str('Ledger name or code, for example "Electricity" or "BANK"')], ['ledger']), 'run' => function (array $a) {
                    $q = trim((string) ($a['ledger'] ?? ''));
                    $l = Ledger::where('code', strtoupper($q))->first() ?? Ledger::whereLike('name', "%{$q}%")->orderBy('name')->first() ?? throw new AiException("No ledger matching \"{$q}\".");
                    $st = app(Books::class)->statement($l, $this->fyStart(), today()->format('Y-m-d'));

                    return ['ledger' => $l->name, 'code' => $l->code, 'balance' => Amount::drcr(app(Books::class)->balance($l, today()->format('Y-m-d'))),
                        'latest' => $st['rows']->take(-10)->map(fn ($r) => ['date' => $r['entry']->date->format('Y-m-d'), 'voucher' => $r['voucher']->number, 'against' => $r['against'],
                            'debit' => self::money((int) $r['entry']->debit), 'credit' => self::money((int) $r['entry']->credit)])->values()->all()];
                }],
            'accounting.vouchers' => ['permission' => 'accounts.view', 'description' => 'Find vouchers by type, status, date range or minimum amount.',
                'parameters' => self::schema(['type' => self::str('payment, receipt, contra, journal, sales, purchase …'), 'status' => self::str('draft, pending, posted, reversed'),
                    'from' => self::date('From'), 'to' => self::date('To'), 'min_amount' => self::str('Minimum amount in rupees')]), 'run' => function (array $a) {
                    $list = Voucher::query()->when(! empty($a['type']), fn ($q) => $q->where('type', $a['type']))->when(! empty($a['status']), fn ($q) => $q->where('status', $a['status']))
                        ->when(! empty($a['from']), fn ($q) => $q->where('date', '>=', self::day($a['from'], '1900-01-01')))->when(! empty($a['to']), fn ($q) => $q->where('date', '<=', self::day($a['to'], '2999-12-31')))
                        ->when(! empty($a['min_amount']), fn ($q) => $q->where('total', '>=', Amount::parse((string) $a['min_amount'])))->orderByDesc('date')->limit(30)->get();

                    return ['vouchers' => $list->map(fn ($v) => ['number' => $v->number, 'type' => $v->type, 'date' => $v->date->format('Y-m-d'), 'status' => $v->status,
                        'amount' => self::money($v->total), 'narration' => $v->narration])->all()];
                }],
            'accounting.outstanding_bills' => ['permission' => 'accounts.view', 'description' => 'Open sales and purchase bills with what is still to be received or paid.',
                'parameters' => self::schema(), 'run' => function () {
                    $svc = app(InvoiceService::class);
                    $rows = Invoice::with('party')->where('status', 'posted')->whereIn('kind', ['sales', 'purchase', 'expense'])->get()
                        ->map(fn ($i) => ['number' => $i->number, 'kind' => $i->kind, 'party' => $i->party->name, 'date' => $i->date->format('Y-m-d'), 'open' => $svc->outstanding($i)])
                        ->filter(fn ($r) => $r['open'] > 0)->sortByDesc('open')->take(40)->map(fn ($r) => ['open' => self::money($r['open'])] + $r)->values();

                    return ['bills' => $rows->all()];
                }],
            'accounting.anomalies' => ['permission' => 'accounts.view', 'description' => 'Checks the books for unusual entries: duplicate payments, payments split under the approval limit, '
                .'large cash payments, self-approved or back-dated vouchers, round amounts, spikes against a ledger\'s usual monthly spend, frequent reversals and large fee waivers.',
                'parameters' => self::schema(['from' => self::date('Default start of financial year'), 'to' => self::date('Default today')]), 'run' => function (array $a) use ($range) {
                    [$from, $to] = $range($a);
                    $found = app(AnomalyDetector::class)->scan($from, $to);

                    return ['from' => $from, 'to' => $to, 'count' => count($found), 'findings' => $found];
                }],
            'accounting.propose_voucher' => ['permission' => 'accounts.post', 'description' => 'Propose a payment, receipt, contra or journal voucher. It is checked now (debits must equal credits) '
                .'but only posted after an authorised person approves it in the ERP.',
                'parameters' => self::schema(['type' => self::str('payment, receipt, contra or journal'), 'date' => self::date('Voucher date, default today'), 'narration' => self::str('Narration'),
                    'reference' => self::str('Cheque, UTR or bill number'),
                    'lines' => ['type' => 'array', 'description' => 'Each line has a ledger (name or code) and either debit or credit in rupees',
                        'items' => ['type' => 'object', 'properties' => ['ledger' => ['type' => 'string'], 'debit' => ['type' => 'string'], 'credit' => ['type' => 'string']], 'required' => ['ledger']]]],
                    ['type', 'lines', 'narration']), 'run' => function (array $a) {
                    $type = (string) ($a['type'] ?? '');
                    if (! in_array($type, config('accounting.manual_types'), true)) {
                        throw new AiException('The type must be payment, receipt, contra or journal.');
                    }
                    $lines = [];
                    foreach ((array) ($a['lines'] ?? []) as $l) {
                        $q = trim((string) ($l['ledger'] ?? ''));
                        $ledger = Ledger::where('is_active', true)->where(fn ($w) => $w->where('code', strtoupper($q))->orWhereLike('name', $q))->first()
                            ?? throw new AiException("No active ledger called \"{$q}\". Use the ledger's exact name or code.");
                        $lines[] = ['ledger_id' => $ledger->id, 'ledger' => $ledger->name, 'debit' => Amount::parse((string) ($l['debit'] ?? '')), 'credit' => Amount::parse((string) ($l['credit'] ?? ''))];
                    }
                    $dr = array_sum(array_column($lines, 'debit'));
                    $cr = array_sum(array_column($lines, 'credit'));
                    if (count($lines) < 2 || $dr <= 0 || $dr !== $cr) {
                        throw new AiException('Debits ('.Amount::format($dr).') must equal credits ('.Amount::format($cr).') across at least two lines.');
                    }
                    $date = self::day($a['date'] ?? null, today()->format('Y-m-d'));
                    $payload = ['type' => $type, 'date' => $date, 'narration' => mb_substr((string) $a['narration'], 0, 500), 'reference' => mb_substr((string) ($a['reference'] ?? ''), 0, 60) ?: null, 'lines' => $lines];

                    return app(Proposals::class)->propose('accounting.voucher', 'accounts.approve',
                        ucfirst($type).' voucher '.Amount::format($dr).' on '.$date.': '.$payload['narration'], $payload);
                }],
        ];
    }

    /* ---------------- payroll ---------------- */

    private function payrollTools(): array
    {
        return [
            'payroll.runs' => ['permission' => 'payroll.process', 'description' => 'Payroll runs with status and totals (gross, deductions, net, employer cost).',
                'parameters' => self::schema(), 'run' => fn () => ['runs' => PayrollRun::orderByDesc('period')->limit(12)->get()->map(fn ($r) => ['period' => $r->period, 'status' => $r->status,
                    'employees' => $r->employees, 'gross' => self::money((int) $r->gross), 'deductions' => self::money((int) $r->deductions), 'net' => self::money((int) $r->net),
                    'employer_cost' => self::money((int) $r->employer_cost)])->all()]],
            'payroll.run_detail' => ['permission' => 'payroll.process', 'description' => 'One month\'s payroll: totals of each earning and deduction (PF, ESI, professional tax, TDS) and the largest payslips.',
                'parameters' => self::schema(['period' => self::str('Month as YYYY-MM, default the latest run')]), 'run' => function (array $a) {
                    $run = PayrollRun::when(! empty($a['period']), fn ($q) => $q->where('period', $a['period']))->orderByDesc('period')->first() ?? throw new AiException('No payroll run found.');
                    $slips = $run->payslips()->with('staff')->get();
                    $tot = [];
                    foreach (['earnings', 'deductions', 'employer'] as $part) {
                        $acc = [];
                        foreach ($slips as $s) {
                            foreach ((array) $s->{$part} as $k => $v) {
                                $acc[$k] = ($acc[$k] ?? 0) + (int) $v;
                            }
                        }
                        $tot[$part] = array_map(fn ($v) => self::money($v), $acc);
                    }

                    return ['period' => $run->period, 'status' => $run->status, 'employees' => $slips->count(), 'net' => self::money((int) $run->net)] + $tot + [
                        'largest' => $slips->sortByDesc('net')->take(5)->map(fn ($s) => ['employee' => $s->staff->name, 'net' => self::money((int) $s->net), 'lop_days' => (float) $s->lop_days])->values()->all()];
                }],
            'payroll.leave_balances' => ['permission' => 'hr.view', 'description' => 'Leave balances of an employee this year. Search by name or employee code.',
                'parameters' => self::schema(['employee' => self::str('Name or employee code')], ['employee']), 'run' => function (array $a) {
                    $q = trim((string) ($a['employee'] ?? ''));
                    $staff = StaffMember::where('employee_code', $q)->orWhereLike('name', "%{$q}%")->first() ?? throw new AiException("No employee matching \"{$q}\".");

                    return ['employee' => $staff->name, 'code' => $staff->employee_code, 'balances' => collect(app(LeaveService::class)->balances($staff))
                        ->map(fn ($b) => ['type' => $b['type']->name, 'allotted' => $b['allotted'], 'used' => $b['used'], 'balance' => $b['balance']])->all()];
                }],
            'payroll.propose_approval' => ['permission' => 'payroll.process', 'description' => 'Ask for a processed payroll to be approved. Creates a request; the payroll is approved and posted only when an authorised person approves it.',
                'parameters' => self::schema(['period' => self::str('Month as YYYY-MM')], ['period']), 'run' => function (array $a) {
                    $run = PayrollRun::where('period', $a['period'] ?? '')->first() ?? throw new AiException('No payroll for '.($a['period'] ?? '').'.');
                    if ($run->status !== 'processed') {
                        throw new AiException("The {$run->period} payroll is {$run->status}; only a processed payroll can be sent for approval.");
                    }

                    return app(Proposals::class)->propose('payroll.approve', 'payroll.approve', "Approve payroll {$run->period}: {$run->employees} employees, net ".Amount::format((int) $run->net),
                        ['run_id' => $run->id, 'period' => $run->period, 'net' => (int) $run->net]);
                }],
        ];
    }

    /* ---------------- documents and voice ---------------- */

    private function documentTools(): array
    {
        $mine = fn () => AiDocument::query()->when(! $this->member->can('ai.approve'), fn ($q) => $q->where('uploaded_by', $this->member->userId()));

        return [
            'documents.list' => ['permission' => 'ai.use', 'description' => 'Documents uploaded for AI reading, newest first, with their status.',
                'parameters' => self::schema(), 'run' => fn () => ['documents' => $mine()->latest()->limit(20)->get()->map(fn ($d) => ['id' => $d->id, 'type' => $d->doc_type,
                    'name' => $d->original_name, 'status' => $d->status, 'uploaded' => $d->created_at->format('Y-m-d')])->all()]],
            'documents.get' => ['permission' => 'ai.use', 'description' => 'The fields read from one uploaded document, with missing or doubtful fields.',
                'parameters' => self::schema(['id' => self::str('Document id')], ['id']), 'run' => function (array $a) use ($mine) {
                    $d = Str::isUuid((string) ($a['id'] ?? '')) ? $mine()->find($a['id']) : null;
                    if (! $d) {
                        throw new AiException('No such document that you can see.');
                    }

                    return ['id' => $d->id, 'type' => $d->doc_type, 'status' => $d->status, 'extracted' => $d->extracted, 'error' => $d->error];
                }],
        ];
    }

    private function voiceTools(): array
    {
        return [
            'voice.call_list' => ['permission' => 'fees.view', 'description' => 'Who to call today: guardians of students with overdue fees who have not been called in the last 7 days.',
                'parameters' => self::schema(['limit' => ['type' => 'integer', 'description' => 'Default 20']]), 'run' => function (array $a) {
                    $recent = AiCallLog::where('created_at', '>=', now()->subDays(7))->whereNotNull('student_id')->pluck('student_id')->all();
                    $rows = app(FeeReports::class)->outstanding(today()->format('Y-m-d'))->filter(fn ($r) => $r['total'] - $r['not_due'] > 0 && ! in_array($r['student']->id, $recent, true));

                    return ['calls' => $rows->take(max(1, min(50, (int) ($a['limit'] ?? 20))))->map(fn ($r) => $this->studentRow($r['student']) + [
                        'overdue' => self::money($r['total'] - $r['not_due']), 'guardian' => $r['student']->guardians->first()?->name, 'phone' => $r['student']->guardians->first()?->phone])->values()->all()];
                }],
            'voice.log_call' => ['permission' => null, 'when' => fn () => $this->member->can('fees.view') || $this->member->can('admissions.manage'),
                'description' => 'Record the outcome of a call with a parent: who, why, what happened and the next step.',
                'parameters' => self::schema(['purpose' => self::str('fee_reminder, admission_follow_up or other'), 'student' => self::str('Admission number or name, for a fee call'),
                    'application' => self::str('Admission application number, for an admission call'), 'phone' => self::str('Number called'),
                    'outcome' => self::str(implode(', ', AiCallLog::OUTCOMES)), 'notes' => self::str('Short notes'), 'next_action_on' => self::date('Next follow-up')], ['purpose', 'phone', 'outcome']),
                'run' => function (array $a) {
                    $purpose = in_array($a['purpose'] ?? '', ['fee_reminder', 'admission_follow_up', 'other'], true) ? $a['purpose'] : 'other';
                    $outcome = in_array($a['outcome'] ?? '', AiCallLog::OUTCOMES, true) ? $a['outcome'] : throw new AiException('The outcome must be one of: '.implode(', ', AiCallLog::OUTCOMES).'.');
                    $student = ! empty($a['student']) ? $this->findStudent($a) : null;
                    $app = ! empty($a['application']) ? AdmissionApplication::where('number', $a['application'])->first() : null;
                    $log = AiCallLog::create(['purpose' => $purpose, 'student_id' => $student?->id, 'application_id' => $app?->id, 'phone' => mb_substr(preg_replace('/[^\d+]/', '', (string) $a['phone']), 0, 15),
                        'outcome' => $outcome, 'notes' => mb_substr((string) ($a['notes'] ?? ''), 0, 500) ?: null,
                        'next_action_on' => ! empty($a['next_action_on']) ? self::day($a['next_action_on'], today()->addDay()->format('Y-m-d')) : null,
                        'conversation_id' => app(\Modules\Ai\Services\ToolContext::class)->conversationId, 'logged_by' => $this->member->userId()]);
                    if ($app && $log->next_action_on) {
                        $app->update(['follow_up_on' => $log->next_action_on]);
                    }

                    return ['logged' => true, 'id' => $log->id];
                }],
        ];
    }
}
