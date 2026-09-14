# Deployment Rules

`vps.onlinebros.com` (this workspace) is **development only**. Production runs on separate DigitalOcean infrastructure, described below.

Agents do production work only when the project owner explicitly asks for it. The owner first asked on 2026-09-14, for web server setup and the q3.life landing site. Confirm scope before any change to production, and never deploy the member application to production unasked.

## Production web tier: `ubuntu-q3-webapp-01`

| | |
|---|---|
| IP | `142.93.181.39` (DigitalOcean droplet, Ubuntu 26.04 LTS, 2 vCPU / 4 GB / 80 GB) |
| Role | Web tier only. **No database on this server.** The database is a separate managed cluster. |
| Serves | `q3.life` + `www.q3.life`: static landing site. `app.q3.life`: the member app (Laravel), first deployed 2026-09-14. |
| DNS | Cloudflare, proxied (orange cloud) for q3.life, www and app |

### Access

- From the dev VPS: `ssh liontraining-prod`. Key `~/.ssh/liontraining_prod`, alias in `~/.ssh/config`.
- Only `deploy` can log in (key only, passwordless sudo). Root login and password login are off (`/etc/ssh/sshd_config.d/00-hardening.conf`).
- Break-glass: DigitalOcean web console.

### Server baseline

- ufw: deny inbound except 22, 80 and 443
- fail2ban (sshd jail) and unattended-upgrades enabled
- nginx site `/etc/nginx/sites-available/q3.life`. Docroot `/var/www/q3.life/public` (owned by `deploy:www-data`). The default server drops unknown hosts (444 / TLS handshake rejected).
- TLS: Let's Encrypt cert for `q3.life` + `www.q3.life` via webroot `/var/www/certbot`. `certbot.timer` renews it and a deploy hook reloads nginx. The dry-run renewal passed on 2026-09-14.
- Cloudflare SSL mode should be **Full (strict)**, since the origin has a valid certificate.

### Database

- DigitalOcean managed PostgreSQL 18.6: `db-pgsql-q3-01-do-user-42597543-0.g.db.ondigitalocean.com`, port 25060, `sslmode=require`. The connection from the droplet was verified on 2026-09-14 (TLS 1.3).
- `max_connections` is **25**, and DigitalOcean keeps a few for itself. PHP-FPM children plus queue workers must fit under that; add a DigitalOcean connection pool (PgBouncer) before scaling workers.
- The droplet has only `postgresql-client` installed. Credentials are in `~deploy/.pgpass` (mode 600). They are never committed or written anywhere in this repo.
- Before deploying the app: create a least-privilege app role and database, enable `ltree` and `pgcrypto`, restrict the cluster's Trusted Sources to the droplet, and rotate the `doadmin` password.

### Object storage

- DigitalOcean Spaces bucket `storage-q3-01`, region `nyc3`: `https://storage-q3-01.nyc3.digitaloceanspaces.com`.
- The app uses it through Laravel's `s3` disk: endpoint `https://nyc3.digitaloceanspaces.com`, region `nyc3`, bucket `storage-q3-01`, virtual-hosted style (`AWS_USE_PATH_STYLE_ENDPOINT=false`).
- Needs a Spaces access key scoped to this bucket only. It goes in the production `.env` on the droplet, never in the repo.

### Transactional email (Resend)

