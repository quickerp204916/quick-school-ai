<?php

namespace Modules\Fees\Services;

use Modules\Accounting\Models\Ledger;
use Modules\Fees\Models\FeeHead;
use Modules\Fees\Models\FeeSetting;

/** Default fee heads and settings for a new institution. Runs after the chart of accounts is seeded. */
final class FeeSetup
{
    public function seed(): void
    {
        if (FeeHead::exists()) {
            return;
        }
        FeeSetting::current();
        foreach (config('fees.heads') as $i => [$code, $name, $kind, $ledger, $refundable]) {
            FeeHead::create(['code' => $code, 'name' => $name, 'kind' => $kind, 'ledger_id' => Ledger::where('code', $ledger)->value('id'),
                'is_refundable' => $refundable, 'sort_order' => $i]);
        }
    }
}
