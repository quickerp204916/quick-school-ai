<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantFeatureOverride extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'feature', 'enabled', 'limit_value', 'reason', 'expires_at', 'created_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'limit_value' => 'integer', 'expires_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
