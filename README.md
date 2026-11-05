# Quick School AI

Multi-tenant AI education ERP by Quick ERP Technologies Pvt. Ltd. Laravel (PHP 8.4) on **MySQL 8.4 or PostgreSQL 16**, with a Flutter mobile app to follow.

This repository contains:

- the **Super Admin module**: the platform console Quick ERP staff use to run the SaaS;
- the **Academic ERP module**: the institution app that schools, colleges and coaching institutes use every day, with its JSON API for the mobile app;
- the tenancy core both depend on.

## Academic ERP module

One configurable structure serves every kind of institution: **Programme → Level → Section (or batch) → Subjects**. The institution type chosen at onboarding sets the words on screen and the defaults:

| | K-12 school | College | Coaching institute |
| --- | --- | --- | --- |
| Words | Curriculum, Class, Section, Term, Subject | Programme, Semester, Section, Semester, Course | Course, Level, Batch, Module, Subject |
| Created at onboarding | Nursery to Class 12 with CBSE subjects, 2 terms | 2 semesters | Nothing fixed; batches are set up freely |
| Grading | CBSE 8-point grades | 10-point GPA with credits | Percentage with divisions |

| Area | What it does |
| --- | --- |
| Institution and branches | Profile (board, affiliation, UDISE+), working days, attendance mode, required admission documents; branches up to the plan limit |
| Academic sessions | Sessions that cannot overlap, one current, planning → open → closed; terms inside each |
| Classes, sections, courses, batches | Programmes, levels with their subjects, sections or batches with capacity, class teacher, room and schedule; subject teachers and periods per week |
| Admissions | Enquiry → applied → under review → waitlisted or offered → admitted (or rejected or withdrawn, with a reason); follow-ups, entrance test, document checklist; admitting creates the student |
| Students and guardians | Full profile (PEN, APAAR, category), automatic admission numbers, siblings share one guardian record, primary contact, pickup permission, app invitations, roll numbers |
| Attendance | Daily or per period; blocks future dates, holidays, non-working days and dates outside the session; edit window for teachers; monthly register; low-attendance list; an event for absence notifications to parents |
| Timetable | Bell schedule per branch; section and teacher timetables; refuses teacher, room and periods-per-week clashes |
| Examinations, marks and grades | Exams with papers per section and subject; draft → open → published; marks checked against the maximum and absence; publishing needs every mark; subject analysis and toppers |
| Report cards | Weighted combination of published exams, grades from the grading scheme, GPA with credits, pass rules per subject, competition ranks, attendance, remarks; printable |
| Homework | Set per section and subject, submissions from the app, checking with grades and remarks, completion report |
| Certificates | Templates with placeholders: bonafide, character, study, course completion, transfer; numbered per type and financial year; a transfer certificate only after leaving is recorded, one at a time |
| Promotion and transfer | Section and branch moves with history; leaving (transferred or withdrawn); promotion to the next session from report-card results: promote, retain, graduate, leave or decide later |
| Reports | Class strength by gender and capacity, admission register, admissions funnel by stage and source, attendance, homework completion, certificates; CSV download for every list |

**Roles inside an institution:** owner, administrator, front office, teacher, accountant, parent, student. Teachers see and work only with the sections and subjects they teach. Parents and students use the API (mobile app) and see only their own children.

**API for the Flutter app:** `https://{code}.quickschool.app/api/v1/...` with bearer tokens issued per institution (`POST auth/token`). A token from one institution is refused by another, and the API never accepts a browser session.

## Accounting module

Professional double-entry books for each institution, under **Accounts** in the institution app. Amounts are stored as whole paise, so there are no rounding errors.

| Area | What it does |
|---|---|
| Chart of accounts | A ready Indian school chart (31 groups, about 47 ledgers) seeded when an institution is created. Groups nest; ledgers are cash, bank, customer, vendor, student, employee, tax or gateway. System ledgers receive automatic postings and can't be deleted |
| Opening balances | Entered per ledger on the first day of the year; any difference goes to *Opening balance difference*. Revising them reverses the old opening voucher and posts a new one |
| Vouchers | Payment, receipt, contra and journal, entered as drafts and then posted. Each line can carry a cost centre, a branch and a bill reference |
| Invoices | Sales and purchase invoices, expenses, credit notes and debit notes. GST splits into CGST + SGST in your state and IGST outside it, by place of supply. TDS is deducted by the vendor's section, PAN status and single-bill or annual threshold. A vendor's bill number can't be entered twice, and notes can't exceed what is still open on the bill |
| Banking | Cash book and bank book. Bank reconciliation: import CSV statements (repeated lines are skipped), auto-match within 5 days (a matching reference wins), match by hand, and a reconciliation statement |
| Reports | General ledger, day book, trial balance, profit and loss (income and expenditure), balance sheet, cash flow (direct method), outstanding bills with ageing, budget against actual, cost-centre report. Each can be filtered by branch |
| Fixed assets | Asset register with straight-line or written-down-value depreciation, posted monthly and never twice for the same month |

