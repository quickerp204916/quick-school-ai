<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mark extends TenantRow
{
    protected function casts(): array
    {
        return ['marks' => 'decimal:2', 'is_absent' => 'boolean'];
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(ExamPaper::class, 'paper_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
