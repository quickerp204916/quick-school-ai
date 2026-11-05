<?php

namespace Modules\Academic\Support;

use DateTimeInterface;

/** Numbers and dates in English words, as printed on transfer certificates. */
final class Words
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    private const ORDINALS = ['', 'First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth', 'Ninth', 'Tenth',
        'Eleventh', 'Twelfth', 'Thirteenth', 'Fourteenth', 'Fifteenth', 'Sixteenth', 'Seventeenth', 'Eighteenth', 'Nineteenth',
        'Twentieth', 'Twenty-first', 'Twenty-second', 'Twenty-third', 'Twenty-fourth', 'Twenty-fifth', 'Twenty-sixth',
        'Twenty-seventh', 'Twenty-eighth', 'Twenty-ninth', 'Thirtieth', 'Thirty-first'];

    public static function number(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $out = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$size, $word]) {
            if ($n >= $size) {
                $out[] = self::number(intdiv($n, $size)).' '.$word;
                $n %= $size;
            }
        }
        if ($n > 0) {
            $out[] = $n < 20 ? self::ONES[$n] : trim(self::TENS[intdiv($n, 10)].($n % 10 ? '-'.self::ONES[$n % 10] : ''));
        }

        return implode(' ', $out);
    }

    /** 2012-08-15 → "Fifteenth August Two Thousand Twelve" */
    public static function date(DateTimeInterface $date): string
    {
        return self::ORDINALS[(int) $date->format('j')].' '.$date->format('F').' '.self::number((int) $date->format('Y'));
    }
}
