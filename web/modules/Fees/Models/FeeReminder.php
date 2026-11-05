<?php

namespace Modules\Fees\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FeeReminder extends TenantModel
{
    protected $table = 'fee_reminders';

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class);
    }
}
