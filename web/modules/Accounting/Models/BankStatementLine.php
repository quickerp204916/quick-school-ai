<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class BankStatementLine extends TenantModel
{
    protected function casts(): array
    {
        return ["date" => "date:Y-m-d", "amount" => "integer", "matched_at" => "datetime"];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, "matched_entry_id");
    }
}