**Rules the engine enforces**, in code and in the database:

- Debits must equal credits, and every line is either a debit or a credit.
- Payment vouchers credit cash or bank. Receipt vouchers debit cash or bank. Contra uses only cash and bank. Journals never touch cash or bank.
- Posted entries are immutable. Database triggers and constraints on PostgreSQL and MySQL refuse any UPDATE or DELETE, and every voucher stores a SHA-256 fingerprint of its entries.
- Corrections are reversals. A reversal posts the opposite entries, keeps both vouchers, and an entry can only be reversed once.
- Numbers like `PV/2026-27/00001` are gapless and assigned only when a voucher posts.
- Approval limits are set per voucher type. The person who entered a voucher can't approve it unless you allow that in settings.
- Period lock: nothing can be posted, approved or reversed on or before the lock date. Only the owner can unlock, and must give a reason. A financial year can be closed.
- Other modules post through `AccountingGateway` with an idempotency key. A retried webhook returns the original voucher, and a retry with a different amount is refused. This covers fee collections (cash, bank, Razorpay, Cashfree into clearing ledgers), gateway settlements, fee refunds and, next, payroll.
- Every change is written to the audit log.

**Permissions:**

| Permission | Roles |
|---|---|
| `accounts.view` | owner, admin, accountant |
| `accounts.post` | owner, admin, accountant |
| `accounts.approve` and `accounts.configure` | owner, admin |

**API** (`/api/v1/accounting/...`, bearer token):

| Method and endpoint | What it does |
|---|---|
| `GET ledgers` | Ledgers with balances |
| `GET vouchers`, `GET vouchers/{id}` | List and view vouchers |
| `POST vouchers` | Create a voucher. Send an `Idempotency-Key` header; a retry returns `Idempotent-Replayed: true` and posts nothing |
| `POST vouchers/{id}/approve`, `/reject`, `/reverse` | Approve, reject or reverse a voucher |
| `POST invoices` | Create an invoice. Also accepts `Idempotency-Key` |
| `GET reports/{trial-balance, profit-loss, balance-sheet, cash-flow}` | Reports as JSON |

## Fee management module

Under **Fees** in the institution app. Every fee transaction is posted to the accounting module through `AccountingGateway`, and none is posted twice.

| Area | What it does |
|---|---|
| Fee heads and structures | Tuition, admission, annual charges, optional transport and refundable caution money are seeded, and each head posts to its own ledger. A structure gives each head a monthly, quarterly, half-yearly, annual or one-time frequency and a due day |
| Student fee plans | Assign a structure to a student, choose optional heads, and start mid-year. Instalments are created for the session and issued a set number of days before they fall due |
| Discounts and scholarships | Percentage or fixed concessions on chosen heads. Assigning one needs a second person's approval, and it is posted to *Fee concessions and scholarships* |
| Late fees | Flat or per-day after a grace period, with a cap. Posted once per invoice and day, and can be waived with a reason |
| Collection | Collection at the counter (cash, cheque, bank transfer, UPI), oldest invoice first, with any excess kept as an advance. An idempotency key on every receipt means a double click or retry never records it twice. Bounced cheques reverse the receipt and reopen the dues |
| Online payments | Razorpay and Cashfree orders through their real APIs. Checkout signatures are verified on the server and the payment is re-fetched from the gateway. Webhooks are signature-checked, protected against replays and processed once. UPI goes through the gateways. Settlements post gateway charges and clear the clearing ledger |
| Refunds | One person requests a refund and a different person approves it. It is then paid out, through the gateway refund API for online payments |
| Receipts and reports | Printable receipts with signed links that expire. Outstanding by student with ageing, collections by mode and day, and a concession register |
| Reminders | `fees:daily` runs every morning (06:30 IST): it issues the instalments that are due, applies late fees and sends reminders on the configured days, each only once |
| Parent access | Parents see and pay only their own children's fees in the mobile app (`/api/v1/fees/...`) |

