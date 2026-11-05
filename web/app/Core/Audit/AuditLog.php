<?php

namespace App\Core\Audit;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** The institution's own audit trail. Its owner can see platform support access here too. */
class AuditLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip_address'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }
}
