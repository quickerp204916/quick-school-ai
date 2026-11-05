<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class PayrollRun extends TenantModel
{
    protected $table = 'payroll_runs';

    protected function casts(): array
    {
        return ['employees' => 'integer', 'gross' => 'integer', 'deductions' => 'integer', 'employer_cost' => 'integer', 'net' => 'integer',
            'processed_at' => 'datetime', 'approved_at' => 'datetime', 'bank_file_at' => 'datetime'];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'run_id');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['approved', 'paid'], true);
    }

    public function label(): string
    {
        return \Carbon\Carbon::parse($this->period.'-01')->format('F Y');
    }
}
