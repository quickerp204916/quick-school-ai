<?php

namespace Modules\Fees\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class StudentFeeConcession extends TenantModel
{
    protected $table = 'student_fee_concessions';

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function concession(): BelongsTo
    {
        return $this->belongsTo(FeeConcession::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(StudentFeePlan::class);
    }
}