## HR and payroll module

Under **HR and payroll**, and **My leave and payslips** for every staff member.

| Area | What it does |
|---|---|
| Employees | Employee master, departments, designations, bank details, PAN/UAN and private documents (visible only to HR and the employee). Relieving an employee is recorded too |
| Attendance and shifts | Daily marking or CSV import, shifts with weekly offs, holidays and overtime minutes |
| Leave | Leave types, yearly allocations and balances. HR can't approve their own leave, and approved leave is marked in attendance |
| Salary | Effective-dated salary structures built from earnings and deductions. Revising one keeps the history |
| Statutory rules | PF (with the wage ceiling, EPS and EPF split), ESI, professional tax by state, and TDS under the new and old regimes (standard deduction, 80C, rebate, cess). The rules are effective-dated, so an old payslip keeps the rules it was computed with |
| Payroll | Monthly run with loss of pay, proration, overtime and one-off inputs. A different person approves it, and approval posts the salary journal (salaries, employer PF and ESI, payroll liabilities) and locks the month. Then the bank transfer file is produced and the run is marked paid |
| Payslips and reports | Payslips, the payroll register, and PF, ESI, PT and TDS reports |
| Self-service | Staff apply for leave and see their own payslips, attendance and documents, on the web and in the app |
| Full and final settlement | Pending salary, leave encashment, gratuity and notice-period recovery. It needs approval and is posted to the books |

## AI Intelligence Engine

Two parts: the **AI service** (`ai-service/`, Python FastAPI + LangGraph, see its README) and the **AI module** in this app (`modules/Ai`). Staff open it from **AI assistants**, and parents through the mobile API.

- **Seven assistants:** School, Accounting, Payroll, Admission, Academic, Document Processing and Voice Calling. Each can be switched on or off and given its own provider and model (OpenAI `gpt-4.1-mini`, `gpt-4.1`, `gpt-4o`…; Gemini `gemini-2.5-flash`, `gemini-2.5-pro`…), under **AI settings**.
- **Permission-controlled tools:** about 30 ERP tools (`modules/Ai/Tools/ToolRegistry.php`), each needing a permission. A chat is offered only the tools the signed-in user's role may use, and every call is checked again. The AI service calls them back with a signed request and a 10-minute session token bound to one institution and one user, so tenant isolation and section limits apply exactly as in the app.
- **Human approval for sensitive actions:** vouchers, fee refunds, fee waivers and payroll approval exist only as `propose_*` tools. These create a pending request under **AI approvals**. A person with the target permission, who is not the person who asked, approves it. The action then runs through the module's own service, with the asker as maker and the approver as checker, so accounting, fee and payroll rules still apply. If a rule refuses it (for example a locked period), the request is marked failed and nothing is posted.
- **RAG:** institution documents in the **Knowledge base** are embedded into PostgreSQL + pgvector by the AI service, with the roles allowed to retrieve each one.
- **Conversation history and prompts:** conversations and messages are stored with the tools used, tokens and credits. Prompts are versioned per assistant, with one active version, and the fixed guardrails are always added.
- **Usage and credits:** each reply costs credits per 1,000 tokens by model, and each document read has a fixed cost. Credits come from the platform's AI credit ledger (plan grants and Super Admin top-ups), and a chat is refused when the balance is empty.
- **Multilingual:** replies in English, Hindi, Marathi, Tamil, Telugu, Kannada, Bengali, Gujarati, Malayalam or Punjabi.
- **Document extraction:** marksheets, transfer and birth certificates, and vendor bills are read by a vision model for a person to review and correct.
- **Anomaly detection:** rule-based checks for duplicate payments, payments split under the approval limit, cash payments above ₹10,000, self-approved or back-dated vouchers, round amounts, frequent reversals, large waivers and spending spikes. They are shown under **Unusual entries** and given to the Accounting Assistant as a tool.
- **AI reporting:** ask any assistant for a summary (for example "monthly management summary of fees, expenses and attendance"). It builds the answer from the reports its tools return.

