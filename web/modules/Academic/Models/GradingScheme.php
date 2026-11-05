<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class GradingScheme extends TenantModel
{
    protected function casts(): array
    {
        return ['pass_percent' => 'decimal:2', 'is_default' => 'boolean'];
    }

    public function bands(): HasMany
    {
        return $this->hasMany(GradeBand::class)->orderByDesc('min_percent');
    }

    /** The band for a percentage: the highest band whose minimum it reaches. */
    public function bandFor(float $percent): ?GradeBand
    {
        $percent = round($percent, 2);

        return $this->bands->first(fn (GradeBand $b) => $percent >= (float) $b->min_percent);
    }
}
