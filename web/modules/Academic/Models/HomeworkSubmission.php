<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeworkSubmission extends TenantRow
{
    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'checked_at' => 'datetime'];
    }

    public function homework(): BelongsTo
    {
        return $this->belongsTo(Homework::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
