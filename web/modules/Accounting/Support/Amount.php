<?php

namespace Modules\Accounting\Support;

use App\Core\Support\Money;

/** Rupee input and display for accounting screens. Storage is always integer paise. */
final class Amount
{
    /** "1,23,456.50" or "1234.5" → paise. Empty → 0. Throws InvalidArgumentException on anything else. */
    public static function parse(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value;
        }

        return Money::of(str_replace([',', ' ', '₹'], '', (string) $value))->paise;
    }

    public static function format(int $paise, bool $symbol = true): string
    {
        $s = Money::paise($paise)->format();

        return $symbol ? $s : ltrim(str_replace('₹', '', $s));
    }

    /** Balance with Dr/Cr suffix: 1,200.00 Dr. */
    public static function drcr(int $paise): string
    {
        if ($paise === 0) {
            return '0.00';
        }

        return self::format(abs($paise), false).($paise > 0 ? ' Dr' : ' Cr');
    }

    public static function input(int $paise): string
    {
        return $paise === 0 ? '' : Money::paise($paise)->toDecimal();
    }
}
