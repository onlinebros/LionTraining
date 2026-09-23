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
| Scheduler | None yet, although the app now defines scheduled tasks (`routes/console.php`): vendor event sync, commission billing holds, and the presentation tasks. Until a `schedule:run` timer exists, scheduled presentations have to be started by hand |
| Video | `ffmpeg` and `ffprobe` (apt) are needed for recording upload, trim and combine. Recordings are stored on the local public disk (`RECORDINGS_DISK=public` in `shared/.env`) until Spaces keys exist. Without that override, the set `AWS_BUCKET` selects the Spaces disk and uploads fail |
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
  - **Onboarding:** `controller.requirement_collection=application` with no Stripe dashboard, so there is no Stripe sign-in pop-up. The live platform already accepts that responsibility. Accounts request `transfers` plus `tax_reporting_us_1099_misc`, so Stripe files the 1099s.
  - **Business type is the partner's choice (2026-09-15):** accounts are created with no `business_type`, so Stripe's form opens on the business-type step and partners who operate through an LLC or corporation can pick it. Only the email is prefilled. Stripe refuses `individual` details without a business type, and a business type cannot be cleared once set, so accounts created before this change skip the step until they are rebuilt with `connect:reset-account`.
  - **Paying and tracking:** admins pay approved commission payouts with "Pay via Stripe", a transfer from the Q3 balance, and can reverse one. **Admin → Billing → Payout Accounts** shows what Stripe still needs and emails partners.
  - **Webhooks:** the Connect endpoint `/api/webhooks/stripe/connect` (secret `STRIPE_WEBHOOK_CONNECT_SECRET`) needs `account.updated` and `payout.failed`. The platform endpoint also needs `transfer.updated` and `transfer.reversed`.
  - **Stripe notices:** Stripe now adds a `Stripe-Notice` header ("use Accounts v2") to v1 Accounts calls, and stripe-php raises it as a PHP warning that Laravel turns into a 500. `NoticeLoggingHttpClient` (installed in AppServiceProvider) logs it instead. Don't remove it.
  - **Resetting an account:** `php artisan connect:reset-account <email>` deletes a partner's account so it can be rebuilt.
- PlasmaGuard live Stripe: restricted and publishable keys installed, `PLASMAGUARD_STRIPE_MODE=live` (account `acct_1UChdNEHqqaCFxmC`, taken from the key prefix; it differs from the dev test account). Still missing: `PLASMAGUARD_LIVE_WEBHOOK_SECRET`. PlasmaGuard must add an endpoint `https://app.q3.life/api/webhooks/vendor/plasmaguard` for `payment_intent.succeeded`, `charge.succeeded`, `charge.refunded`, `checkout.session.completed` and `checkout.session.async_payment_succeeded`. Until then, conversions are marked by hand
- FedEx (2026-09-15): the owner supplied production keys for our FedEx developer project. They go in the app `.env` as `FEDEX_MODE=live`, `FEDEX_LIVE_API_KEY` and `FEDEX_LIVE_SECRET_KEY`, next to `PLASMAGUARD_PRO_PRICE=6000`. They drive both negotiated rating and the delivery address check. The secret was pasted into a chat session, so rotate it in the FedEx portal and replace it in the dev and production `.env`. See `fedex-shipping-setup.md`
- Turnstile: **real widget since 2026-09-22**, replacing the always-pass test keys the owner chose on 2026-09-14 for the Stripe application. One widget covers q3.life, www.q3.life and q3.onlinebros.com. Its site key is public and lives in `sites/q3.life/site.json` (`support.turnstile_site_key`), so `./deploy.sh dev|live` no longer needs a `--set` override. The secret is per-environment in the app `.env`, never in the repo: dev has it with `TURNSTILE_ALLOWED_HOSTNAMES=q3.onlinebros.com`; production has it with `TURNSTILE_ALLOWED_HOSTNAMES=q3.life,www.q3.life` (done 2026-09-22, `.env` backed up on the droplet as `shared/.env.bak-2026-09-22-turnstile`). Both sides verified by posting a dummy token and getting a 422 rather than a 201. The verifier fails closed, so the site key and the app secret must always move together — a real site key against an app still holding a test secret is fine (test secrets pass anything), but a real secret with no key configured is a 503 on every submission. The secret was pasted into a chat session, so rotate it in the Cloudflare dashboard when convenient (same caveat as the FedEx key above)
- **Spaces keys: supplied 2026-09-21 and in use.** The training library's 24 GB
  is uploading to `storage-q3-01` from dev. Two things to know: the key is
  **account-wide** (it also reaches `solarxfactor-storage-01`) and should be
  replaced with one scoped to this bucket; and `league/flysystem-aws-s3-v3` was
  never installed, so the `spaces` disk configured here since the screen-recorder
  work could never have worked. It is installed now — the droplet needs
  `composer install --no-dev` after this ships. See `training-library.md` § 6.
