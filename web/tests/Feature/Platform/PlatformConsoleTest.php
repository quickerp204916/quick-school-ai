<?php

namespace Tests\Feature\Platform;

use App\Core\Support\Totp;
use Modules\Platform\Models\Announcement;
use Modules\Platform\Models\PlatformAdmin;

class PlatformConsoleTest extends PlatformTestCase
{
    public function test_login_requires_the_right_password_and_an_active_account(): void
    {
        $admin = $this->admin();

        $this->post(route('platform.login.attempt'), ['email' => $admin->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest('platform');

        $admin->update(['is_active' => false]);
        $this->post(route('platform.login.attempt'), ['email' => $admin->email, 'password' => 'Correct-Horse-42'])->assertSessionHasErrors('email');
        $this->assertGuest('platform');
    }

    public function test_password_leads_to_the_two_factor_step(): void
    {
        $admin = $this->admin();

        $this->post(route('platform.login.attempt'), ['email' => strtoupper($admin->email), 'password' => 'Correct-Horse-42'])
            ->assertRedirect(route('platform.mfa'));
        $this->assertAuthenticatedAs($admin, 'platform');
    }

    public function test_console_pages_need_two_factor_first(): void
    {
        $this->actingAs($this->admin(), 'platform')->get(route('platform.dashboard'))->assertRedirect(route('platform.mfa'));
    }

    public function test_first_sign_in_sets_up_two_factor(): void
    {
        $admin = $this->admin('super_admin', ['mfa_secret' => null, 'mfa_confirmed_at' => null]);
        $secret = Totp::generateSecret();

        $this->actingAs($admin, 'platform')->withSession(['platform.mfa_setup_secret' => $secret])
            ->post(route('platform.mfa.verify'), ['code' => '000000'])->assertSessionHasErrors('code');

        $this->actingAs($admin, 'platform')->withSession(['platform.mfa_setup_secret' => $secret])
            ->post(route('platform.mfa.verify'), ['code' => Totp::code($secret)])->assertRedirect(route('platform.dashboard'));

        $admin->refresh();
        $this->assertNotNull($admin->mfa_confirmed_at);
        $this->assertSame($secret, $admin->mfa_secret);
    }

    public function test_returning_admin_signs_in_with_their_code(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'platform')->post(route('platform.mfa.verify'), ['code' => Totp::code('JBSWY3DPEHPK3PXP')])
            ->assertRedirect(route('platform.dashboard'))->assertSessionHas('platform.mfa_passed', true);
    }

    public function test_roles_limit_what_staff_can_do(): void
    {
        $tenant = $this->provision();
        $support = $this->admin('support');
        $billing = $this->admin('billing');

        $this->signIn($support)->get(route('platform.tenants.show', $tenant))->assertOk();
        $this->signIn($support)->get(route('platform.tenants.create'))->assertForbidden();
        $this->signIn($support)->get(route('platform.invoices.index'))->assertForbidden();
        $this->signIn($support)->post(route('platform.tenants.suspend', $tenant), ['reason' => 'Testing roles'])->assertForbidden();
        $this->signIn($support)->get(route('platform.support.index'))->assertOk();

        $this->signIn($billing)->get(route('platform.invoices.index'))->assertOk();
        $this->signIn($billing)->get(route('platform.plans.create'))->assertOk();
        $this->signIn($billing)->get(route('platform.support.index'))->assertForbidden();
        $this->signIn($billing)->get(route('platform.admins.index'))->assertForbidden();
    }

    public function test_every_console_page_renders_for_a_super_admin(): void
    {
        $plan = $this->plan();
        $plan->update(['trial_days' => 0]);
        $tenant = $this->provision(['plan_id' => $plan->id]);
        $invoice = $tenant->invoices()->first();
        $announcement = Announcement::create(['title' => 'Maintenance on Sunday', 'body' => '2 am to 4 am IST', 'level' => 'warning', 'audience' => 'all', 'starts_at' => now()]);
        $admin = $this->admin();

        $pages = [
            route('platform.dashboard') => 'Platform overview',
            route('platform.tenants.index') => $tenant->name,
            route('platform.tenants.create') => 'Onboard an institution',
            route('platform.tenants.show', $tenant) => 'Features and limits',
            route('platform.tenants.edit', $tenant) => 'Edit',
            route('platform.plans.index') => 'Starter',
            route('platform.plans.create') => 'New plan',
            route('platform.plans.edit', $plan) => 'Edit Starter',
            route('platform.invoices.index') => $invoice->number,
            route('platform.invoices.show', $invoice) => 'CGST',
            route('platform.support.index') => 'Active now',
            route('platform.announcements.index') => 'Maintenance on Sunday',
            route('platform.announcements.create') => 'New announcement',
            route('platform.announcements.edit', $announcement) => 'Edit announcement',
            route('platform.audit.index') => 'tenant.provisioned',
            route('platform.admins.index') => 'API tokens',
        ];

        foreach ($pages as $url => $text) {
            $this->signIn($admin)->get($url)->assertOk()->assertSee($text, false);
        }
    }

    public function test_suspend_and_reactivate_from_the_console(): void
    {
        $tenant = $this->provision();

        $this->signIn()->post(route('platform.tenants.suspend', $tenant), ['reason' => 'Requested by trustee'])->assertSessionHasNoErrors();
        $this->assertSame('suspended', $tenant->fresh()->status);

        $this->signIn()->post(route('platform.tenants.reactivate', $tenant))->assertSessionHasNoErrors();
        $this->assertSame('trial', $tenant->fresh()->status);
    }

    public function test_archive_needs_the_code_typed(): void
    {
        $tenant = $this->provision();

        $this->signIn()->post(route('platform.tenants.archive', $tenant), ['reason' => 'Closed down', 'confirm_code' => 'wrong'])->assertSessionHasErrors('confirm_code');
        $this->signIn()->post(route('platform.tenants.archive', $tenant), ['reason' => 'Closed down', 'confirm_code' => $tenant->code])->assertSessionHasNoErrors();

        $this->assertSame('archived', $tenant->fresh()->status);
        $this->assertSame('cancelled', $tenant->subscription()->first()->status);
    }

    public function test_plan_edit_saves_prices_and_features(): void
    {
        $plan = $this->plan('starter');
        $features = ['students_max' => '400', 'branches_max' => '1', 'staff_max' => '50', 'ai_credits_monthly' => '500', 'ai_assistant' => '1', 'whatsapp' => '1'];

        $this->signIn($this->admin('billing'))->put(route('platform.plans.update', $plan), [
            'code' => 'starter', 'name' => 'Starter', 'price_monthly' => '3499', 'price_yearly' => '34990', 'trial_days' => 14,
            'is_active' => '1', 'is_public' => '1', 'features' => $features,
        ])->assertRedirect(route('platform.plans.index'));

        $plan->refresh()->load('features');
        $this->assertSame('3499.00', $plan->price_monthly);
        $this->assertSame(400, $plan->featureMap()['students_max']);
        $this->assertTrue($plan->featureMap()['ai_assistant']);
        $this->assertFalse($plan->featureMap()['transport']);
    }

    public function test_cannot_disable_the_last_super_admin(): void
    {
        $only = $this->admin();
        $this->signIn($only)->post(route('platform.admins.toggle', $only))->assertSessionHasErrors('action');

        $support = $this->admin('support');
        $this->signIn($only)->post(route('platform.admins.toggle', $support))->assertSessionHasNoErrors();
        $this->assertFalse($support->fresh()->is_active);
        $this->assertSame(1, PlatformAdmin::where('role', 'super_admin')->where('is_active', true)->count());
    }
}
