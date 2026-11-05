<?php

namespace Modules\Fees\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FeeConcession extends TenantModel
{
    protected $table = 'fee_concessions';

    public const CATEGORIES = ['discount' => 'Discount', 'scholarship' => 'Scholarship', 'sibling' => 'Sibling concession', 'staff_ward' => 'Staff ward', 'other' => 'Other'];

    protected function casts(): array
    {
        return ['value' => 'integer', 'fee_head_ids' => 'array', 'is_active' => 'boolean'];
    }

    public function appliesTo(string $headId): bool
    {
        return empty($this->fee_head_ids) || in_array($headId, $this->fee_head_ids, true);
    }

    public function label(): string
    {
        return $this->name.' ('.($this->type === 'percent' ? rtrim(rtrim(number_format($this->value / 100, 2), '0'), '.').'%' : \Modules\Accounting\Support\Amount::format($this->value).' each instalment').')';
    }
}
