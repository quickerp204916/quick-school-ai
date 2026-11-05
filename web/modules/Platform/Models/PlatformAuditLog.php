<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Insert-only record of every action taken in the Super Admin console or API. */
class PlatformAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['platform_admin_id', 'action', 'subject_type', 'subject_id', 'tenant_id', 'changes', 'ip_address', 'via'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'platform_admin_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
