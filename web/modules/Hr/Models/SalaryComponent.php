<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class SalaryComponent extends TenantModel
{
    protected $table = 'salary_components';

    protected function casts(): array
    {
        return ['taxable' => 'boolean', 'pf_wage' => 'boolean', 'esi_wage' => 'boolean', 'is_computed' => 'boolean', 'is_active' => 'boolean'];
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(\Modules\Accounting\Models\Ledger::class);
    }
}
