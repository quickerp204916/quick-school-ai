<?php

namespace Modules\Platform\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Platform\Services\BillingRun;

class BillingRunCommand extends Command
{
    protected $signature = 'platform:billing-run {--date= : Run as if today were this date (YYYY-MM-DD)}';

    protected $description = 'Convert ended trials, renew subscriptions, apply dunning and grant monthly AI credits';

    public function handle(BillingRun $billing): int
    {
        $date = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::now();
        $summary = $billing->run($date);

        $this->table(['Step', 'Count'], collect($summary)->map(fn ($v, $k) => [str_replace('_', ' ', $k), $v])->values()->all());

        return self::SUCCESS;
    }
}
