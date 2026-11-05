<?php

namespace Modules\Academic\Models;

class Subject extends TenantModel
{
    protected function casts(): array
    {
        return ['credits' => 'decimal:1', 'is_active' => 'boolean'];
    }

    public function isGradedOnly(): bool
    {
        return $this->type === 'co_scholastic';
    }
}
