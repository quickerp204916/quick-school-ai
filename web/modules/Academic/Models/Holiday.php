<?php

namespace Modules\Academic\Models;

class Holiday extends TenantModel
{
    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    /** Holiday covering $date for $branchId (or for all branches), if any. */
    public static function covering(string $date, ?string $branchId): ?self
    {
        return self::whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->first();
    }
}
