<?php

namespace Modules\Fees\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FeeWebhookEvent extends TenantModel
{
    protected $table = 'fee_webhook_events';

    protected function casts(): array
    {
        return ['payload' => 'array', 'verified' => 'boolean'];
    }
}
