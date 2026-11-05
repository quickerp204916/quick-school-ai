<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Accounting\Models\FinancialYear;
use Modules\Institution\Models\Branch;

abstract class Controller extends \Modules\Academic\Http\Controllers\Controller
{
    /** Date range from the request, defaulting to the current financial year up to today. */
    protected function range(Request $request): array
    {
        $fy = FinancialYear::current();
        $from = $request->input('from') ?: $fy?->starts_on->format('Y-m-d') ?? today()->startOfYear()->format('Y-m-d');
        $to = $request->input('to') ?: min(today()->format('Y-m-d'), $fy?->ends_on->format('Y-m-d') ?? today()->format('Y-m-d'));

        return [$from, $to];
    }

    protected function branches()
    {
        return Branch::orderByDesc('is_primary')->orderBy('name')->get();
    }
}
