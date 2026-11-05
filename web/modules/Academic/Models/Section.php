<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Institution\Models\AcademicSession;
use Modules\Institution\Models\Branch;

/** A section of a class, or a batch of a course, for one session and branch. */
class Section extends TenantModel
{
    protected function casts(): array
    {
        return ['capacity' => 'integer', 'is_active' => 'boolean', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'grade_level_id');
    }

    public function classTeacher(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'class_teacher_id');
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(SectionSubject::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function activeEnrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class)->where('status', 'active');
    }

    public function label(): string
    {
        return trim(($this->level?->name ?? '').' – '.$this->name, ' –');
    }

    public function activeCount(): int
    {
        return $this->activeEnrollments()->count();
    }
}
