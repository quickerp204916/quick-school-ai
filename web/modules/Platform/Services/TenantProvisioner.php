<?php

namespace Modules\Platform\Services;

use App\Core\Audit\AuditLog;
use App\Core\Support\DocumentNumber;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Modules\Academic\Services\AcademicSetup;
use Modules\Identity\Models\TenantMembership;
use Modules\Identity\Models\User;
use Modules\Institution\Models\AcademicSession;
use Modules\Institution\Models\Branch;
use Modules\Platform\Exceptions\PlatformRuleException;
use Modules\Platform\Mail\OwnerInvitation;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\PlatformAdmin;
use Modules\Platform\Models\Tenant;

/**
 * Onboards an institution in one transaction: tenant, subdomain, main branch,
 * current academic session, owner account and membership, subscription, audit.
 * The owner then gets an email with a link to set their password.
 */
final class TenantProvisioner
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    /**
     * @param array{code: string, name: string, legal_name?: ?string, type: string, plan_id: string, billing_cycle: string,
     *   owner_name: string, owner_email: string, owner_phone?: ?string, contact_phone?: ?string, billing_gstin?: ?string,
     *   billing_state_code?: ?string, address?: ?string, city?: ?string, branch_name?: ?string} $data
     */
    public function provision(array $data, ?PlatformAdmin $by = null): Tenant
    {
        $code = strtolower(trim($data['code']));
        if (in_array($code, config('platform.reserved_codes'), true)) {
            throw new PlatformRuleException("“{$code}” is reserved. Choose another institution code.");
        }
        if (Tenant::where('code', $code)->exists()) {
            throw new PlatformRuleException("The code “{$code}” is already in use.");
        }

        $plan = Plan::findOrFail($data['plan_id']);

        [$tenant, $owner] = DB::transaction(function () use ($data, $code, $plan, $by) {
            $tenant = Tenant::create([
                'code' => $code,
                'name' => trim($data['name']),
                'legal_name' => $data['legal_name'] ?? null,
                'type' => $data['type'],
                'status' => $plan->trial_days > 0 ? 'trial' : 'active',
                'contact_name' => $data['owner_name'],
                'contact_email' => strtolower($data['owner_email']),
                'contact_phone' => $data['contact_phone'] ?? ($data['owner_phone'] ?? null),
                'billing_gstin' => isset($data['billing_gstin']) ? strtoupper($data['billing_gstin']) : null,
                'billing_state_code' => $data['billing_state_code'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'created_by' => $by?->id,
            ]);

            $tenant->domains()->create([
                'domain' => $code.'.'.config('platform.base_domain'),
                'type' => 'subdomain', 'is_primary' => true, 'verified_at' => now(),
            ]);

            $owner = User::where('email', strtolower($data['owner_email']))->first();
            if (! $owner) {
                $phone = $data['owner_phone'] ?? null;
                $owner = User::create([
                    'name' => $data['owner_name'],
                    'email' => strtolower($data['owner_email']),
                    'phone' => ($phone && ! User::where('phone', $phone)->exists()) ? $phone : null,
                    'password' => Str::random(48),   // unusable until the owner sets one from the invitation
                ]);
            }

            TenantContext::run($tenant, function () use ($tenant, $data, $owner, $by, $plan) {
                $branch = Branch::create([
                    'code' => 'MAIN', 'name' => $data['branch_name'] ?? 'Main campus', 'is_primary' => true,
                    'address' => $data['address'] ?? null, 'city' => $data['city'] ?? null, 'state_code' => $data['billing_state_code'] ?? null,
                ]);

                $fy = DocumentNumber::financialYear(now());
                $startYear = (int) substr($fy, 0, 4);
                $session = AcademicSession::create([
                    'name' => $fy, 'starts_on' => "{$startYear}-04-01", 'ends_on' => ($startYear + 1).'-03-31', 'is_current' => true,
                ]);

                // Academic defaults: profile, grading, terms, bell schedule, certificates (and classes for schools).
                app(AcademicSetup::class)->seed($tenant, $branch, $session);
                app(\Modules\Accounting\Services\AccountingSetup::class)->seed($tenant, $branch, $session);
                app(\Modules\Fees\Services\FeeSetup::class)->seed();
                app(\Modules\Hr\Services\HrSetup::class)->seed();

                TenantMembership::create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'default_branch_id' => $branch->id]);

                AuditLog::create([
                    'actor_type' => $by ? 'platform_admin' : 'system', 'actor_id' => $by?->id,
                    'action' => 'tenant.provisioned', 'subject_type' => 'Tenant', 'subject_id' => $tenant->id,
                    'changes' => ['plan' => $plan->code, 'owner' => $owner->email],
                ]);
            });

            $this->subscriptions->start($tenant, $plan, $data['billing_cycle'], $by);

            PlatformAudit::record('tenant.provisioned', $tenant, $tenant->id,
                ['code' => $code, 'plan' => $plan->code, 'cycle' => $data['billing_cycle'], 'owner' => $owner->email], $by);

            return [$tenant, $owner];
        });

        $token = Password::broker('users')->createToken($owner);
        $link = 'https://'.$tenant->code.'.'.config('platform.base_domain').'/set-password?'.http_build_query(['token' => $token, 'email' => $owner->email]);
        Mail::to($owner->email)->send(new OwnerInvitation($tenant, $owner->name, $link));

        return $tenant->fresh(['domains', 'subscription.plan']);
    }
}
