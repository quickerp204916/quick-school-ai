<?php

namespace Modules\Academic\Models;

class BellPeriod extends TenantModel
{
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'is_break' => 'boolean'];
    }

    public function timeRange(): string
    {
        return substr((string) $this->starts_at, 0, 5).'–'.substr((string) $this->ends_at, 0, 5);
    }
}
