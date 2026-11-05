<?php

namespace Modules\Fees\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FeePayment extends TenantModel
{
    protected $table = 'fee_payments';

    public const MODES = ['cash' => 'Cash', 'cheque' => 'Cheque', 'bank' => 'Bank transfer', 'upi' => 'UPI (at the counter)', 'razorpay' => 'Razorpay', 'cashfree' => 'Cashfree'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'amount' => 'integer', 'advance' => 'integer', 'refunded' => 'integer'];
    }

    protected static function booted(): void
    {
        // A receipt is permanent. Only its status (bounced) and refunded total may change; corrections go through reversal.
        static::updating(function (self $p) {
            if ($p->getOriginal('number') && array_diff(array_keys($p->getDirty()), ['status', 'refunded', 'advance', 'updated_at'])) {
                throw new \LogicException('Fee receipts cannot be changed. Mark the payment bounced or refund it instead.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Fee receipts cannot be deleted.'));
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(\Modules\Academic\Models\Student::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FeePaymentAllocation::class, 'payment_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(FeeRefund::class, 'payment_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(\Modules\Identity\Models\User::class, 'received_by');
    }

    public function refundable(): int
    {
        return $this->status === 'success' ? $this->amount - $this->refunded - (int) $this->refunds()->whereIn('status', ['requested', 'approved'])->sum('amount') : 0;
    }
}
