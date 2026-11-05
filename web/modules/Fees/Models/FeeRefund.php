<?php

namespace Modules\Fees\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FeeRefund extends TenantModel
{
    protected $table = 'fee_refunds';

    protected function casts(): array
    {
        return ['amount' => 'integer', 'approved_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(\Modules\Identity\Models\User::class, 'requested_by');
    }
}
