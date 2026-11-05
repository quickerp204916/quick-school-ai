<?php

namespace Modules\Academic\Support;

use App\Core\Tenancy\TenantContext;
use LogicException;
use Modules\Academic\Models\InstitutionProfile;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\FeatureGate;

/** The current institution, its academic profile and its plan features. Cached per tenant. */
final class Institution
{
    private static array $cache = [];

    public static function tenant(): Tenant
    {
        $id = TenantContext::id() ?? throw new LogicException('No tenant context.');

        return self::$cache[$id]['tenant'] ??= Tenant::findOrFail($id);
    }

    public static function profile(): InstitutionProfile
    {
        $id = TenantContext::id() ?? throw new LogicException('No tenant context.');

        return self::$cache[$id]['profile'] ??= InstitutionProfile::firstOrFail();
    }

    public static function features(): FeatureGate
    {
        return new FeatureGate(self::tenant());
    }

    /** Word for this institution's structure, e.g. term('section') → "Batch" for coaching. */
    public static function term(string $key): string
    {
        return self::profile()->term($key);
    }

    public static function forget(): void
    {
        self::$cache = [];
    }
}
