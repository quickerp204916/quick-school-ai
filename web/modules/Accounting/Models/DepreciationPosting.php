<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class DepreciationPosting extends TenantModel
{
    protected function casts(): array
    {
        return ["amount" => "integer"];
    }
}
