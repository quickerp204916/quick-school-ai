<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class PayrollInput extends TenantModel
{
    protected $table = 'payroll_inputs';

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(\Modules\Academic\Models\StaffMember::class, 'staff_member_id');
    }
}
