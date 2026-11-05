<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanFeature extends Model
{
    use HasUuids;

    protected $fillable = ['plan_id', 'feature', 'enabled', 'limit_value'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'limit_value' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
