<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class EmployeeDocument extends TenantModel
{
    protected $table = 'employee_documents';

    protected function casts(): array
    {
        return ['expires_on' => 'date:Y-m-d', 'size' => 'integer'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(\Modules\Academic\Models\StaffMember::class, 'staff_member_id');
    }
}
