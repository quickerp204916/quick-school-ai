<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class Shift extends TenantModel
{
    protected $table = 'hr_shifts';

    protected function casts(): array
    {
        return ['weekly_off' => 'array', 'break_minutes' => 'integer', 'grace_minutes' => 'integer'];
    }

    /** Working minutes in the shift, without the break. */
    public function minutes(): int
    {
        [$a, $b] = [strtotime('1970-01-01 '.$this->starts_at), strtotime('1970-01-01 '.$this->ends_at)];

        return (int) (($b - $a) / 60) - $this->break_minutes;
    }
}
