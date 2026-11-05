<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class AccountGroup extends TenantModel
{
    protected function casts(): array
    {
        return ["is_system" => "boolean"];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, "parent_id");
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, "parent_id")->orderBy("code");
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(Ledger::class, "group_id")->orderBy("name");
    }

    /** Debit-balance natures: assets and expenses. */
    public function isDebitNature(): bool
    {
        return in_array($this->nature, ["asset", "expense"], true);
    }

    /** Cash flow class of this group or the nearest parent that has one. */
    public function cashFlowClass(): ?string
    {
        return $this->cash_flow ?? $this->parent?->cashFlowClass();
    }
}
