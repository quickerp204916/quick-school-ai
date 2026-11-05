<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Institution\Models\AcademicSession;

/** A published report card: a snapshot that does not change if marks are edited later. */
class ReportCard extends TenantModel
{
    protected function casts(): array
    {
        return ['exams' => 'array', 'data' => 'array', 'percentage' => 'decimal:2', 'gpa' => 'decimal:2',
            'attendance_percent' => 'decimal:2', 'rank' => 'integer', 'published_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }
}
