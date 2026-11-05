<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A message from Quick ERP shown inside institutions' apps (maintenance, new features). */
class Announcement extends Model
{
    use HasUuids;

    public const LEVELS = ['info', 'warning', 'critical'];

    protected $fillable = ['title', 'body', 'level', 'audience', 'plan_id', 'starts_at', 'ends_at', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Announcements currently visible to $tenant. */
    public function scopeVisibleTo(Builder $query, Tenant $tenant): Builder
    {
        $planId = $tenant->subscription?->plan_id;

        return $query->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn ($q) => $q->where('audience', 'all')
                ->orWhere(fn ($q) => $q->where('audience', 'plan')->where('plan_id', $planId)));
    }
}
