<?php

namespace Modules\Fees\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FeeInvoice extends TenantModel
{
    protected $table = 'fee_invoices';

    protected function casts(): array
    {
        return ['issue_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'gross' => 'integer', 'concession' => 'integer', 'late_fee' => 'integer', 'waived' => 'integer', 'paid' => 'integer', 'instalment' => 'integer'];
    }

    /** What the parent owes on this invoice now. */
    public function total(): int
    {
        return $this->gross - $this->concession + $this->late_fee - $this->waived;
    }

    public function balance(): int
    {
        return max(0, $this->total() - $this->paid);
    }

    public function isOverdue(?string $on = null): bool
    {
        return in_array($this->status, ['open', 'partial'], true) && $this->due_date->format('Y-m-d') < ($on ?? today()->format('Y-m-d'));
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(\Modules\Academic\Models\Student::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(StudentFeePlan::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FeeInvoiceLine::class, 'invoice_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FeePaymentAllocation::class, 'invoice_id');
    }
}
