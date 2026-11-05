<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class CostCentre extends TenantModel
{
    protected function casts(): array
    {
        return ["is_active" => "boolean"];
    }
}
