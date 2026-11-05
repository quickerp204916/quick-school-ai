<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a tenant's AI credit ledger. Positive = granted, negative = used. Never updated. */
class AiCreditEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const KINDS = ['monthly_grant', 'top_up', 'usage', 'adjustment'];

    protected $fillable = ['tenant_id', 'amount', 'kind', 'reference', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
