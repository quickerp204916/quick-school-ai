<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class InvoiceLine extends TenantModel
{
    protected function casts(): array
    {
        return ["rate" => "integer", "amount" => "integer", "gst_bp" => "integer", "quantity" => "decimal:3"];
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }
}
