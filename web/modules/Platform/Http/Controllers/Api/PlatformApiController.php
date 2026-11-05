<?php

namespace Modules\Platform\Http\Controllers\Api;

use App\Core\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Exceptions\PlatformRuleException;
use Modules\Platform\Http\Controllers\TenantController;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\PlatformInvoice;
use Modules\Platform\Models\PlatformPayment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\FeatureGate;
use Modules\Platform\Services\InvoiceService;
use Modules\Platform\Services\TenantLifecycle;
use Modules\Platform\Services\TenantProvisioner;

/** Platform API v1. All responses are JSON; errors use {"error": {"code", "message"}}. */
class PlatformApiController
{
    public function tenants(Request $request): JsonResponse
    {
        $page = Tenant::with('subscription.plan', 'domains')
            ->when(in_array($request->query('status'), Tenant::STATUSES, true), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->query('q').'%')->orWhere('code', 'like', '%'.$request->query('q').'%')))
            ->orderBy('name')->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Tenant $t) => $this->tenantJson($t))->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function tenant(Tenant $tenant): JsonResponse
    {
        $tenant->load('subscription.plan', 'domains');

        return response()->json(['data' => $this->tenantJson($tenant) + [
            'features' => collect(FeatureGate::for($tenant)->all())->map(fn ($f) => ['value' => $f['value'], 'source' => $f['source']]),
        ]]);
    }

    public function plans(): JsonResponse
    {
        return response()->json(['data' => Plan::with('features')->where('is_active', true)->orderBy('sort_order')->get()->map(fn (Plan $p) => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'price_monthly' => $p->price_monthly, 'price_yearly' => $p->price_yearly,
            'currency' => $p->currency, 'trial_days' => $p->trial_days, 'is_public' => $p->is_public, 'features' => $p->featureMap(),
        ])]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $page = PlatformInvoice::with('tenant')
            ->when(in_array($request->query('status'), ['open', 'paid', 'void'], true), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->query('tenant_id')))
            ->latest('issue_date')->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => $page->getCollection()->map(fn (PlatformInvoice $i) => $this->invoiceJson($i))->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function storeTenant(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        return $this->idempotent($request, function () use ($request, $provisioner) {
            TenantController::normalise($request);
            $validator = validator($request->all(), TenantController::provisionRules(), TenantController::validationMessages());
            $validator->after(fn ($v) => TenantController::checkGstinState($v));
            $data = $validator->validate();

            $tenant = $provisioner->provision($data, auth('platform')->user());

            return [201, ['data' => $this->tenantJson($tenant)]];
        });
    }

    public function suspend(Request $request, Tenant $tenant, TenantLifecycle $lifecycle): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->rule(fn () => response()->json(['data' => $this->tenantJson($lifecycle->suspend($tenant, $data['reason'], auth('platform')->user())->load('subscription.plan', 'domains'))]));
    }

    public function reactivate(Tenant $tenant, TenantLifecycle $lifecycle): JsonResponse
    {
        return $this->rule(fn () => response()->json(['data' => $this->tenantJson($lifecycle->reactivate($tenant, auth('platform')->user())->load('subscription.plan', 'domains'))]));
    }

    public function storePayment(Request $request, PlatformInvoice $invoice, InvoiceService $invoices): JsonResponse
    {
        return $this->idempotent($request, function () use ($request, $invoice, $invoices) {
            $data = $request->validate([
                'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
                'method' => ['required', Rule::in(PlatformPayment::METHODS)],
                'reference' => ['nullable', 'string', 'max:100'],
                'paid_on' => ['required', 'date', 'before_or_equal:today'],
            ]);

            $payment = $invoices->recordPayment($invoice, Money::of($data['amount']), $data['method'], $data['reference'] ?? null,
                CarbonImmutable::parse($data['paid_on']), auth('platform')->user());

            return [201, ['data' => ['payment_id' => $payment->id, 'invoice' => $this->invoiceJson($invoice->fresh('tenant'))]]];
        });
    }

    /**
     * Run a write once per Idempotency-Key. A retry with the same key and body
     * returns the stored response; the same key with a different body is a 409.
     *
     * @param  Closure(): array{0: int, 1: array}  $action
     */
    private function idempotent(Request $request, Closure $action): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '' || strlen($key) > 100) {
            return $this->error(400, 'idempotency_key_required', 'Send a unique Idempotency-Key header (up to 100 characters) with this request.');
        }

        $tokenId = $request->attributes->get('platform_token_id');
        $hash = hash('sha256', $request->method().' '.$request->path().' '.json_encode($request->all()));

        $stored = DB::table('platform_idempotency_keys')->where('platform_api_token_id', $tokenId)->where('idempotency_key', $key)->first();
        if ($stored) {
            if ($stored->request_hash !== $hash) {
                return $this->error(409, 'idempotency_key_reused', 'This Idempotency-Key was already used with a different request.');
            }

            return response()->json(json_decode($stored->response, true), $stored->status_code)->header('Idempotent-Replayed', 'true');
        }

        try {
            [$status, $body] = $action();
        } catch (ValidationException $e) {
            return response()->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage(), 'fields' => $e->errors()]], 422);
        } catch (PlatformRuleException $e) {
            return $this->error(422, 'rule_violation', $e->getMessage());
        }

        DB::table('platform_idempotency_keys')->insert([
            'platform_api_token_id' => $tokenId, 'idempotency_key' => $key, 'request_hash' => $hash,
            'status_code' => $status, 'response' => json_encode($body), 'created_at' => now(),
        ]);

        return response()->json($body, $status);
    }

    private function rule(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (PlatformRuleException $e) {
            return $this->error(422, 'rule_violation', $e->getMessage());
        }
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function tenantJson(Tenant $t): array
    {
        $s = $t->subscription;

        return [
            'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'type' => $t->type, 'status' => $t->status,
            'domain' => $t->primaryDomain(), 'contact_email' => $t->contact_email, 'city' => $t->city,
            'billing_state_code' => $t->billing_state_code, 'created_at' => $t->created_at?->toIso8601String(),
            'subscription' => $s ? [
                'id' => $s->id, 'plan' => $s->plan?->code, 'status' => $s->status, 'billing_cycle' => $s->billing_cycle, 'price' => $s->price,
                'trial_ends_at' => $s->trial_ends_at?->toDateString(), 'current_period_end' => $s->current_period_end?->toDateString(),
            ] : null,
        ];
    }

    private function invoiceJson(PlatformInvoice $i): array
    {
        return [
            'id' => $i->id, 'number' => $i->number, 'tenant_id' => $i->tenant_id, 'tenant' => $i->tenant?->name,
            'status' => $i->displayStatus(), 'issue_date' => $i->issue_date->toDateString(), 'due_date' => $i->due_date->toDateString(),
            'subtotal' => $i->subtotal, 'cgst' => $i->cgst, 'sgst' => $i->sgst, 'igst' => $i->igst, 'total' => $i->total,
            'amount_paid' => $i->amount_paid, 'balance' => $i->balance()->toDecimal(),
        ];
    }
}
