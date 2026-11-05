<?php

namespace Modules\Academic\Support;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gapless numbers per institution, series and period (admission numbers, certificate numbers).
 * Must run inside the transaction that saves the document, so a rollback gives the number back.
 */
final class Numbering
{
    public static function next(string $series, string $period): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Numbering::next() must run inside a database transaction.');
        }
        $tenant = TenantContext::id() ?? throw new LogicException('No tenant context for numbering.');

        DB::table('tenant_counters')->insertOrIgnore([
            'tenant_id' => $tenant, 'series' => $series, 'period' => $period, 'next_value' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $q = fn () => DB::table('tenant_counters')->where('tenant_id', $tenant)->where('series', $series)->where('period', $period);
        $value = (int) $q()->lockForUpdate()->value('next_value');
        $q()->update(['next_value' => $value + 1, 'updated_at' => now()]);

        return $value;
    }
}
