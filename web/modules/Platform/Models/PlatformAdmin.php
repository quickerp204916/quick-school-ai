<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Quick ERP staff who use the Super Admin console. Separate from institution users. */
class PlatformAdmin extends Authenticatable
{
    use HasUuids;

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active', 'mfa_secret', 'mfa_confirmed_at', 'last_login_at'];

    protected $hidden = ['password', 'remember_token', 'mfa_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function hasRole(string ...$roles): bool
    {
        return $this->is_active && in_array($this->role, $roles, true);
    }

    public function roleLabel(): string
    {
        return config("platform.roles.{$this->role}", $this->role);
    }
}
