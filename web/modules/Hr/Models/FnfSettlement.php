<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FnfSettlement extends TenantModel
{
    protected $table = 'fnf_settlements';

    protected function casts(): array
    {
        return ['relieved_on' => 'date:Y-m-d', 'workings' => 'array', 'approved_at' => 'datetime', 'salary_days' => 'float', 'encash_days' => 'float', 'service_years' => 'float',
            'salary' => 'integer', 'leave_encashment' => 'integer', 'gratuity' => 'integer', 'notice_recovery' => 'integer', 'advance_recovery' => 'integer',
            'other_deductions' => 'integer', 'tds' => 'integer', 'net' => 'integer'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(\Modules\Academic\Models\StaffMember::class, 'staff_member_id');
    }
}
