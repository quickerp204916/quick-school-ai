<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An institution (school, college, coaching or training institute).
 * Platform-owned record: it is NOT tenant-scoped itself.
 */
class Tenant extends Model
{
    use HasUuids;

    public const STATUSES = ['trial', 'active', 'past_due', 'suspended', 'archived'];

    protected $fillable = [
        'code', 'name', 'legal_name', 'type', 'status', 'contact_name', 'contact_email', 'contact_phone',
        'billing_gstin', 'billing_state_code', 'address', 'city', 'timezone', 'locale', 'branding',
        'suspended_at', 'suspension_type', 'suspension_reason', 'archived_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'branding' => 'array',
            'suspended_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function subscription(): HasOne
    {
        // Latest by creation (ids are time-ordered UUIDs, so they break ties). Not latestOfMany(): PostgreSQL has no MAX(uuid).
        return $this->hasOne(Subscription::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PlatformInvoice::class);
    }

    public function featureOverrides(): HasMany
    {
        return $this->hasMany(TenantFeatureOverride::class);
    }

    public function creditEntries(): HasMany
    {
        return $this->hasMany(AiCreditEntry::class);
    }

    public function supportSessions(): HasMany
    {
        return $this->hasMany(SupportSession::class);
    }

    public function primaryDomain(): ?string
    {
        return $this->domains->firstWhere('is_primary', true)?->domain;
    }

    /** Whether users of this institution may sign in to the tenant app. */
    public function isAccessible(): bool
    {
        return in_array($this->status, ['trial', 'active', 'past_due'], true);
    }
}
