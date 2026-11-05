<?php

namespace Modules\Fees\Console;

use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Fees\Services\CollectionService;
use Modules\Fees\Services\FeePlanService;
use Modules\Fees\Services\Reminders;
use Modules\Platform\Models\Tenant;

/** Daily, for every active institution: issue invoices that have come due, bring late fees up to date, use advances, send reminders. */
class FeesDailyCommand extends Command
{
    protected $signature = 'fees:daily {--date= : Run as if today were this date (YYYY-MM-DD)} {--tenant= : Only this institution code}';

    protected $description = 'Issue due fee invoices, apply late fees and advances, and send fee reminders';

    public function handle(): int
    {
        $on = $this->option('date') ?: today()->format('Y-m-d');
        $rows = [];
        foreach (Tenant::whereIn('status', ['trial', 'active', 'past_due'])->when($this->option('tenant'), fn ($q, $c) => $q->where('code', $c))->get() as $tenant) {
            try {
                $rows[] = TenantContext::run($tenant, function () use ($tenant, $on) {
                    $issued = app(FeePlanService::class)->issueDue(null, $on);
                    $late = app(FeePlanService::class)->applyLateFees(null, $on);
                    $adv = app(CollectionService::class)->applyAdvances();
                    $r = app(Reminders::class)->send($on);

                    return [$tenant->code, $issued, $late, $adv, $r['sent'], $r['skipped']];
                });
            } catch (\Throwable $e) {
                report($e);
                $rows[] = [$tenant->code, 'error: '.$e->getMessage(), '', '', '', ''];
            }
        }
        $this->table(['Institution', 'Invoices issued', 'Late fees', 'Advances used', 'Reminders sent', 'No email'], $rows);

        return self::SUCCESS;
    }
}
