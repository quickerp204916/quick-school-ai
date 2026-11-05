<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An uploaded supporting document or photo, on an application, a student, or both after admission. */
class StudentDocument extends TenantModel
{
    protected function casts(): array
    {
        return ['size' => 'integer', 'verified_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function label(): string
    {
        return config("academic.admission.documents.{$this->type}", ucfirst(str_replace('_', ' ', $this->type)));
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function sizeLabel(): string
    {
        return $this->size >= 1048576 ? round($this->size / 1048576, 1).' MB' : max(1, (int) round($this->size / 1024)).' KB';
    }
}
