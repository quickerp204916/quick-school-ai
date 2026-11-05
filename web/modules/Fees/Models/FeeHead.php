<?php

namespace Modules\Fees\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FeeHead extends TenantModel
{
    protected $table = 'fee_heads';

    public const KINDS = ['recurring' => 'Recurring', 'one_time' => 'One time', 'deposit' => 'Refundable deposit', 'optional' => 'Optional (opt-in)'];

    protected function casts(): array
    {
        return ['is_refundable' => 'boolean', 'is_active' => 'boolean'];
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(\Modules\Accounting\Models\Ledger::class);
    }
}
