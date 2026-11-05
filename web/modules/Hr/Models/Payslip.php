<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class Payslip extends TenantModel
{
    protected $table = 'payslips';

    protected function casts(): array
    {
        return ['earnings' => 'array', 'deductions' => 'array', 'employer' => 'array', 'snapshot' => 'array', 'gross' => 'integer', 'total_deductions' => 'integer',
            'net' => 'integer', 'payable_days' => 'float', 'lop_days' => 'float', 'paid_days' => 'float', 'overtime_minutes' => 'integer', 'days_in_month' => 'integer'];
    }

    protected static function booted(): void
    {
        $locked = fn (self $p) => PayrollRun::whereKey($p->run_id)->whereIn('status', ['approved', 'paid'])->exists();
        static::updating(fn (self $p) => $locked($p) ? throw new \LogicException('Approved payslips cannot be changed.') : null);
        static::deleting(fn (self $p) => $locked($p) ? throw new \LogicException('Approved payslips cannot be deleted.') : null);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(\Modules\Academic\Models\StaffMember::class, 'staff_member_id');
    }
}