- Provider: Resend. The sending domain `q3.life` was verified on 2026-09-14 (region us-east-1). Sending is on and receiving is off. The DKIM record is `resend._domainkey.q3.life`, and SPF/bounce MX are on `send.q3.life`.
- DigitalOcean **blocks outbound SMTP on 587 and 465** from the droplet (2587 is open). Use Laravel's `resend` mailer, which goes over the HTTPS API via `resend/resend-php`. Don't use `smtp`.
- The API key is stored on the droplet at `~deploy/.config/q3/resend.env` (mode 600). When the app is deployed, copy it into the production `.env` as `RESEND_API_KEY`. Never commit it.
- Production `.env`: `MAIL_MAILER=resend`, `RESEND_API_KEY=…`, `MAIL_FROM_NAME="Quantum 3 Solution"`, `MAIL_FROM_ADDRESS=<address>@q3.life` (sender address not yet chosen).
- Verified 2026-09-14: a test send from the droplet to `delivered@resend.dev` was delivered.
- Before the app launches: add a DMARC record (`_dmarc.q3.life`; none exists yet), and replace the current full-access key with a sending-only key scoped to `q3.life`.

## Member app: `app.q3.life`

The owner asked for it on 2026-09-14 and chose: git-based deploys from GitHub `main`, **live** Stripe (keys not yet supplied), public access (signup stays invite-only), a fresh database with a founder account, and `QL_PRELAUNCH=true`.

| | |
|---|---|
| Runtime | PHP 8.5 (Ubuntu 26.04 packages; dev runs 8.4), Composer 2.9, Node 22 for the Vite build |
| Layout | `/var/www/app.q3.life/releases/<timestamp>` (full repo clones), `current` symlink to the live release, `shared/.env` and `shared/storage` linked into each release |
| nginx | `/etc/nginx/sites-available/app.q3.life` (source: `deploy/app.q3.life/nginx.conf`). Root `current/backend/public`. HTTP redirects to HTTPS. Cloudflare real-IP snippet included |
| TLS | Let's Encrypt `app.q3.life` via webroot `/var/www/certbot`, renewed by `certbot.timer` |
| PHP-FPM | `www` pool, `pm.max_children = 10`, which keeps within the database's 25-connection limit. `memory_limit 256M`, uploads up to 100M |
| Queue | `q3-queue.service` (systemd, runs as www-data, `queue:work database`). Source: `deploy/app.q3.life/q3-queue.service` |
| Scheduler | None. The app defines no scheduled tasks. Add a `schedule:run` timer when it does |
| Database | Managed Postgres database `q3_app`, owner role `q3app` (connection limit 20), `ltree` + `pgcrypto`. Credentials in `~deploy/.config/q3/db-app.env` and `shared/.env` |
| Mail | `MAIL_MAILER=resend`, from `noreply@q3.life` |
| Files | `FILESYSTEM_DISK=local` (shared/storage) until Spaces keys exist |
| GitHub | Read-only deploy key `~deploy/.ssh/github_liontraining` (repo deploy key id 163282666) |

### Deploying

From the dev VPS, after the change has been reviewed on q3.onlinebros.com and pushed to `main`:

```bash
ssh liontraining-prod /var/www/app.q3.life/deploy.sh          # or: deploy.sh <branch-or-tag>
```

The script clones, runs `composer install --no-dev`, `npm ci && npm run build`, `migrate --force`, `storage:link` and `optimize`, then switches `current`, reloads PHP-FPM and restarts the queue worker. If any step fails, the previous release keeps serving. It keeps 5 releases. Rollback steps are in the script header. Rolling back does not reverse migrations.

The production `.env` exists only on the droplet. Change it there, then run `php artisan optimize` in `current/backend` and `sudo systemctl reload php8.5-fpm && sudo systemctl restart q3-queue`.

### Still open

