<?php

namespace Modules\Academic\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Base for institution data with auto-increment keys (link and entry rows). */
abstract class TenantRow extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];
}
