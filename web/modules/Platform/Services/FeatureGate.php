<?php

namespace Modules\Platform\Services;

use InvalidArgumentException;
use Modules\Platform\Models\Tenant;

/**
 * What a tenant may use. Precedence: active override, then the plan of its
 * current subscription, then the catalogue default in config/platform.php.
 * For limit features, null means unlimited.
 */
final class FeatureGate
{
    /** @var array<string, array{value: bool|int|null, source: string}>|null */
    private ?array $resolved = null;

    public function __construct(private readonly Tenant $tenant)
    {
    }

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** @return array<string, array{value: bool|int|null, source: string, label: string, type: string}> */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $subscription = $this->tenant->subscription()->with('plan.features')->first();
        $planValues = ($subscription && $subscription->status !== 'cancelled') ? $subscription->plan->featureMap() : [];
        $overrides = $this->tenant->featureOverrides()->get()->filter->isActive()->keyBy('feature');

        $out = [];
        foreach (config('platform.features') as $key => $def) {
            $value = $def['default'];
            $source = 'default';

            if (array_key_exists($key, $planValues)) {
                $value = $planValues[$key];
                $source = 'plan';
            }

            if ($override = $overrides->get($key)) {
                $value = $def['type'] === 'bool' ? (bool) $override->enabled : $override->limit_value;
                $source = 'override';
            }

            $out[$key] = ['value' => $value, 'source' => $source, 'label' => $def['label'], 'type' => $def['type']];
        }

        return $this->resolved = $out;
    }

    public function enabled(string $feature): bool
    {
        $f = $this->feature($feature);

        return $f['type'] === 'bool' ? (bool) $f['value'] : ($f['value'] === null || $f['value'] > 0);
    }

    /** null = unlimited */
    public function limit(string $feature): ?int
    {
        $f = $this->feature($feature);
        if ($f['type'] !== 'limit') {
            throw new InvalidArgumentException("{$feature} is not a limit feature.");
        }

        return $f['value'];
    }

    /** May the tenant add one more, given it already has $current? */
    public function allowsAnother(string $feature, int $current): bool
    {
        $limit = $this->limit($feature);

        return $limit === null || $current < $limit;
    }

    private function feature(string $feature): array
    {
        $all = $this->all();
        if (! isset($all[$feature])) {
            throw new InvalidArgumentException("Unknown feature: {$feature}");
        }

        return $all[$feature];
    }
}
