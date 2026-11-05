<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Institution\Models\AcademicSession;
use Modules\Institution\Models\Branch;

class AdmissionApplication extends TenantModel
{
    protected function casts(): array
    {
        return ['guardians' => 'array', 'documents' => 'array', 'date_of_birth' => 'date:Y-m-d', 'follow_up_on' => 'date:Y-m-d',
            'test_on' => 'date:Y-m-d', 'test_score' => 'decimal:2', 'stage_changed_at' => 'datetime'];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
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

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function uploads(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StudentDocument::class, 'application_id')->orderBy('type');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->stage, ['admitted', 'rejected', 'withdrawn'], true);
    }

    /** @return list<string> required documents not yet received */
    public function missingDocuments(array $required): array
    {
        $docs = $this->documents ?? [];

        return array_values(array_filter($required, fn ($d) => empty($docs[$d])));
    }
}
