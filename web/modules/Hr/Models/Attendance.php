<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class Attendance extends TenantModel
{
    protected $table = 'hr_attendance';

    public const STATUSES = ['present' => 'Present', 'absent' => 'Absent', 'half_day' => 'Half day', 'leave' => 'On leave', 'holiday' => 'Holiday', 'weekly_off' => 'Weekly off'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'late_minutes' => 'integer', 'overtime_minutes' => 'integer', 'paid_leave' => 'boolean'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(\Modules\Academic\Models\StaffMember::class, 'staff_member_id');
    }

    /** Days of pay lost for this day. */
    public function lop(): float
    {
        return match ($this->status) {
            'absent' => 1.0,
            'half_day' => 0.5,
            'leave' => $this->paid_leave ? 0.0 : 1.0,
            default => 0.0,
        };
    }
}
