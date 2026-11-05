<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Platform\Models\Plan;
use Modules\Platform\Services\PlatformAudit;

class PlanController extends Controller
{
    public function index(): View
    {
        $plans = Plan::with('features')->withCount(['subscriptions as live_subscriptions' => fn ($q) => $q->where('status', '!=', 'cancelled')])
            ->orderBy('sort_order')->get();

        return view('platform::plans.index', ['plans' => $plans, 'catalogue' => config('platform.features')]);
    }

    public function create(): View
    {
        return view('platform::plans.form', ['plan' => new Plan(['trial_days' => 14, 'is_public' => true, 'is_active' => true]), 'values' => [], 'catalogue' => config('platform.features')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $plan = DB::transaction(function () use ($data) {
            $plan = Plan::create($data['plan']);
            $this->syncFeatures($plan, $data['features']);

            return $plan;
        });
        PlatformAudit::record('plan.created', $plan, null, ['code' => $plan->code]);

        return redirect()->route('platform.plans.index')->with('status', "Plan {$plan->name} created.");
    }

    public function edit(Plan $plan): View
    {
        return view('platform::plans.form', ['plan' => $plan, 'values' => $plan->load('features')->featureMap(), 'catalogue' => config('platform.features')]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $this->validated($request, $plan);
        $before = ['monthly' => $plan->price_monthly, 'yearly' => $plan->price_yearly, 'features' => $plan->load('features')->featureMap()];

        DB::transaction(function () use ($plan, $data) {
            $plan->update($data['plan']);
            $this->syncFeatures($plan, $data['features']);
        });
        PlatformAudit::record('plan.updated', $plan, null, ['before' => $before, 'after' => [
            'monthly' => $plan->price_monthly, 'yearly' => $plan->price_yearly, 'features' => $plan->fresh('features')->featureMap(),
        ]]);

        return redirect()->route('platform.plans.index')
            ->with('status', "Plan {$plan->name} saved. Existing subscriptions keep their locked price until their plan is changed.");
    }

    /** @return array{plan: array, features: array<string, bool|int|null>} */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        $catalogue = config('platform.features');
        $rules = [
            'code' => ['required', 'regex:/^[a-z0-9_-]{2,40}$/', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'price_monthly' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'price_yearly' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'trial_days' => ['required', 'integer', 'between:0,90'],
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
        ];
        foreach ($catalogue as $key => $def) {
            $rules["features.{$key}"] = $def['type'] === 'bool' ? ['nullable', 'in:1'] : ['nullable', 'regex:/^(\d{1,9}|unlimited)$/'];
        }

        $v = $request->validate($rules, [
            'price_monthly.regex' => 'Enter a price in rupees, for example 2999 or 2999.00.',
            'price_yearly.regex' => 'Enter a price in rupees, for example 29990 or 29990.00.',
            'features.*.regex' => 'Enter a whole number, or "unlimited".',
        ]);

        $features = [];
        foreach ($catalogue as $key => $def) {
            $raw = $v['features'][$key] ?? null;
            $features[$key] = $def['type'] === 'bool' ? $raw === '1' : ($raw === null || $raw === '' ? 0 : ($raw === 'unlimited' ? null : (int) $raw));
        }

        return [
            'plan' => [
                'code' => $v['code'], 'name' => $v['name'], 'description' => $v['description'] ?? null,
                'price_monthly' => $v['price_monthly'], 'price_yearly' => $v['price_yearly'], 'trial_days' => (int) $v['trial_days'],
                'sort_order' => (int) ($v['sort_order'] ?? 0), 'is_public' => $request->boolean('is_public'), 'is_active' => $request->boolean('is_active'),
                'currency' => 'INR',
            ],
            'features' => $features,
        ];
    }

    private function syncFeatures(Plan $plan, array $features): void
    {
        foreach ($features as $key => $value) {
            $isBool = config("platform.features.{$key}.type") === 'bool';
            $plan->features()->updateOrCreate(['feature' => $key], [
                'enabled' => $isBool ? (bool) $value : true,
                'limit_value' => $isBool ? null : $value,
            ]);
        }
    }
}
