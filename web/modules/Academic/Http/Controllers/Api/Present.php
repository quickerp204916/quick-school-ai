<?php

namespace Modules\Academic\Http\Controllers\Api;

use Modules\Academic\Models\Homework;
use Modules\Academic\Models\ReportCard;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\Student;

/** JSON shapes for the mobile app. Dates are YYYY-MM-DD. */
final class Present
{
    public static function section(Section $s): array
    {
        return ['id' => $s->id, 'label' => $s->label(), 'name' => $s->name, 'level' => $s->level?->name, 'level_id' => $s->grade_level_id,
            'session_id' => $s->session_id, 'branch_id' => $s->branch_id, 'room' => $s->room, 'capacity' => $s->capacity,
            'class_teacher' => $s->classTeacher?->name, 'schedule_note' => $s->schedule_note];
    }

    public static function student(Student $s, bool $full = false): array
    {
        $e = $s->relationLoaded('currentEnrollment') ? $s->currentEnrollment : null;
        $base = ['id' => $s->id, 'admission_no' => $s->admission_no, 'name' => $s->fullName(), 'first_name' => $s->first_name, 'last_name' => $s->last_name,
            'gender' => $s->gender, 'status' => $s->status, 'section' => $e?->section ? ['id' => $e->section->id, 'label' => $e->section->label()] : null,
            'roll_no' => $e?->roll_no, 'photo_url' => $s->photo_path ? route('api.students.photo', $s) : null];
        if (! $full) {
            return $base;
        }

        return $base + [
            'date_of_birth' => $s->date_of_birth?->format('Y-m-d'), 'blood_group' => $s->blood_group, 'category' => $s->category,
            'phone' => $s->phone, 'email' => $s->email, 'address' => $s->address, 'city' => $s->city, 'pincode' => $s->pincode,
            'admitted_on' => $s->admitted_on?->format('Y-m-d'), 'pen' => $s->pen,
            'guardians' => $s->guardians->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'relation' => $g->pivot->relation, 'phone' => $g->phone,
                'email' => $g->email, 'is_primary' => (bool) $g->pivot->is_primary])->values(),
        ];
    }

    public static function homework(Homework $h, ?string $submissionStatus = null): array
    {
        return ['id' => $h->id, 'title' => $h->title, 'details' => $h->details, 'subject' => $h->subject?->name, 'section_id' => $h->section_id,
            'section' => $h->section?->label(), 'assigned_on' => $h->assigned_on->format('Y-m-d'), 'due_on' => $h->due_on->format('Y-m-d'),
            'status' => $h->status, 'teacher' => $h->teacher?->name, 'submission_status' => $submissionStatus];
    }

    public static function reportCard(ReportCard $c): array
    {
        return ['id' => $c->id, 'title' => $c->title, 'section' => $c->section?->label(), 'percentage' => (float) $c->percentage, 'grade' => $c->grade,
            'gpa' => $c->gpa !== null ? (float) $c->gpa : null, 'rank' => $c->rank, 'result' => $c->result,
            'attendance_percent' => $c->attendance_percent !== null ? (float) $c->attendance_percent : null, 'remarks' => $c->remarks,
            'subjects' => $c->data['subjects'] ?? [], 'exams' => $c->exams, 'published_at' => $c->published_at?->toIso8601String()];
    }

    public static function document(\Modules\Academic\Models\StudentDocument $d): array
    {
        return ['id' => $d->id, 'type' => $d->type, 'label' => $d->label(), 'name' => $d->original_name, 'mime' => $d->mime, 'size' => $d->size,
            'uploaded_at' => $d->created_at?->toIso8601String(), 'verified' => $d->verified_at !== null, 'url' => route('api.documents.show', $d)];
    }
}
