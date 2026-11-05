<?php

namespace Modules\Fees\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\Fees\Models\FeeInvoice;
use Modules\Fees\Models\FeeReminder;
use Modules\Fees\Models\FeeSetting;
use Modules\Fees\Notifications\FeeReminder as Mail;

/** Sends due-soon and overdue reminders on the days set in fee settings, each one only once per invoice. */
final class Reminders
{
    public function send(?string $on = null): array
    {
        $s = FeeSetting::current();
        $sent = $skipped = 0;
        if (! $s->reminders_enabled) {
            return ['sent' => 0, 'skipped' => 0];
        }
        $today = CarbonImmutable::parse($on ?? today());
        $institution = \Modules\Platform\Models\Tenant::find(\App\Core\Tenancy\TenantContext::id())?->name ?? config('app.name');
        foreach (FeeInvoice::with('student.guardians')->whereIn('status', ['open', 'partial'])->get() as $inv) {
            if ($inv->balance() <= 0) {
                continue;
            }
            $days = (int) floor($inv->due_date->diffInDays($today, false));   // negative before the due date
            $kind = match (true) {
                $days < 0 && in_array(-$days, $s->reminder_days_before ?? [], true) => 'before_due',
                $days > 0 && in_array($days, $s->reminder_days_after ?? [], true) => 'overdue',
                default => null,
            };
            if (! $kind) {
                continue;
            }
            $g = $inv->student->primaryGuardian();
            if (! $g?->email) {
                $skipped++;
                continue;
            }
            $created = FeeReminder::query()->insertOrIgnore(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => \App\Core\Tenancy\TenantContext::id(),
                'invoice_id' => $inv->id, 'kind' => $kind, 'offset_days' => $days, 'channel' => 'mail', 'recipient' => $g->email, 'created_at' => now(), 'updated_at' => now()]);
            if ($created) {
                Notification::route('mail', [$g->email => $g->name])->notify(new Mail($inv, $kind, $institution));
                $sent++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
