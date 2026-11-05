<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class TaxRate extends TenantModel
{
    protected function casts(): array
    {
        return ["effective_from" => "date:Y-m-d", "effective_to" => "date:Y-m-d", "is_active" => "boolean", "rate_bp" => "integer"];
    }
}
