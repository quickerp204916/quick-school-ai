<?php

namespace Modules\Academic\Services;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Exceptions\AcademicRuleException;
use Modules\Academic\Models\Enrollment;
use Modules\Academic\Models\PromotionRun;
use Modules\Academic\Models\ReportCard;
use Modules\Academic\Models\Section;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\Institution;
use Modules\Institution\Models\AcademicSession;

/**
 * Year-end promotion of a section into the next session:
 * promote to the next level, retain in the same level, graduate from the final level, or mark as left.
 */
final class PromotionService
{
    public const ACTIONS = ['promote', 'retain', 'graduate', 'leave', 'skip'];

    /** Suggested decision per student, from their latest published report card. */
    public function preview(Section $from, AcademicSession $to): array
    {
        $this->checkSessions($from, $to);
        $next = $from->level->next();
        $rows = [];
        foreach ($from->activeEnrollments()->with('student')->get()->sortBy(fn ($e) => [$e->roll_no ?? 9999, $e->student->first_name]) as $e) {
            $card = ReportCard::where('student_id', $e->student_id)->where('session_id', $from->session_id)->latest('published_at')->first();
            $action = match (true) {
                $card?->result === 'fail' => 'retain',
                $next === null => 'graduate',
                default => 'promote',
            };
            $rows[] = ['enrollment' => $e, 'student' => $e->student, 'report_card' => $card, 'suggested' => $action,
                'reason' => $card ? "Result {$card->result} in {$card->title}" : 'No published report card'];
        }

        return ['rows' => $rows, 'next_level' => $next,
            'targets' => [
                'promote' => $next ? Section::with('level')->where('session_id', $to->id)->where('grade_level_id', $next->id)->where('is_active', true)->get() : collect(),
                'retain' => Section::with('level')->where('session_id', $to->id)->where('grade_level_id', $from->grade_level_id)->where('is_active', true)->get(),
            ]];
    }

    /**
     * @param array<string, array{action: string, section_id?: ?string}> $decisions keyed by student id
     * @return array<string, int> counts per action
     */
    public function run(Section $from, AcademicSession $to, array $decisions): array
    {
        $this->checkSessions($from, $to);
        $from->loadMissing(['level', 'session']);
        $next = $from->level->next();
        $enrollments = $from->activeEnrollments()->with('student')->get()->keyBy('student_id');
        $counts = array_fill_keys(self::ACTIONS, 0);
        $adding = [];

        foreach ($decisions as $studentId => $dec) {
            $action = $dec['action'] ?? '';
            $name = $enrollments->get($studentId)?->student?->fullName() ?? 'A student';
            if (! isset($enrollments[$studentId])) {
                throw new AcademicRuleException('A student in the list is no longer enrolled in this '.strtolower(Institution::term('section')).'.');
            }
            if (! in_array($action, self::ACTIONS, true)) {
                throw new AcademicRuleException("Choose what happens to {$name}.");
            }
            if (in_array($action, ['promote', 'retain'], true)) {
                $target = Section::find($dec['section_id'] ?? null);
                $wantLevel = $action === 'promote' ? $next?->id : $from->grade_level_id;
                if ($action === 'promote' && ! $next) {
                    throw new AcademicRuleException("{$from->level->name} is the final ".strtolower(Institution::term('level')).". Choose graduate for {$name}.");
                }
                if (! $target || $target->session_id !== $to->id || $target->grade_level_id !== $wantLevel) {
                    throw new AcademicRuleException("Choose a ".strtolower(Institution::term('section'))." of ".($action === 'promote' ? $next->name : $from->level->name)." in session {$to->name} for {$name}.");
                }
                if (Enrollment::where('student_id', $studentId)->where('session_id', $to->id)->where('status', 'active')->exists()) {
                    throw new AcademicRuleException("{$name} is already enrolled in session {$to->name}.");
                }
                $adding[$target->id] = ($adding[$target->id] ?? 0) + 1;
            }
            if ($action === 'graduate' && $next) {
                throw new AcademicRuleException("Only students in the final ".strtolower(Institution::term('level'))." can graduate. Promote or retain {$name}.");
            }
        }
        foreach ($adding as $sectionId => $n) {
            $s = Section::with('level')->find($sectionId);
            if ($s->capacity !== null && $s->activeCount() + $n > $s->capacity) {
                throw new AcademicRuleException("{$s->label()} has room for ".max(0, $s->capacity - $s->activeCount())." more students, but {$n} are being moved in.");
            }
        }

        return DB::transaction(function () use ($from, $to, $decisions, $enrollments, $counts) {
            $leftOn = $from->session->ends_on->min(today()->max($from->session->starts_on));
            foreach ($decisions as $studentId => $dec) {
                $e = $enrollments[$studentId];
                $action = $dec['action'];
                $counts[$action]++;
                if ($action === 'skip') {
                    continue;
                }
                $e->update(['status' => ['promote' => 'promoted', 'retain' => 'retained', 'graduate' => 'completed', 'leave' => 'withdrawn'][$action], 'left_on' => $leftOn]);
                if (in_array($action, ['promote', 'retain'], true)) {
                    $target = Section::find($dec['section_id']);
                    if ($target->branch_id !== $e->student->branch_id) {
                        $e->student->update(['branch_id' => $target->branch_id]);
                    }
                    Enrollment::create(['student_id' => $studentId, 'session_id' => $to->id, 'section_id' => $target->id,
                        'status' => 'active', 'joined_on' => $to->starts_on]);
                } else {
                    $e->student->update(['status' => $action === 'graduate' ? 'alumni' : 'withdrawn', 'left_on' => $leftOn,
                        'leaving_reason' => $action === 'graduate' ? 'Completed '.$from->level->name : 'Did not continue']);
                }
            }
            $run = PromotionRun::create(['from_section_id' => $from->id, 'to_session_id' => $to->id, 'counts' => $counts,
                'created_by' => request()->user()?->getAuthIdentifier()]);
            Audit::record('promotion.run', $run, $counts);

            return $counts;
        });
    }

    private function checkSessions(Section $from, AcademicSession $to): void
    {
        $from->loadMissing('session', 'level');
        if ($to->id === $from->session_id || $to->starts_on->lte($from->session->starts_on)) {
            throw new AcademicRuleException('Promote into a later session than '.$from->session->name.'.', 'to_session_id');
        }
        if ($to->status === 'closed') {
            throw new AcademicRuleException("Session {$to->name} is closed.", 'to_session_id');
        }
    }
}
