<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class SalaryStructure extends TenantModel
{
    protected $table = 'salary_structures';

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'components' => 'array', 'monthly_gross' => 'integer'];
    }

    protected static function booted(): void
    {
        // History is preserved: a structure an approved payslip used can never change. Revise salary by adding a new structure.
        $used = fn (self $s) => Payslip::where('structure_id', $s->id)->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))->exists();
        static::updating(function (self $s) use ($used) {
            if ($used($s)) {
                throw new \LogicException('This salary structure was used by an approved payroll. Add a revision with a new effective date instead.');
            }
        });
        static::deleting(function (self $s) use ($used) {
            if ($used($s) || Payslip::where('structure_id', $s->id)->exists()) {
                throw new \LogicException('This salary structure was used by a payroll and cannot be deleted.');
            }
        });
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(\Modules\Academic\Models\StaffMember::class, 'staff_member_id');
    }

    /** The structure in force for a staff member on a date. */
    public static function inForce(string $staffId, string $date): ?self
    {
        return self::where('staff_member_id', $staffId)->where('effective_from', '<=', $date)->orderByDesc('effective_from')->first();
    }
}
