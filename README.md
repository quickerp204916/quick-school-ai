# Quick School AI

Multi-tenant AI education ERP by Quick ERP Technologies Pvt. Ltd. Laravel (PHP 8.4) and MySQL 8.4, with a Flutter mobile app to follow.

This repository currently contains the **Super Admin module** (the platform console that Quick ERP staff use to run the SaaS) and the tenancy core it depends on.

## What the Super Admin module does

| Area | Features |
| --- | --- |
| Institutions | Onboard in one step (institution, subdomain, main branch, current academic session, owner account and invitation email, subscription), search and filter, edit details, suspend with a reason, reactivate, archive (typed confirmation) |
| Plans | Plans with monthly and yearly prices, trial days, public or private, and a feature matrix (limits such as students and branches, switches such as AI assistant and transport) |
| Features | Per-institution overrides with a reason and optional expiry. A `FeatureGate` service answers "is this on?" and "may they add another?" for the rest of the ERP |
| Billing | Subscriptions with trials, plan changes, cancel now or at period end. GST tax invoices to institutions (CGST + SGST in the seller's state, IGST otherwise), gapless numbers per financial year, payments (part or full), void |
| Daily billing run | `php artisan platform:billing-run`: ends trials, renews periods, marks overdue subscriptions past due and suspends them after a grace period, lifts billing suspensions when paid, grants monthly AI credits. Every step is safe to run twice |
| AI credits | Per-institution ledger: monthly grants from the plan, top-ups, adjustments, usage that can never go below zero |
| Support access | Time-limited (5 to 120 minutes) access with a written reason, recorded in the institution's own audit log |
| Announcements | Messages to all institutions or one plan, with start and end times |
| Staff | Roles: super admin, billing, support. Mandatory two-factor sign-in (TOTP authenticator apps). Disable accounts, reset two-factor. The last super admin cannot be disabled |
| Audit | Insert-only log of every console and API action, filterable |
| API | `/api/v1/platform/...` with bearer tokens (read or write), rate limits, JSON errors, and `Idempotency-Key` on writes |

## Tenant isolation on MySQL

MySQL has no Row-Level Security, so four guards stand in for it (see `app/Core/Tenancy`):

1. Requests resolve the tenant and check membership.
2. `BelongsToTenant` adds `WHERE tenant_id = ?` to every query on tenant models, and refuses to query or create with no tenant set.
3. Database triggers reject any insert or update whose `tenant_id` differs from the connection's `@app_tenant_id`, including raw SQL.
4. Composite foreign keys `(tenant_id, x_id)` stop a row pointing at another institution's data.

`tests/Feature/Platform/TenantIsolationTest.php` proves each guard against a real MySQL database.

## Run it locally

Requirements: PHP 8.4 with `pdo_mysql`, `mbstring`, `bcmath`, `intl`; Composer; MySQL 8.4.

```bash
cd web
composer install
cp .env.example .env && php artisan key:generate
# set DB_* in .env, then:
php artisan migrate --seed
php artisan platform:create-admin you@quickerp.in "Your Name"
php artisan serve
```

Open http://localhost:8000/platform, sign in, and scan the setup key into an authenticator app.

Schedule the billing run with one cron line: `* * * * * php /path/to/web/artisan schedule:run`.

**Amazon RDS:** set `log_bin_trust_function_creators = 1` in the parameter group so migrations can create the tenant triggers.

## Tests

```bash
cd web && php artisan test
```

Tests need MySQL (they check triggers and foreign keys), using the database in `phpunit.xml` (`qsa_test`, root/root by default). GitHub Actions runs the whole suite on MySQL 8.4 for every push and pull request.

## Values to confirm before launch

These are placeholders in `config/platform.php` and the plan seeder:

- Plan names, prices and limits (Starter, Growth, Enterprise are samples)
- Your GSTIN, state code and address on invoices (`PLATFORM_SELLER_*`)
- GST rate (18%) and SAC code (997331) on platform invoices: confirm with your chartered accountant
- Invoice due days (7) and suspension grace period (14 days)