Set `QSA_AI_SERVICE_URL` and `QSA_AI_SERVICE_KEY` here, and the same key, `OPENAI_API_KEY` / `GEMINI_API_KEY` and the pgvector database on the AI service.

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

## Tenant isolation on MySQL and PostgreSQL

Every institution's rows carry `tenant_id`, and four guards keep them apart (see `app/Core/Tenancy`):

1. Requests resolve the institution from the subdomain and check the user's membership.
2. `BelongsToTenant` adds `WHERE tenant_id = ?` to every query on tenant models, and refuses to query or create with no tenant set.
3. The database itself:
   - **PostgreSQL:** Row-Level Security, forced even for the table owner. Rows are visible and writable only when `tenant_id` matches the `app.tenant_id` setting. Platform code may read (never write) across institutions inside `TenantContext::acrossTenants()`. The app must connect as a role that is not a superuser and has no `BYPASSRLS`.
   - **MySQL** (no RLS): triggers reject any insert or update whose `tenant_id` differs from `@app_tenant_id`, including raw SQL.
4. Composite foreign keys `(tenant_id, x_id)` stop a row pointing at another institution's data.

`tests/Feature/Platform/TenantIsolationTest.php` and `tests/Feature/Academic/AcademicIsolationTest.php` prove each guard against the real database.

## Run it locally

Requirements: PHP 8.4 with `pdo_mysql` or `pdo_pgsql`, `mbstring`, `intl`; Composer; MySQL 8.4 or PostgreSQL 16.

For PostgreSQL, create a normal role for the app, for example `CREATE ROLE qsa_app LOGIN PASSWORD '…' NOSUPERUSER NOBYPASSRLS; CREATE DATABASE quick_school_ai OWNER qsa_app;`, and set `DB_CONNECTION=pgsql` in `.env`.

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

To try the institution app locally, set `TENANT_ROUTING=path` in `.env`, then run `php artisan db:seed --class=DemoSchoolSeeder` and open http://localhost:8000/i/demo (owner@demo.test / Demo-School-2026). It has 6 sections, 72 students, two weeks of attendance and a published unit test. In production each institution is at `https://{code}.quickschool.app`.

Schedule the billing run with one cron line: `* * * * * php /path/to/web/artisan schedule:run`.

**Amazon RDS:** set `log_bin_trust_function_creators = 1` in the parameter group so migrations can create the tenant triggers.

## Tests

```bash
cd web && php artisan test
```

Tests use the database in `phpunit.xml` (MySQL `qsa_test`, root/root by default). For PostgreSQL, set `DB_CONNECTION=pgsql` and the other `DB_*` values in the environment, with a normal role so RLS applies. GitHub Actions runs the whole suite on both MySQL 8.4 and PostgreSQL 16 for every push and pull request.

For a quick run without a database server: `php vendor/bin/phpunit -c phpunit.sqlite.xml` (skips the database-guard checks).

## Deploying to production

`deploy/` holds a Docker kit for one Ubuntu 24.04 server: Caddy with automatic per-school HTTPS, PHP 8.4-FPM, queue worker, scheduler, AI service, PostgreSQL 16 + pgvector and Redis. `deploy/install.sh` sets up the server and secrets. `deploy/deploy.sh` rolls out a new version. `.github/workflows/deploy.yml` deploys `main` after CI passes. Nightly backups come with a restore script. Step-by-step guide and launch checklist: [deploy/DEPLOY.md](deploy/DEPLOY.md).

## Values to confirm before launch

These are placeholders in `config/platform.php` and the plan seeder:

- Plan names, prices and limits (Starter, Growth, Enterprise are samples)
- Your GSTIN, state code and address on invoices (`PLATFORM_SELLER_*`)
- GST rate (18%) and SAC code (997331) on platform invoices: confirm with your chartered accountant
- Invoice due days (7) and suspension grace period (14 days)

These are starting values in `config/accounting.php`. Confirm them with your chartered accountant before going live:

- GST rates and which school services are exempt (tuition is generally exempt; hall hire, uniforms or transport may not be)
- TDS sections, rates, the no-PAN rate and thresholds (effective 1 Apr 2025)
- Approval limits per voucher type
- The default chart of accounts and its cash-flow classes
- Professional tax slabs, PF/ESI ceilings and TDS slabs in `config/hr.php`: confirm the current values for your states with your payroll consultant
- AI credit prices per model (`config/ai.php`) against your OpenAI and Gemini costs
