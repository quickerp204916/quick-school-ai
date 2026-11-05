<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FixedAsset extends TenantModel
{
    protected function casts(): array
    {
        return ["put_to_use_on" => "date:Y-m-d", "disposed_on" => "date:Y-m-d", "cost" => "integer", "salvage" => "integer", "rate_bp" => "integer"];
    }

    public function postings(): HasMany
    {
        return $this->hasMany(DepreciationPosting::class, "asset_id");
    }

    public function assetLedger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, "asset_ledger_id");
    }
}
