<?php

namespace Modules\Fees\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FeeStructure extends TenantModel
{
    protected $table = 'fee_structures';

    public const LATE_TYPES = ['none' => 'No late fee', 'flat' => 'Flat amount', 'per_day' => 'Per day', 'percent' => 'Percent of the due'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'due_day' => 'integer', 'late_fee_value' => 'integer', 'grace_days' => 'integer', 'late_fee_cap' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(FeeStructureItem::class, 'structure_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(\Modules\Institution\Models\AcademicSession::class);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(\Modules\Academic\Models\GradeLevel::class);
    }
}
