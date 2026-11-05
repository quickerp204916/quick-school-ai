<?php

namespace Modules\Institution\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'code', 'name', 'is_primary', 'address', 'city', 'state_code'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
