<?php

namespace Modules\Platform\Models;

use App\Core\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Invoice from Quick ERP to an institution for its subscription. */
class PlatformInvoice extends Model
{
    use HasUuids;

    protected $fillable = [
        'number', 'tenant_id', 'subscription_id', 'period_start', 'period_end', 'issue_date', 'due_date', 'status',
        'currency', 'description', 'subtotal', 'cgst', 'sgst', 'igst', 'total', 'amount_paid',
        'seller_gstin', 'buyer_gstin', 'buyer_name', 'place_of_supply', 'sac', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'issue_date' => 'date', 'due_date' => 'date',
            'subtotal' => 'decimal:2', 'cgst' => 'decimal:2', 'sgst' => 'decimal:2', 'igst' => 'decimal:2',
            'total' => 'decimal:2', 'amount_paid' => 'decimal:2', 'paid_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PlatformPayment::class, 'invoice_id');
    }

    public function balance(): Money
    {
        return Money::of($this->total)->minus(Money::of($this->amount_paid));
    }

    public function isOverdue(?\Carbon\CarbonInterface $today = null): bool
    {
        return $this->status === 'open' && $this->due_date->lt(($today ?? now())->startOfDay());
    }

    /** open | overdue | paid | void, for display */
    public function displayStatus(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status;
    }
}
