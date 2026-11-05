<?php

namespace Modules\Academic\Models;

class LevelSubject extends TenantRow
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['is_optional' => 'boolean'];
    }
}
