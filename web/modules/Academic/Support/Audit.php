<?php

namespace Modules\Academic\Support;

use App\Core\Audit\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** Writes to the institution's own audit log, as the signed-in user. */
final class Audit
{
    public static function record(string $action, ?Model $subject = null, ?array $changes = null): void
    {
        $user = request()->user() ?? auth()->user();

        AuditLog::create([
            'actor_type' => $user ? 'user' : 'system',
            'actor_id' => $user?->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes,
            'ip_address' => request()->ip(),
        ]);
    }
}
