<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGuardian extends TenantRow
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'is_emergency' => 'boolean', 'can_pickup' => 'boolean'];
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
