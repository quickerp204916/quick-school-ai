# Deploying Quick School AI

This kit runs the whole platform on one Ubuntu 24.04 server with Docker. It works on any provider: AWS Lightsail/EC2, DigitalOcean, E2E Networks, Hetzner and others.

```
 Internet ──► Caddy :80/:443  (automatic HTTPS, one certificate per school, issued on demand)
               │
               ├─► app        Laravel 12 on PHP 8.4-FPM
               ├─  queue      queue:work (redis)
               ├─  scheduler  schedule:work: billing 02:15 IST, fee jobs 06:30 IST
               ├─► ai-service FastAPI + LangGraph (internal only)
               ├─  postgres   PostgreSQL 16 + pgvector  (ERP database with Row-Level Security, AI vectors)
               └─  redis      cache and queues
```

Only ports 22, 80 and 443 are open. The database, Redis, PHP and the AI service are reachable only on the private Docker network.

## What you need

| Item | Notes |
|---|---|
| Server | Ubuntu 24.04, **4 GB RAM / 2 vCPU / 80 GB SSD** to start (about 30–60 schools). 8 GB once AI use grows. India region (Mumbai/Delhi) for latency. |
| Domain | For example `quickschool.app`. |
| DNS | Two **A records** pointing to the server IP: `quickschool.app` and `*.quickschool.app`. |
| E-mail sending | Any SMTP service (Amazon SES, Zoho, Postmark, Brevo) for password resets, invoices and reminders. |
| AI keys (optional) | OpenAI and/or Gemini API key. Without them the ERP works and the AI features stay off. |
| GitHub | A private repository, for automatic deploys. |
| Off-site backups | Strongly recommended: an S3/B2/R2 bucket configured in `rclone`. |

## 1. Put the code on GitHub

From the project folder on your computer:

```bash
git remote add origin git@github.com:<your-org>/quick-school-ai.git
git push -u origin main
```

## 2. Prepare the server (about 15 minutes)

SSH in as root, then:

```bash
# A read-only deploy key lets the server pull the private repository.
ssh-keygen -t ed25519 -N "" -f ~/.ssh/github_deploy -C "qsa-server"
cat ~/.ssh/github_deploy.pub
#   GitHub ▸ repository ▸ Settings ▸ Deploy keys ▸ Add key  (read-only)
printf 'Host github.com\n  IdentityFile ~/.ssh/github_deploy\n' >> ~/.ssh/config

git clone git@github.com:<your-org>/quick-school-ai.git /opt/quick-school-ai
cd /opt/quick-school-ai
./deploy/install.sh --domain quickschool.app --email ops@quickerptechnologies.com
```

`install.sh` does the rest:
- installs Docker and the firewall
- adds 2 GB of swap
- creates a `deploy` user
- writes `deploy/.env` with freshly generated secrets: app key, database, Redis and AI service passwords
- builds and starts everything and runs the migrations
- loads the subscription plans
- schedules a nightly backup

The deploy user pulls code, so give it the same deploy key:

```bash
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
cp ~/.ssh/github_deploy* /home/deploy/.ssh/ && cp ~/.ssh/config /home/deploy/.ssh/config
chown -R deploy:deploy /home/deploy/.ssh && chmod 600 /home/deploy/.ssh/*
```

## 3. Create the super admin and finish settings

```bash
cd /opt/quick-school-ai
docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env \
  exec app php artisan platform:create-admin you@yourcompany.com "Your Name"
```

You type the password on the server. It is never written to a file.

Then open `deploy/.env` (`nano deploy/.env`) and fill in the following, then run `sudo -u deploy ./deploy/deploy.sh --no-pull`:
- `MAIL_*`
- `PLATFORM_SELLER_GSTIN` and `PLATFORM_SELLER_ADDRESS`
- optionally `OPENAI_API_KEY` / `GEMINI_API_KEY`
- optionally `BACKUP_RCLONE_REMOTE`

Sign in at `https://quickschool.app/platform`, set up MFA and create the first institution. It is live at `https://<code>.quickschool.app` once its certificate is issued, which takes a few seconds on the first visit.

