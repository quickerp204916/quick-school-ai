<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class EmployeeProfile extends TenantModel
{
    protected $table = 'employee_profiles';

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d', 'pan' => 'encrypted', 'bank_account' => 'encrypted', 'pf_applicable' => 'boolean', 'esi_applicable' => 'boolean',
            'declared_80c' => 'integer', 'resigned_on' => 'date:Y-m-d', 'relieved_on' => 'date:Y-m-d'];
    }

    protected $hidden = ['pan', 'bank_account'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(\Modules\Academic\Models\StaffMember::class, 'staff_member_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** Masked for screens: XXXXXX1234. */
    public function maskedAccount(): ?string
    {
        return $this->bank_account ? str_repeat('X', max(0, strlen($this->bank_account) - 4)).substr($this->bank_account, -4) : null;
    }

    public function maskedPan(): ?string
    {
        return $this->pan ? substr($this->pan, 0, 2).'XXXXX'.substr($this->pan, -3) : null;
    }
}
