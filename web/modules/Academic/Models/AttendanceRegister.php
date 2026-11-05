<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceRegister extends TenantModel
{
    protected $guarded = ['id', 'tenant_id', 'slot'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'marked_at' => 'datetime'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(BellPeriod::class, 'period_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AttendanceEntry::class, 'register_id');
    }
}
