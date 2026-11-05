<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FinancialYear extends TenantModel
{
    protected function casts(): array
    {
        return ["starts_on" => "date:Y-m-d", "ends_on" => "date:Y-m-d", "closed_at" => "datetime"];
    }

    public static function for(\DateTimeInterface|string $date): ?self
    {
        $d = $date instanceof \DateTimeInterface ? $date->format("Y-m-d") : $date;

        return self::where("starts_on", "<=", $d)->where("ends_on", ">=", $d)->first();
    }

    public static function current(): ?self
    {
        return self::for(today()) ?? self::orderByDesc("starts_on")->first();
    }
}
