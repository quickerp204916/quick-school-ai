<?php

namespace Modules\Fees\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FeePaymentAllocation extends TenantModel
{
    protected $table = 'fee_payment_allocations';

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class);
    }
}
