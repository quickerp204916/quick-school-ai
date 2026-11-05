<?php

namespace Modules\Academic\Models;

class GradeBand extends TenantModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['min_percent' => 'decimal:2', 'grade_point' => 'decimal:2'];
    }
}
