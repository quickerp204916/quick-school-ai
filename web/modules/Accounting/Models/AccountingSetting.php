<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class AccountingSetting extends TenantModel
{
    protected function casts(): array
    {
        return ["approval_thresholds" => "array", "locked_until" => "date:Y-m-d", "allow_self_approval" => "boolean"];
    }

    public static function current(): self
    {
        return self::firstOrFail();
    }
}
