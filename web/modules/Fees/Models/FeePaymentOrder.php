<?php

namespace Modules\Fees\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FeePaymentOrder extends TenantModel
{
    protected $table = 'fee_payment_orders';

    protected function casts(): array
    {
        return ['amount' => 'integer', 'invoice_ids' => 'array'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(\Modules\Academic\Models\Student::class);
    }
}
