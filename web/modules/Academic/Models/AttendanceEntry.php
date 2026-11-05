<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceEntry extends TenantRow
{
    public $timestamps = false;

    public function register(): BelongsTo
    {
        return $this->belongsTo(AttendanceRegister::class, 'register_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
