<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class StatutoryRule extends TenantModel
{
    protected $table = 'statutory_rules';

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'config' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Statutory rules are never edited. Add a new version with a later effective date.'));
        static::deleting(fn () => throw new \LogicException('Statutory rules are kept for history and cannot be deleted.'));
    }

    /** The version of a rule in force on a date. */
    public static function inForce(string $code, string $date): ?self
    {
        return self::where('code', $code)->where('effective_from', '<=', $date)->orderByDesc('effective_from')->first();
    }
}
