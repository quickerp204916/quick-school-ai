<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Identity\Models\User;
use Modules\Institution\Models\Branch;

class Student extends TenantModel
{
    public const STATUSES = ['active', 'alumni', 'transferred', 'withdrawn'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d', 'admitted_on' => 'date:Y-m-d', 'left_on' => 'date:Y-m-d'];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardians')
            ->withPivot(['relation', 'is_primary', 'is_emergency', 'can_pickup'])->orderByPivot('is_primary', 'desc');
    }

    public function guardianLinks(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class)->orderByDesc('joined_on');
    }

    public function currentEnrollment(): HasOne
    {
        // Not latestOfMany(): PostgreSQL has no MAX(uuid). A student has at most one active enrollment per session.
        return $this->hasOne(Enrollment::class)->where('status', 'active')->orderByDesc('joined_on')->orderByDesc('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class)->orderBy('type');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** First guardian with the given relation, e.g. guardianBy('father'). */
    public function guardianBy(string $relation): ?Guardian
    {
        return $this->guardians->first(fn ($g) => $g->pivot->relation === $relation);
    }

    public function primaryGuardian(): ?Guardian
    {
        return $this->guardians->first(fn ($g) => $g->pivot->is_primary) ?? $this->guardians->first();
    }
}
