<?php

namespace Modules\Fees;

use Modules\Accounting\Models\FinancialYear;
use Modules\Accounting\Support\Amount;
use Modules\Academic\Support\Numbering;

/** Small shared helpers for the fee module. */
final class Support
{
    /** FY label for numbering, e.g. 2026-27 (from the books, or April–March if no year is set up yet). */
    public static function fy(string $date): string
    {
        if ($fy = FinancialYear::for($date)) {
            return $fy->name;
        }
        $y = (int) substr($date, 0, 4) - ((int) substr($date, 5, 2) < 4 ? 1 : 0);

        return $y.'-'.substr((string) ($y + 1), -2);
    }

    /** Gapless document number such as RCT/2026-27/00001. Call inside the transaction that saves the document. */
    public static function number(string $series, string $date): string
    {
        $fy = self::fy($date);

        return sprintf('%s/%s/%05d', $series, $fy, Numbering::next('fee_'.strtolower($series), $fy));
    }

    /** Rupee input ("1,500.50" or paise int) to paise, refusing negatives and junk. */
    public static function paise(mixed $value, string $field = 'amount'): int
    {
        try {
            $p = Amount::parse($value);
        } catch (\Throwable) {
            throw new FeeException('Enter the amount in rupees, for example 1500 or 1500.50.', $field);
        }
        if ($p < 0) {
            throw new FeeException('The amount cannot be negative.', $field);
        }

        return $p;
    }

    /** The webhook URL for this institution (works in jobs and the console too, where there is no request). */
    public static function webhookUrl(string $gateway): string
    {
        return route('api.fees.webhook', ['gateway' => $gateway, 'tenant' => \Modules\Platform\Models\Tenant::find(\App\Core\Tenancy\TenantContext::id())?->code]);
    }
}
