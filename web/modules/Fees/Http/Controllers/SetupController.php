<?php

namespace Modules\Fees\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Academic\Support\Audit;
use Modules\Accounting\Models\Ledger;
use Modules\Fees\FeeException;
use Modules\Fees\Models\FeeConcession;
use Modules\Fees\Models\FeeHead;
use Modules\Fees\Models\FeeSetting;
use Modules\Fees\Support;

/** Fee heads, concessions, gateways and reminders. */
class SetupController extends \Modules\Academic\Http\Controllers\Controller
{
    public function index(): View
    {
        return view('fees::setup', ['heads' => FeeHead::with('ledger')->orderBy('sort_order')->get(), 'concessions' => FeeConcession::orderBy('name')->get(),
            'ledgers' => Ledger::whereHas('group', fn ($q) => $q->whereIn('nature', ['income', 'liability']))->where('is_active', true)->orderBy('name')->get(),
            'settings' => FeeSetting::current()]);
    }

    private function head(Request $r, ?FeeHead $h = null): array
    {
        return $r->validate([
            'code' => ['required', 'alpha_dash', 'max:20', Rule::unique('fee_heads', 'code')->where('tenant_id', \App\Core\Tenancy\TenantContext::id())->ignore($h?->id)],
            'name' => ['required', 'max:120'], 'kind' => ['required', Rule::in(array_keys(FeeHead::KINDS))],
            'ledger_id' => ['required', Rule::exists('ledgers', 'id')->where('tenant_id', \App\Core\Tenancy\TenantContext::id())],
            'is_refundable' => ['boolean'], 'is_active' => ['boolean'],
        ]) + ['is_refundable' => false, 'is_active' => $h ? false : true];
    }

    public function storeHead(Request $r): RedirectResponse
    {
        $h = FeeHead::create($this->head($r) + ['sort_order' => (int) FeeHead::max('sort_order') + 1]);
        Audit::record('fees.head_created', $h);

        return $this->done("Fee head {$h->name} added.");
    }

    public function updateHead(Request $r, FeeHead $feeHead): RedirectResponse
    {
        $feeHead->update($this->head($r, $feeHead));
        Audit::record('fees.head_updated', $feeHead);

        return $this->done("Fee head {$feeHead->name} saved.");
    }

    private function concession(Request $r): array
    {
        $d = $r->validate(['name' => ['required', 'max:120'], 'category' => ['required', Rule::in(array_keys(FeeConcession::CATEGORIES))],
            'type' => ['required', Rule::in(['percent', 'amount'])], 'value' => ['required'], 'fee_head_ids' => ['array'], 'is_active' => ['boolean']]);
        if ($d['type'] === 'percent') {
            $pct = (float) $d['value'];
            if ($pct <= 0 || $pct > 100) {
                throw new FeeException('A percentage concession must be more than 0 and at most 100.', 'value');
            }
            $d['value'] = (int) round($pct * 100);
        } else {
            $d['value'] = Support::paise($d['value'], 'value');
        }
        $d['fee_head_ids'] = array_values(array_filter($d['fee_head_ids'] ?? [])) ?: null;

        return $d + ['is_active' => true];
    }

    public function storeConcession(Request $r): RedirectResponse
    {
        $c = FeeConcession::create($this->concession($r));
        Audit::record('fees.concession_created', $c);

        return $this->done("Concession {$c->name} added.");
    }

    public function updateConcession(Request $r, FeeConcession $feeConcession): RedirectResponse
    {
        $feeConcession->update($this->concession($r) + ['is_active' => $r->boolean('is_active')]);
        Audit::record('fees.concession_updated', $feeConcession);

        return $this->done("Concession {$feeConcession->name} saved.");
    }

    /** Gateway keys are stored encrypted; a blank secret field keeps the saved one. */
    public function updateSettings(Request $r): RedirectResponse
    {
        $d = $r->validate(['gateways' => ['array'], 'gateways.*' => [Rule::in(['razorpay', 'cashfree'])], 'razorpay_key_id' => ['nullable', 'max:60'],
            'razorpay_key_secret' => ['nullable', 'max:200'], 'razorpay_webhook_secret' => ['nullable', 'max:200'], 'cashfree_app_id' => ['nullable', 'max:80'],
            'cashfree_secret_key' => ['nullable', 'max:200'], 'cashfree_env' => [Rule::in(['sandbox', 'production'])], 'reminders_enabled' => ['boolean'],
            'reminder_days_before' => ['nullable', 'string', 'max:40'], 'reminder_days_after' => ['nullable', 'string', 'max:40'], 'receipt_note' => ['nullable', 'max:300']]);
        $days = fn (?string $s) => array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) $s)), fn ($n) => $n > 0 && $n <= 90)));
        $s = FeeSetting::current();
        foreach (['razorpay_key_secret', 'razorpay_webhook_secret', 'cashfree_secret_key'] as $k) {
            if (blank($d[$k] ?? null)) {
                unset($d[$k]);
            }
        }
        $s->update(array_merge($d, ['gateways' => $d['gateways'] ?? [], 'reminders_enabled' => $r->boolean('reminders_enabled'),
            'reminder_days_before' => $days($d['reminder_days_before'] ?? ''), 'reminder_days_after' => $days($d['reminder_days_after'] ?? '')]));
        Audit::record('fees.settings_saved', $s, ['gateways' => $s->gateways]);

        return $this->done('Fee settings saved.');
    }
}
