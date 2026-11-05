<?php

namespace Modules\Platform\Models;

use App\Core\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name', 'description', 'price_monthly', 'price_yearly', 'currency', 'trial_days', 'is_public', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'trial_days' => 'integer',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function priceFor(string $cycle): Money
    {
        return Money::of($cycle === 'yearly' ? $this->price_yearly : $this->price_monthly);
    }

    /** @return array<string, bool|int|null> feature key => value as set on this plan */
    public function featureMap(): array
    {
        $map = [];
        foreach ($this->features as $f) {
            $type = config("platform.features.{$f->feature}.type");
            $map[$f->feature] = $type === 'bool' ? (bool) $f->enabled : $f->limit_value;
        }

        return $map;
    }
}
