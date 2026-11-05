<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class LedgerEntry extends TenantModel
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ["date" => "date:Y-m-d", "debit" => "integer", "credit" => "integer"];
    }

    protected static function booted(): void
    {
        $refuse = fn () => throw new \LogicException("Posted ledger entries cannot be changed. Post a reversal instead.");
        static::updating($refuse);
        static::deleting($refuse);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }
}
