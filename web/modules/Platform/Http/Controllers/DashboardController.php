<?php

namespace Modules\Platform\Http\Controllers;

use App\Core\Support\Money;
use Illuminate\View\View;
use Modules\Platform\Models\PlatformAuditLog;
use Modules\Platform\Models\PlatformInvoice;
use Modules\Platform\Models\Subscription;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\AiCredits;

class DashboardController extends Controller
{
    public function __invoke(AiCredits $credits): View
    {
        $byStatus = Tenant::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        $mrr = Subscription::whereIn('status', ['active', 'past_due'])->get()
            ->reduce(fn (Money $sum, Subscription $s) => $sum->plus($s->monthlyValue()), Money::zero());

        $overdue = PlatformInvoice::where('status', 'open')->whereDate('due_date', '<', today())->get();
        $overdueTotal = $overdue->reduce(fn (Money $sum, PlatformInvoice $i) => $sum->plus($i->balance()), Money::zero());

        $collected = PlatformInvoice::query()->join('platform_payments', 'platform_payments.invoice_id', '=', 'platform_invoices.id')
            ->whereBetween('platform_payments.paid_on', [now()->startOfMonth()->toDateString(), now()->toDateString()])
            ->sum('platform_payments.amount');

        return view('platform::dashboard', [
            'byStatus' => $byStatus,
            'totalTenants' => $byStatus->sum(),
            'mrr' => $mrr,
            'overdueTotal' => $overdueTotal,
            'overdueCount' => $overdue->count(),
            'collected' => Money::of((string) ($collected ?: '0')),   // MySQL returns DECIMAL sums as strings
            'trialsEnding' => Subscription::with('tenant', 'plan')->where('status', 'trialing')
                ->whereDate('trial_ends_at', '<=', today()->addDays(7))->orderBy('trial_ends_at')->limit(8)->get(),
            'newTenants' => Tenant::with('subscription.plan')->latest()->limit(6)->get(),
            'aiUsed' => $credits->usedBetween(now()->startOfMonth(), now()),
            'recentAudit' => PlatformAuditLog::with('admin', 'tenant')->latest('id')->limit(8)->get(),
        ]);
    }
}
