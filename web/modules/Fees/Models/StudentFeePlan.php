<?php

namespace Modules\Fees\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class StudentFeePlan extends TenantModel
{
    protected $table = 'student_fee_plans';

    protected function casts(): array
    {
        return ['optional_head_ids' => 'array'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(\Modules\Academic\Models\Student::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    public function concessions(): HasMany
    {
        return $this->hasMany(StudentFeeConcession::class, 'plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(FeeInvoice::class, 'plan_id')->orderBy('instalment');
    }
}
