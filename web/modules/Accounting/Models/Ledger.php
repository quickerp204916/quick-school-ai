<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class Ledger extends TenantModel
{
    protected function casts(): array
    {
        return ["opening_balance" => "integer", "is_active" => "boolean"];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, "group_id");
    }

    public function tdsSection(): BelongsTo
    {
        return $this->belongsTo(TdsSection::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function isCashOrBank(): bool
    {
        return in_array($this->type, ["cash", "bank"], true);
    }

    public static function system(string $key): self
    {
        return self::where("system_key", $key)->firstOrFail();
    }

    public function label(): string
    {
        return $this->name." (".$this->code.")";
    }
}
