<?php

namespace Modules\Platform\Models;

use App\Core\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasUuids;

    public const STATUSES = ['trialing', 'active', 'past_due', 'suspended', 'cancelled'];

    protected $fillable = [
        'tenant_id', 'plan_id', 'status', 'billing_cycle', 'price', 'trial_ends_at',
        'current_period_start', 'current_period_end', 'grace_until', 'cancel_at_period_end', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'trial_ends_at' => 'date',
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'grace_until' => 'date',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PlatformInvoice::class);
    }

    public function priceMoney(): Money
    {
        return Money::of($this->price);
    }

    /** Monthly recurring revenue contribution, excluding GST. */
    public function monthlyValue(): Money
    {
        if (! in_array($this->status, ['active', 'past_due'], true)) {
            return Money::zero();
        }

        return $this->billing_cycle === 'yearly' ? $this->priceMoney()->dividedBy(12) : $this->priceMoney();
    }
}
