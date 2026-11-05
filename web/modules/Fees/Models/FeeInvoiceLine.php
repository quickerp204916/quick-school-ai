<?php

namespace Modules\Fees\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FeeInvoiceLine extends TenantModel
{
    protected $table = 'fee_invoice_lines';

    protected function casts(): array
    {
        return ['amount' => 'integer', 'concession' => 'integer'];
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(FeeHead::class, 'fee_head_id');
    }
}
