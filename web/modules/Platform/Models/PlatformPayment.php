<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformPayment extends Model
{
    use HasUuids;

    public const METHODS = ['bank_transfer', 'upi', 'card', 'cheque', 'gateway'];

    protected $fillable = ['invoice_id', 'amount', 'method', 'reference', 'paid_on', 'recorded_by', 'idempotency_key'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PlatformInvoice::class, 'invoice_id');
    }
}
