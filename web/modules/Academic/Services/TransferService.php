<?php

namespace Modules\Academic\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Academic\Exceptions\AcademicRuleException;
use Modules\Academic\Models\Enrollment;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\Student;
use Modules\Academic\Models\StudentTransfer;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\Institution;
use Modules\Academic\Support\Rules;

/**
 * Moves within the institution (another section, another branch) and leaving it
 * (transfer to another school, withdrawal). Enrolment history is kept.
 */
final class TransferService
{
    public function __construct(private readonly StudentService $students)
    {
    }

    /** Section change in the same session; a section in another branch makes it a branch transfer. */
    public function move(Student $student, array $data): StudentTransfer
    {
        $d = Rules::check($data, [
            'section_id' => ['required', Rules::exists('sections', ['is_active' => true])],
            'effective_on' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:200'],
            'roll_no' => ['nullable', 'integer', 'between:1,9999'],
        ]);
        $to = Section::with('session')->findOrFail($d['section_id']);
        $current = $student->currentEnrollment()->with('section')->first();
        if ($student->status !== 'active' || ! $current) {
            throw new AcademicRuleException("{$student->fullName()} has no active enrolment to move.");
        }
        if ($current->section_id === $to->id) {
            throw new AcademicRuleException('The student is already in this '.strtolower(Institution::term('section')).'.', 'section_id');
        }
        if ($current->session_id !== $to->session_id) {
            throw new AcademicRuleException('Moves are within the same session. Use promotion to move to the next session.', 'section_id');
        }
        $date = Carbon::parse($d['effective_on']);
        if ($date->lt($current->joined_on)) {
            throw new AcademicRuleException('The move date is before the student joined the current '.strtolower(Institution::term('section')).'.', 'effective_on');
        }
        $this->students->checkCapacity($to, 1);

        return DB::transaction(function () use ($student, $current, $to, $date, $d) {
            $fromBranch = $student->branch_id;
            $current->update(['status' => 'section_changed', 'left_on' => $date->copy()->subDay()->max($current->joined_on)]);
            if ($to->branch_id !== $fromBranch) {
                $student->update(['branch_id' => $to->branch_id]);
            }
            $this->students->enroll($student->refresh(), $to, $d['roll_no'] ?? null, $date);

            $t = StudentTransfer::create([
                'student_id' => $student->id, 'type' => $to->branch_id !== $fromBranch ? 'branch' : 'section',
                'from_section_id' => $current->section_id, 'to_section_id' => $to->id,
                'from_branch_id' => $fromBranch, 'to_branch_id' => $to->branch_id,
                'effective_on' => $date, 'reason' => $d['reason'], 'created_by' => request()->user()?->getAuthIdentifier(),
            ]);
            Audit::record('student.moved', $student, ['type' => $t->type, 'to' => $to->id]);

            return $t;
        });
    }

    /** Student leaves the institution: transferred to another school, or withdrawn. */
    public function leave(Student $student, array $data): StudentTransfer
    {
        $d = Rules::check($data, [
            'status' => ['required', Rule::in(['transferred', 'withdrawn'])],
            'left_on' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:200'],
            'conduct' => ['nullable', 'string', 'max:40'],
        ]);
        if ($student->status !== 'active') {
            throw new AcademicRuleException("{$student->fullName()} is already {$student->status}.");
        }
        $current = $student->currentEnrollment;
        if ($current && Carbon::parse($d['left_on'])->lt($current->joined_on)) {
            throw new AcademicRuleException('The leaving date is before the student joined.', 'left_on');
        }

        return DB::transaction(function () use ($student, $current, $d) {
            $current?->update(['status' => $d['status'], 'left_on' => $d['left_on']]);
            $student->update(['status' => $d['status'], 'left_on' => $d['left_on'], 'leaving_reason' => $d['reason'], 'conduct' => $d['conduct'] ?? $student->conduct ?? 'Good']);
            $t = StudentTransfer::create([
                'student_id' => $student->id, 'type' => 'out', 'from_section_id' => $current?->section_id, 'from_branch_id' => $student->branch_id,
                'effective_on' => $d['left_on'], 'reason' => $d['reason'], 'created_by' => request()->user()?->getAuthIdentifier(),
            ]);
            Audit::record('student.left', $student, ['status' => $d['status'], 'reason' => $d['reason']]);

            return $t;
        });
    }
}