- Q3 live Stripe is **done** (2026-09-14). Account `acct_1UDn1uDY1DawuO70` (Quantum 3 Solution LLC). Webhook `we_1UFemoDY1DawuO708eeB8xKo` goes to `/api/webhooks/stripe`, pinned to API `2025-08-27.basil` with 9 events. Product "Q3 Partner Membership" `prod_VGBAAal4mHglUV`, price `price_1UFenhDY1DawuO70qdffHGsz` at $49.99/month. `billing:preflight` passes, with Connect warnings only: nothing handles Connect events, so no Connect webhook exists. The card statement reads `QUANTUM 3 SOLUTION`
- Card on file at signup (2026-09-14). Every partner, pre-launch included, must save a card before the member area opens: the `subscribed` gate is on the member routes, and profile, support and billing are exempt. Saving a card opens a trial parked until launch. One card backs one account permanently via `card_fingerprints`. Apple Pay, Google Pay and Link are hidden in the Payment Element and refused server-side. The live Stripe account has Link and Apple Pay **on** at account level, so keep those client and server refusals. Verified end to end in a real browser against the sandbox (Playwright, script kept out of the repo). On production only the owner's in-browser test with a real card is left
- Partner payouts via Stripe Connect (built 2026-09-14; the owner chose to turn it on live). Partners set up a connected account on **Get Paid** (`/member/payouts`, behind the card gate) inside Stripe's embedded components.
  - **Onboarding and prefill:** `controller.requirement_collection=application` with no Stripe dashboard, so there is no Stripe sign-in pop-up. The live platform already accepts that responsibility. Accounts request `transfers` plus `tax_reporting_us_1099_misc`, so Stripe files the 1099s. The partner's name, email, phone and address are prefilled when valid, with a retry without prefill if Stripe refuses them.
  - **Paying and tracking:** admins pay approved commission payouts with "Pay via Stripe", a transfer from the Q3 balance, and can reverse one. **Admin → Billing → Payout Accounts** shows what Stripe still needs and emails partners.
  - **Webhooks:** the Connect endpoint `/api/webhooks/stripe/connect` (secret `STRIPE_WEBHOOK_CONNECT_SECRET`) needs `account.updated` and `payout.failed`. The platform endpoint also needs `transfer.updated` and `transfer.reversed`.
  - **Stripe notices:** Stripe now adds a `Stripe-Notice` header ("use Accounts v2") to v1 Accounts calls, and stripe-php raises it as a PHP warning that Laravel turns into a 500. `NoticeLoggingHttpClient` (installed in AppServiceProvider) logs it instead. Don't remove it.
  - **Resetting an account:** `php artisan connect:reset-account <email>` deletes a partner's account so it can be rebuilt.
- PlasmaGuard live Stripe: restricted and publishable keys installed, `PLASMAGUARD_STRIPE_MODE=live` (account `acct_1UChdNEHqqaCFxmC`, taken from the key prefix; it differs from the dev test account). Still missing: `PLASMAGUARD_LIVE_WEBHOOK_SECRET`. PlasmaGuard must add an endpoint `https://app.q3.life/api/webhooks/vendor/plasmaguard` for `payment_intent.succeeded`, `charge.succeeded`, `charge.refunded`, `checkout.session.completed` and `checkout.session.async_payment_succeeded`. Until then, conversions are marked by hand. FedEx credentials are also missing, so quotes fall back to freight and no card payment can be taken yet
- Turnstile: on 2026-09-14 the owner chose Cloudflare's always-pass **test** keys so the site could go live for the Stripe application. The live q3.life build and app.q3.life both use them, with `TURNSTILE_ALLOWED_HOSTNAMES` empty. That setup gives no bot protection. Replace with a real widget (hostnames q3.life, www.q3.life, q3.onlinebros.com): put the site key in `site.json`, set the secret and `TURNSTILE_ALLOWED_HOSTNAMES=q3.life,www.q3.life` in the app `.env`, then rebuild with `./deploy.sh live`
- FedEx (and the EasyPost carrier account), Vimeo credentials, and Spaces access keys
- The CI workflow `.github/workflows/ci.yml` is uncommitted: the GitHub token in `origin` lacks the `workflow` scope

## Landing site deploy

The site is static files. To publish, copy the built site into the docroot:

```bash
rsync -av --delete <site-dir>/ liontraining-prod:/var/www/q3.life/public/
```

No reload is needed for content changes. After editing nginx config, run `sudo nginx -t && sudo systemctl reload nginx`.
