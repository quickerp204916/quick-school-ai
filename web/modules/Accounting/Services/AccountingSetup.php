<?php

namespace Modules\Accounting\Services;

use Modules\Accounting\Models\AccountGroup;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\CostCentre;
use Modules\Accounting\Models\FinancialYear;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\TaxRate;
use Modules\Accounting\Models\TdsSection;
use Modules\Institution\Models\AcademicSession;
use Modules\Institution\Models\Branch;
use Modules\Platform\Models\Tenant;

/** Books for a new institution: settings, the current financial year, chart of accounts, GST rates, TDS sections, cost centres. */
final class AccountingSetup
{
    public function seed(Tenant $tenant, ?Branch $branch = null, ?AcademicSession $session = null): void
    {
        if (AccountingSetting::exists()) {
            return;
        }
        AccountingSetting::create([
            'gstin' => $tenant->billing_gstin, 'state_code' => $tenant->billing_state_code ?? $branch?->state_code,
            'approval_thresholds' => config('accounting.approval_thresholds'),
        ]);

        $start = today()->month >= 4 ? today()->year : today()->year - 1;
        FinancialYear::create(['name' => $start.'-'.substr((string) ($start + 1), -2), 'starts_on' => "{$start}-04-01", 'ends_on' => ($start + 1).'-03-31']);

        $groups = [];
        foreach (config('accounting.groups') as $i => [$code, $name, $nature, $parent, $flow]) {
            $groups[$code] = AccountGroup::create(['code' => $code, 'name' => $name, 'nature' => $nature, 'parent_id' => $parent ? $groups[$parent]->id : null,
                'cash_flow' => $flow, 'is_system' => true, 'sort_order' => $i]);
        }
        foreach (config('accounting.ledgers') as [$code, $name, $group, $type, $key]) {
            Ledger::create(['code' => $code, 'name' => $name, 'group_id' => $groups[$group]->id, 'type' => $type, 'system_key' => $key]);
        }
        foreach (config('accounting.gst_rates') as [$name, $bp]) {
            TaxRate::create(['name' => $name, 'rate_bp' => $bp, 'effective_from' => '2017-07-01']);
        }
        foreach (config('accounting.tds_sections') as [$code, $name, $rate, $noPan, $single, $annual]) {
            TdsSection::create(['code' => $code, 'name' => $name, 'rate_bp' => $rate, 'rate_no_pan_bp' => $noPan,
                'threshold_single' => $single * 100, 'threshold_annual' => $annual * 100, 'effective_from' => '2025-04-01']);
        }
        foreach (config('accounting.cost_centres') as [$code, $name]) {
            CostCentre::create(['code' => $code, 'name' => $name]);
        }
    }
}