- Setting any bucket flips `config/screen-recordings.php` to the Space for new
  recordings. Pin `RECORDINGS_DISK=public` unless that is intended.
- Vimeo credentials are **no longer wanted**. Training videos are self-hosted
  behind the membership check: a Vimeo URL is playable by anyone it is passed to
  and outlives the subscription that paid for it.
- The CI workflow `.github/workflows/ci.yml` is uncommitted: the GitHub token in `origin` lacks the `workflow` scope

## Landing site deploy

The site is static files. To publish, copy the built site into the docroot:

```bash
rsync -av --delete <site-dir>/ liontraining-prod:/var/www/q3.life/public/
```

Partner websites (`q3.life/CODE`) need the hand-added `location ~ "^/[A-Za-z0-9]{8}/?$"` block in `/etc/nginx/sites-available/q3.life` (added 2026-09-18, backup `.bak-2026-09-18-ref`). See `sites/q3.life/README.md`.

`/membership` and `/membership/` 301 to `/training-program`, added 2026-09-22 in the same file (backup `.bak-2026-09-22-training`). The Training Program page was `/membership` until the owner ruled the paid training is not a membership.

No reload is needed for content changes. After editing nginx config, run `sudo nginx -t && sudo systemctl reload nginx`.

### Published live on 2026-09-22

The product-first site went to `q3.life` on 2026-09-22 (previous docroot backed
up on the droplet as `/var/www/q3.life/public-backup-2026-09-22.tar.gz`). It is
the strict build — no preview banner, `robots: index, follow`.

It first shipped with a `--set` override of Cloudflare's always-pass Turnstile
test key, because `support.turnstile_site_key` was TODO and `./deploy.sh live`
refuses while anything is. The real widget landed in `site.json` later the same
day, so the override is gone and publishing is just:

```bash
cd sites/q3.life
./deploy.sh live
```

Republished on 2026-09-22 with the real Turnstile key, and the droplet's
`.env` given the matching secret the same day — see the Turnstile bullet above.

**The live contact form therefore has no bot protection.** Fixing it is one
change on both sides at once: the widget's site key into `site.json`
(`support.turnstile_site_key`) and its secret into the app's `.env`
(`TURNSTILE_SECRET_KEY`, plus `TURNSTILE_ALLOWED_HOSTNAMES`). A real site key
with the test secret still in the app **breaks the form**, so never ship one
without the other.

### The site is the default page on the dev host (2026-09-22)

`sites/` holds the public sites, all built by `sites/build.py` from their own
directory (`python3 ../build.py`, or `--site <name>` from anywhere). Fonts,
`site.css`, `product.css` and `ref.js` live once in `sites/_shared/assets/` and
are copied in first, with each site's `src/assets/` layered over the top.

There is one site today: `sites/q3.life`, product-first. On dev it is served at
the **root** of q3.onlinebros.com, built with `--base /` into `dev-www/site/`.
nginx tries the static file, then `$uri.html`, then falls through to the named
location `@laravel`, so `/login`, `/join/...`, `/member/...` and `/admin/...` are
untouched and both `/assets/` trees resolve from their own roots. Three details
that are easy to lose:

- `location = /` is separate, because `try_files` cannot turn `/` into
  `index.html` — `/` is a directory, not a file — and without it the front page
  is the only page that falls through to Laravel.
- `location = /register` is separate, because `register` is eight characters and
  the partner-code regex would otherwise swallow it. An exact-match location
  outranks a regex one in nginx.
- `/site` and `/site/` 301 to `/`, where the site used to live.
- `/membership` and `/membership/` 301 to `/training-program`. The page was
  `/membership` until 2026-09-22, when the owner ruled that the paid training is
  a Training Program and not a membership. The old URL may already be out there,
  Stripe included, so the redirect is not optional. **The q3.life droplet still
  needs the same two lines** in `/etc/nginx/sites-available/q3.life`.

All of it was added by hand on 2026-09-21/22, so re-add it if
`control-action.sh` regenerates `/etc/nginx/sites-available/q3.onlinebros.com`.

`sites/air.q3.life` was created on 2026-09-21 and **retired on 2026-09-22** at
the owner's request — one site, one back office. The builder still takes a site
directory, so a second front door is a directory and a `site.json`.

Production serves `sites/q3.life` at `q3.life` from the droplet, where the
`/CODE` rule and the `/membership` redirect are their own hand-added blocks in
`/etc/nginx/sites-available/q3.life`. The product-first build is live there as
of 2026-09-22 — see "Published live on 2026-09-22" above for the exact command
and the Turnstile caveat.
