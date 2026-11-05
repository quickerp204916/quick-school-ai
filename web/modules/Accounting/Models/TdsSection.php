<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class TdsSection extends TenantModel
{
    protected function casts(): array
    {
        return ["effective_from" => "date:Y-m-d", "effective_to" => "date:Y-m-d", "rate_bp" => "integer", "rate_no_pan_bp" => "integer",
            "threshold_single" => "integer", "threshold_annual" => "integer"];
    }

    /** The version of a section (by code) in force on a date. */
    public static function inForce(string $code, string $date): ?self
    {
        return self::where("code", $code)->where("effective_from", "<=", $date)
            ->where(fn ($q) => $q->whereNull("effective_to")->orWhere("effective_to", ">=", $date))->orderByDesc("effective_from")->first();
    }
}
