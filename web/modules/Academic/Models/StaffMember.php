<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Identity\Models\User;
use Modules\Institution\Models\Branch;

/** Teaching and non-teaching staff: the employee master. HR details live in the HR module's employee profile. */
class StaffMember extends TenantModel
{
    protected function casts(): array
    {
        return ['is_teaching' => 'boolean', 'joined_on' => 'date:Y-m-d'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function profile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\Modules\Hr\Models\EmployeeProfile::class);
    }
}
