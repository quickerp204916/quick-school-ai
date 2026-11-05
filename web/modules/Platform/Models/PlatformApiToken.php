<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PlatformApiToken extends Model
{
    use HasUuids;

    protected $fillable = ['platform_admin_id', 'name', 'token_hash', 'abilities', 'last_used_at', 'expires_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'platform_admin_id');
    }

    /** Creates a token and returns [model, plain-text token]. The plain token is shown once only. */
    public static function issue(PlatformAdmin $admin, string $name, array $abilities, ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = 'qsa_'.Str::random(48);
        $token = static::create([
            'platform_admin_id' => $admin->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'abilities' => array_values($abilities),
            'expires_at' => $expiresAt,
        ]);

        return [$token, $plain];
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
