<?php

namespace Modules\Platform\Http\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Support\IndianStates;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Identity\Models\TenantMembership;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantFeatureOverride;
use Modules\Platform\Services\AiCredits;
use Modules\Platform\Services\FeatureGate;
use Modules\Platform\Services\PlatformAudit;
use Modules\Platform\Services\SubscriptionService;
use Modules\Platform\Services\SupportAccess;
use Modules\Platform\Services\TenantLifecycle;
use Modules\Platform\Services\TenantProvisioner;

class TenantController extends Controller
{
    /** Validation for onboarding, shared with the API. */
    public static function provisionRules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/', Rule::notIn(config('platform.reserved_codes')), 'unique:tenants,code'],
            'name' => ['required', 'string', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'type' => ['required', Rule::in(array_keys(config('platform.institution_types')))],
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'owner_name' => ['required', 'string', 'max:150'],
            'owner_email' => ['required', 'email', 'max:191'],
            'owner_phone' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'billing_gstin' => ['nullable', 'regex:'.IndianStates::GSTIN_PATTERN],
            'billing_state_code' => ['nullable', Rule::in(array_keys(IndianStates::ALL))],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'branch_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    public static function validationMessages(): array
    {
        return [
            'code.regex' => 'Use 3 to 40 lower-case letters, numbers or hyphens, starting and ending with a letter or number.',
            'code.not_in' => 'That code is reserved. Choose another.',
            'owner_phone.regex' => 'Enter a 10-digit Indian mobile number.',
            'billing_gstin.regex' => 'Enter a valid 15-character GSTIN, for example 09AAACQ1234A1Z5.',
        ];
    }

    /** A GSTIN starts with its state code, so the two must agree. */
    public static function checkGstinState(\Illuminate\Validation\Validator $v): void
    {
        $gstin = strtoupper((string) ($v->getData()['billing_gstin'] ?? ''));
        $state = (string) ($v->getData()['billing_state_code'] ?? '');
        if ($gstin !== '' && $state !== '' && substr($gstin, 0, 2) !== $state) {
            $v->errors()->add('billing_state_code', 'The state does not match the first two digits of the GSTIN.');
        }
    }

    public function index(Request $request): View
    {
        $tenants = Tenant::query()->with(['subscription.plan', 'domains'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->input('q')).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('code', 'like', $term)
                    ->orWhere('contact_email', 'like', $term)->orWhere('city', 'like', $term));
            })
            ->when(in_array($request->input('status'), Tenant::STATUSES, true), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('plan'), fn ($q) => $q->whereHas('subscription', fn ($s) => $s->where('plan_id', $request->input('plan'))))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('platform::tenants.index', ['tenants' => $tenants, 'plans' => Plan::orderBy('sort_order')->get()]);
    }

    public function create(): View
    {
        return view('platform::tenants.create', [
            'plans' => Plan::where('is_active', true)->orderBy('sort_order')->get(),
            'states' => IndianStates::ALL,
        ]);
    }

    public static function normalise(Request $request): void
    {
        $request->merge(array_filter([
            'code' => $request->filled('code') ? strtolower(trim((string) $request->input('code'))) : null,
            'billing_gstin' => $request->filled('billing_gstin') ? strtoupper(trim((string) $request->input('billing_gstin'))) : null,
            'owner_email' => $request->filled('owner_email') ? strtolower(trim((string) $request->input('owner_email'))) : null,
        ], fn ($v) => $v !== null));
    }

    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        self::normalise($request);
        $validator = validator($request->all(), self::provisionRules(), self::validationMessages());
        $validator->after(fn ($v) => self::checkGstinState($v));
        $data = $validator->validate();

        return $this->attempt(
            fn () => route('platform.tenants.show', $provisioner->provision($data, $this->admin())),
            'Institution created. The owner has been emailed a link to set their password.'
        );
    }

    public function show(Tenant $tenant, AiCredits $credits, SupportAccess $support): View
    {
        $tenant->load(['domains', 'subscription.plan', 'featureOverrides']);

        [$owner, $branches, $tenantAudit] = TenantContext::run($tenant, fn () => [
            TenantMembership::with('user')->where('role', 'owner')->first(),
            \Modules\Institution\Models\Branch::orderByDesc('is_primary')->get(),
            AuditLog::latest('id')->limit(15)->get(),
        ]);

        return view('platform::tenants.show', [
            'tenant' => $tenant,
            'owner' => $owner,
            'branches' => $branches,
            'features' => FeatureGate::for($tenant)->all(),
            'invoices' => $tenant->invoices()->latest('issue_date')->limit(12)->get(),
            'credits' => $credits->balance($tenant),
            'creditEntries' => $tenant->creditEntries()->latest('created_at')->limit(10)->get(),
            'plans' => Plan::where('is_active', true)->orderBy('sort_order')->get(),
            'activeSupport' => $support->activeFor($this->admin(), $tenant),
            'tenantAudit' => $tenantAudit,
            'stateName' => IndianStates::name($tenant->billing_state_code),
        ]);
    }

    public function edit(Tenant $tenant): View
    {
        return view('platform::tenants.edit', ['tenant' => $tenant, 'states' => IndianStates::ALL]);
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        self::normalise($request);
        $validator = validator($request->all(), [
            'name' => ['required', 'string', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'type' => ['required', Rule::in(array_keys(config('platform.institution_types')))],
            'contact_name' => ['required', 'string', 'max:150'],
            'contact_email' => ['required', 'email', 'max:191'],
            'contact_phone' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'billing_gstin' => ['nullable', 'regex:'.IndianStates::GSTIN_PATTERN],
            'billing_state_code' => ['nullable', Rule::in(array_keys(IndianStates::ALL))],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
        ], self::validationMessages());
        $validator->after(fn ($v) => self::checkGstinState($v));
        $data = $validator->validate();

        $before = $tenant->only(array_keys($data));
        $tenant->update($data);
        $changed = array_diff_assoc(array_map('strval', $tenant->only(array_keys($data))), array_map('strval', $before));
        PlatformAudit::record('tenant.updated', $tenant, $tenant->id, ['changed' => array_keys($changed)]);

        return redirect()->route('platform.tenants.show', $tenant)->with('status', 'Institution details saved.');
    }

    public function suspend(Request $request, Tenant $tenant, TenantLifecycle $lifecycle): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->attempt(fn () => $lifecycle->suspend($tenant, $data['reason'], $this->admin()), 'Institution suspended. Its users can no longer sign in.');
    }

    public function reactivate(Tenant $tenant, TenantLifecycle $lifecycle): RedirectResponse
    {
        return $this->attempt(fn () => $lifecycle->reactivate($tenant, $this->admin()), 'Institution reactivated.');
    }

    public function archive(Request $request, Tenant $tenant, TenantLifecycle $lifecycle): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'confirm_code' => ['required', Rule::in([$tenant->code])],
        ], ['confirm_code.in' => 'Type the institution code exactly to confirm.']);

        return $this->attempt(fn () => $lifecycle->archive($tenant, $data['reason'], $this->admin()), 'Institution archived and its subscription cancelled.');
    }

    public function storeOverride(Request $request, Tenant $tenant): RedirectResponse
    {
        $features = config('platform.features');
        $data = $request->validate([
            'feature' => ['required', Rule::in(array_keys($features))],
            'value' => ['nullable', 'string', 'max:20'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $type = $features[$data['feature']]['type'];
        $value = trim((string) ($data['value'] ?? ''));
        if ($type === 'bool' && ! in_array($value, ['on', 'off'], true)) {
            return back()->withInput()->withErrors(['value' => 'Choose on or off for this feature.']);
        }
        if ($type === 'limit' && $value !== '' && $value !== 'unlimited' && ! ctype_digit($value)) {
            return back()->withInput()->withErrors(['value' => 'Enter a whole number, or "unlimited".']);
        }

        $override = TenantFeatureOverride::updateOrCreate(['tenant_id' => $tenant->id, 'feature' => $data['feature']], [
            'enabled' => $type === 'bool' ? $value === 'on' : true,
            'limit_value' => $type === 'limit' && ctype_digit($value) ? (int) $value : null,
            'reason' => $data['reason'],
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => $this->admin()->id,
        ]);
        PlatformAudit::record('tenant.feature_override_set', $override, $tenant->id, ['feature' => $data['feature'], 'value' => $value, 'reason' => $data['reason']]);

        return back()->with('status', 'Override saved for '.$features[$data['feature']]['label'].'.');
    }

    public function destroyOverride(Tenant $tenant, TenantFeatureOverride $override): RedirectResponse
    {
        abort_unless($override->tenant_id === $tenant->id, 404);
        $override->delete();
        PlatformAudit::record('tenant.feature_override_removed', $tenant, $tenant->id, ['feature' => $override->feature]);

        return back()->with('status', 'Override removed. The plan value applies again.');
    }

    public function changePlan(Request $request, Tenant $tenant, SubscriptionService $subscriptions): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
        ]);
        $subscription = $tenant->subscription()->firstOrFail();

        return $this->attempt(
            fn () => $subscriptions->changePlan($subscription, Plan::findOrFail($data['plan_id']), $data['billing_cycle'], $this->admin()),
            'Plan changed. The new price applies from the next invoice.'
        );
    }

    public function cancelSubscription(Request $request, Tenant $tenant, SubscriptionService $subscriptions): RedirectResponse
    {
        $data = $request->validate(['when' => ['required', Rule::in(['period_end', 'now'])], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        $subscription = $tenant->subscription()->firstOrFail();

        return $this->attempt(
            fn () => $subscriptions->cancel($subscription, $data['when'] === 'period_end', $data['reason'], $this->admin()),
            $data['when'] === 'now' ? 'Subscription cancelled now.' : 'Subscription will end at the end of the current period.'
        );
    }

    public function resumeSubscription(Tenant $tenant, SubscriptionService $subscriptions): RedirectResponse
    {
        return $this->attempt(fn () => $subscriptions->resume($tenant->subscription()->firstOrFail(), $this->admin()), 'Scheduled cancellation removed.');
    }

    public function grantCredits(Request $request, Tenant $tenant, AiCredits $credits): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['top_up', 'adjustment'])],
            'amount' => ['required', 'integer', 'between:-1000000,1000000', 'not_in:0'],
            'note' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        return $this->attempt(fn () => $credits->grant($tenant, (int) $data['amount'], $data['kind'], $data['note'], $this->admin()), 'AI credits updated.');
    }
}
