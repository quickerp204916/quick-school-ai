<?php

namespace App\Core\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gapless document numbers per series and Indian financial year (April–March),
 * e.g. QSA/2026-27/00042. Must be called inside the transaction that saves the
 * document, so a rollback also gives the number back.
 */
final class DocumentNumber
{
    public static function next(string $series, CarbonInterface $date): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('DocumentNumber::next() must run inside a database transaction.');
        }

        $fy = self::financialYear($date);

        DB::table('document_counters')->insertOrIgnore([
            'series' => $series, 'financial_year' => $fy, 'next_value' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = DB::table('document_counters')
            ->where('series', $series)->where('financial_year', $fy)
            ->lockForUpdate()->first();

        DB::table('document_counters')
            ->where('series', $series)->where('financial_year', $fy)
            ->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

        return sprintf('%s/%s/%05d', $series, $fy, $row->next_value);
    }

    public static function financialYear(CarbonInterface $date): string
    {
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return $start.'-'.substr((string) ($start + 1), -2);
    }
}
