<?php

namespace Modules\Identity\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Institution\Models\Branch;

class TenantMembership extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'user_id', 'role', 'status', 'default_branch_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }
}