## 4. Automatic deploys from GitHub

Every push to `main` runs CI (Laravel on MySQL and PostgreSQL, plus the AI service tests). When CI passes, `.github/workflows/deploy.yml` SSHes to the server and deploys that exact commit.

On your computer:

```bash
ssh-keygen -t ed25519 -N "" -f qsa_actions -C "github-actions"
ssh-copy-id -i qsa_actions.pub deploy@<server-ip>      # or append it to /home/deploy/.ssh/authorized_keys
ssh-keyscan -t ed25519 <server-ip>                      # copy the output for DEPLOY_KNOWN_HOSTS
```

Add these in GitHub ▸ repository ▸ Settings ▸ Secrets and variables ▸ Actions:

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | server IP |
| `DEPLOY_USER` | `deploy` |
| `DEPLOY_SSH_KEY` | contents of `qsa_actions` (the private key) |
| `DEPLOY_KNOWN_HOSTS` | the `ssh-keyscan` line |

Also create an environment named `production` (Settings ▸ Environments). If you add yourself as a required reviewer there, each deploy waits for your click. Delete `qsa_actions` from your computer after saving it in GitHub.

You can also deploy by hand: `sudo -u deploy /opt/quick-school-ai/deploy/deploy.sh`.

## 5. Payment gateways

In Razorpay and Cashfree, set each school's webhook URL to:

```
https://<code>.quickschool.app/api/v1/webhooks/fees/razorpay
https://<code>.quickschool.app/api/v1/webhooks/fees/cashfree
```

Each school enters its own gateway keys and webhook secret in the ERP under Fees ▸ Fee setup.

## Day-to-day

The compose shortcut used below is `dc="docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env"`.

| Task | Command (from `/opt/quick-school-ai`) |
|---|---|
| Status | `$dc ps` |
| Logs | `$dc logs -f app queue scheduler` |
| Artisan | `$dc exec app php artisan <command>` |
| Maintenance mode | `$dc exec app php artisan down` / `up` |
| Backup now | `sudo ./deploy/backup.sh` |
| Restore | `sudo ./deploy/restore.sh /var/backups/quick-school-ai/<timestamp>` |

Backups run nightly at 02:30 IST into `/var/backups/quick-school-ai`. Each one holds:
- both databases
- every uploaded document
- a checksum file

They are kept for 14 days. A backup that stays on the same server does not protect you if the server is lost, so set `BACKUP_RCLONE_REMOTE`. Test a restore on a spare server before launch.

## Launch checklist

- [ ] DNS: `A quickschool.app` and `A *.quickschool.app` → server IP
- [ ] `install.sh` finished and printed "Quick School AI is running"
- [ ] Super admin created, MFA enabled
- [ ] `MAIL_*` set; a password reset e-mail arrives
- [ ] Seller GSTIN, address and state code set; one platform invoice checked by your CA
- [ ] Plans and prices reviewed in the console (Plans)
- [ ] First school created; `https://<code>.quickschool.app` loads with a valid certificate
- [ ] Razorpay/Cashfree test payment reaches the ERP via webhook
- [ ] AI keys set (optional); the AI assistant answers in a school
- [ ] `BACKUP_RCLONE_REMOTE` set; `backup.sh` run once by hand; one test restore done
- [ ] GitHub secrets added; a push to `main` deployed automatically
- [ ] Uptime monitor (UptimeRobot, Better Stack) on `https://quickschool.app/up`
- [ ] Provider snapshots or backups enabled for the server disk

## Scaling later

- **Bigger server first.** Raise `pm.max_children` in `deploy/docker/fpm-pool.conf` with the RAM.
- **Managed database.** Move PostgreSQL to a managed service (AWS RDS, DigitalOcean): point `DB_HOST` at it, keep the `qsa_app` role `NOBYPASSRLS`, and enable `pgvector` there. Then remove the `postgres` service.
- **More workers.** Run more queue workers with `$dc up -d --scale queue=3`.
- **Several app servers.** Put a load balancer in front, set `TRUSTED_PROXIES` to its private range, and move uploaded documents to S3-compatible storage.
