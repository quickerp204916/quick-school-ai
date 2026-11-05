<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class LeaveType extends TenantModel
{
    protected $table = 'hr_leave_types';

    protected function casts(): array
    {
        return ['days_per_year' => 'float', 'is_paid' => 'boolean', 'carry_forward_max' => 'float', 'encashable' => 'boolean', 'is_active' => 'boolean'];
    }
}
