# Active Context

## Current focus
Pre-launch phase — enrolling reps and placing them in the structure. Foundation is built and tested; billing (card-on-file) is the next blocking chunk.

## Project rename
Project is **Quantum Life** (was LionTraining), 2026-09-02. Display name lives in the `site_settings` table (`site_name`), not in code. Note: the lion metaphor throughout `marketing-inputs.md` — "Heart of the Lion", the Cub → Explorer → Lion → Pride Leader ladder — is now off-brand and needs a copy pass.

## Ratified decisions
1. **Placement model: unilevel.** Every partner sits directly beneath their sponsor; no spillover. Chosen 2026-09-02 over the binary recommendation. Consequence accepted: early position confers no advantage relative to others, so the pre-launch campaign cannot honestly sell position. `users.placement_*` columns are kept separate from `sponsor_id`/`enrollment_path` so a later switch to binary or matrix is a config change, not a migration.
2. **Pre-launch billing: card on file, billed at launch.** Scheduling constraint: Stripe setup-intent capture and the webhook ledger must ship *before* the campaign opens, not alongside it.

## Training library (2026-09-21)
The *Releasing The-LION* material is off Kartra and built on dev: 21 modules,
165 lessons, 124 self-hosted videos (24 GB), 89 worksheets. Self-hosted, not
Vimeo — every byte goes through a gated route that re-checks the membership on
each request. Ships **hidden**: admin-only until released from Admin → Settings.
Releases one module a month from the start of the paid membership.

Not on production yet — blocked on a DigitalOcean Spaces access key. Full
procedure and the things that bit us in [training-library.md](training-library.md).

## It is not a "Membership" (2026-09-22)
Owner's naming rule, to be held everywhere: the paid training is an optional
**Training Program** that any member can add for $49.99 a month. A member
account is free, so calling the fee a membership fee describes the wrong thing.
The course itself is called **Rediscover your Heart** (it replaces the working
title *Vision of the Heart Training*).

Done: every page of q3.life, the `site.json` block (`membership` → `program`,
name **Q3 Training Program**), the member Billing and Training screens, the
billing flash messages, the two admin labels, `STRIPE_PRODUCT_NAME` and the
Stripe **test-mode** product. The **home page no longer mentions the training at
all** — it sells the system and the free Partner Program, and the replicated
partner sites are that same page. The Training Program page and its nav and
footer links stay, because the price and terms must stay published in advance.

The page moved from `/membership` to **`/training-program`**, with a hand-added
nginx 301 from the old URL on dev. The **q3.life droplet still needs that
redirect** — see [deployment.md](deployment.md).

Left deliberately: internal identifiers and config keys
(`hasActiveMembership()`, `requiresMembership()`, `opportunities.*.membership`,
`MEMBERSHIP_ENROLLMENT_OPEN`), and the **live** Stripe product name, which still
reads *Q3 Partner Membership* on real invoices and has to be renamed in the live
dashboard.

## Product-first, and nobody is asked for a card (2026-09-22)
The company site leads with the **PlasmaGuard PRO In-Duct System**. A partner
account is **free**, and the $49.99 Training Program is optional and
**not on sale yet**.

`MEMBERSHIP_ENROLLMENT_OPEN=false`, so no card is requested anywhere in the
application — not at sign-up, not on any gate, not from a bookmarked URL. Card
capture redirects to a new **Training Program** section (`/member/training-program`)
that says when it opens and takes an expression of interest
(`users.training_interest_at`). Opening it is that one variable, plus rewriting
the site's "Opening soon" copy in the same change.

There is now **one** public site. `sites/air.q3.life` was retired; on dev
q3.life is served at the **root of q3.onlinebros.com**, with anything nginx
cannot find on disk falling through to Laravel.

The owner's reason for product-first was Stripe. Recorded in `opportunities.md`
§6: the site is not the lever it looks like, because PlasmaGuard's charges land
on *their* connected account and ours still shows subscriptions plus Connect
payouts down a sponsor tree. The Partner Program stays in the nav and the
Earnings Disclaimer in the footer for that reason — **do not strip them in a
later copy pass.** The comp plan, not the home page, decides the MLM question.

## A shared site can take an order (2026-09-22)

The owner's point: PlasmaGuard's price is fixed and we cannot change it, so
"request a quote" wastes the visit. A partner's replicated site now offers
**Order the system**, which goes to that partner's storefront
(`{app}/p/CODE/plasmaguard/pro-in-duct`) — address, PlasmaGuard's tax, and a
card charged on their Stripe account. The wiring is `data-buy` in
`sites/_shared/assets/ref.js`, with the vendor and product keys declared in
`site.json` (`storefront`) and put on `<body>` by the layout.

`$6,000 per system` is now printed publicly on `/system`. It has to move
together with `PLASMAGUARD_PRO_PRICE` in the app, or the site advertises one
price and the checkout charges another.

A visitor with **no** code still gets the contact form: there is no partner to
attribute the sale or the commission to. If the company should be able to sell
without a partner, that needs a house code and a decision about who is credited.

## Opportunity Associations (2026-09-21)
A member carries a **business line** — `q3-training` or `plasmaguard` — which
decides whether a card is ever asked for and which sections of the back office
exist for them. It is captured from `?o=` on the Join link at sign-up. Full
design, and what is deliberately left undone, in
[opportunities.md](opportunities.md).

PlasmaGuard's product claims on the site are **quoted verbatim** from
plasmaguard.com (owner's instruction). Written permission for their names, copy
and photography is still open — `vendor-referral-framework.md` item 9.

## Released to app.q3.life (2026-09-23)
Commit `260542e` is live on the droplet — the first app deploy since
`73067a8` (2026-09-21). It carries everything that had accumulated on dev,
because the Product Partner portal stands on Opportunity Associations and none
of that was committed either. Nine migrations, all additive; the two index
migrations build CONCURRENTLY, so the 1.3M-row users table was never
write-locked. Verified after: login, admin and the portal all answer, 64
subscriptions intact, error log clean.

Two things stayed behind on dev on purpose and are still uncommitted:
`sites/air.q3.life` (staged as added but deleted from disk) and
`.github/workflows/ci.yml` (the deploy token cannot push workflow files).

## Product Partner portal (2026-09-22)
The vendor's own people, with a login to a section of their own. A
**Product Partner** is a non-admin role linked to one or more vendors and
products; they see their line's sales in full, their pipeline as counts only,
and one shared settlement account with Quantum 3. Built on dev, nothing
deployed. Design and the rules behind it in
[vendor-referral-framework.md](vendor-referral-framework.md) §14.

Two things on it are deliberate and should not be "fixed" without a decision:
open prospects are never listed (they are our partners' un-closed customers),
and a vendor can record a payment but never confirm one.

## Still open
- **Subscription lapse timing** (immediate vs. period-end vs. split access/earning clocks). Recommended: split clocks with grace. Gates the `subscriptions` state machine and commission accrual eligibility.
- Compensation plan rules (the spec's D2) — still a business decision, and the largest schedule risk.

Marketing inputs gate copy, not schema.

## Pre-sprint marketing inputs (separate gate)
Sales/Marketing must sign off on five anchor copy elements before dev work starts on the Free E-Book → Free Account → Upgrade → Affiliate funnel.

## Canonical reference
See [marketing-inputs.md](marketing-inputs.md) — DRAFT candidates for the transformation promise, E-Book title, status-ladder tier names, MLM-objection reframe, and earning hook. **Status: pending stakeholder approval.** Dev should pull final copy from a single source-of-truth config (`config/brand.php` or equivalent) once the approval checklist is initialed.

## Funnel to be built (advisor session)
Free E-Book Landing Page → Create Free Account → Access E-Book → Marketed to Upgrade → Purchase → Access Content → Agree to Affiliate Terms → Unlock Sharing Link → repeat.

## Open follow-up tasks (separate deliverables, not started)
- Full funnel copy deck (landing → opt-in → upgrade → affiliate-terms → share page)
- Visual brand-system brief for UI team (training-page redesign — "high-tech, cool looking" vs current basic-text segmentation)
- Affiliate Terms of Service draft (legal)
- Pricing + commission math (locks the "pay for itself" number)
- Typical-results / earnings disclaimer (legal)

## Reference codebase
`/srv/workspaces/agent6/SolarXFactor` is the built-out fork of this project. Its
`docs/quantum-life-solutions/` holds a 13-document build specification written
for this rebuild — read `11-conventions.md` and `00-decisions.md` first, and
`09-prelaunch.md` for the launch-day runbook. Reference implementations worth
reading rather than reinventing: `app/Support/Prelaunch.php`,
`app/Http/Middleware/PrelaunchGuard.php`, `BinaryAutoPlacementService.php`.

## Product shape (2026-09-03)
Quantum Life sells a **video course** plus further products to be integrated. Two
distinct money paths, and only the first is built:
1. **Recurring partner membership** — the subscription (C1–C3). Built.
2. **One-time product sales** (the course and what follows) — not built. This is
   the spec's `transaction`: the qualifying revenue event the compensation
   engine pays commission on. Needs a product catalogue, checkout, and
   entitlement-to-content before the comp engine (D) has anything to pay against.

The existing training module (categories, lessons, video assets) is the delivery
mechanism for the course; what is missing is purchase and entitlement, not
playback.

## Recent changes
- 2026-09-21: **Opportunity Associations + air.q3.life.** A member now carries a business line (`backend/config/opportunities.php`): `q3-training` (the membership, card at sign-up) or `plasmaguard` (B2B, **no card, no fee, no training library**). `users.primary_opportunity` + `user_opportunities`; nothing backfilled, null reads as the default. `RequireActiveSubscription` passes anyone whose lines do not require the membership; `opportunity:<feature>` gates the routes and `User::canSee()` gates the sidebar, so the menu can never point at a 404. Captured at `/join/{code}?o=…&s=…` by `OpportunityTracker`. The card-free side's only door to the paying side is `BillingService::startSubscription()`, which adds the default line. New marketing site `sites/air.q3.life` (PlasmaGuard, claims quoted verbatim), built by the now-shared `sites/build.py` with `sites/_shared/assets/`; q3.life's build output is byte-identical apart from the two new `<body>` attributes. Dev: https://q3.onlinebros.com/air/. **Not on production** — placeholder domain, and PlasmaGuard's copy/photo permission is still open. See [opportunities.md](opportunities.md).
- 2026-09-18: **Recording Studio, Presentations and Video Funnels, admin-only.** Ported from SolarXFactor using the handoff at `/srv/workspaces/agent6/handoff/video-presentations/`. Admin sidebar: *Recording Studio* (record, upload, combine, library) and *Presentations* (schedule, repeating schedules, prospects, funnels, calls to action, Your Rooms). The member-side pages are closed to non-admins by `PRESENTATIONS_OPEN_TO_MEMBERS=false`; guest pages `/watch/{slug}/{code}` and `/flow/{slug}/{code}` are public. Deviations from the source are listed at the top of `backend/docs/presentations.md`. **Dev runtime gap:** no queue worker or scheduler runs for this workspace, so trim/combine stay queued and scheduled showings must be started by hand until `queue:work` and `schedule:run` are set up. Recordings go to the local public disk until a Spaces bucket is configured.
- 2026-09-14: **PlasmaGuard own purchases + first-100 promotion.** Partners can buy for themselves from Product Sales → Buy for yourself. A partner's own purchase counts for them but pays their **sponsor**, whichever link was used: detected automatically on back-office orders and on an email or phone match, and held for admin review on an address-only match. The raised commission now applies the configured basis (10% of our revenue share rather than of the order total). The promotion tracker for the first 100 PRO systems has a partner leaderboard and an admin order list. See `vendor-referral-framework.md` §11–12. Deployed to app.q3.life on 2026-09-15 (commit `3b0e757`, migration `2026_09_14_220000`). All changed pages were verified rendering there as the real partner and admin.
- 2026-09-03: **Billing C1–C3.** Stripe subscriptions with card-on-file: `config/stripe.php`, `BillingService`, `StripeClientFactory`, `StripeWebhookProcessor`, `ProcessPaymentWebhookJob`, `RequireActiveSubscription`, member billing screens, admin oversight (`/admin/billing/subscriptions`, `/admin/billing/webhooks`). Commands: `billing:bootstrap-product`, `billing:preflight`, `billing:apply-prelaunch-end`. Two webhook endpoints with separate secrets. **The billing routes sit outside the subscription gate on purpose** — gating them is a redirect loop, and there is a test asserting it. `past_due` grace is `STRIPE_GRACE_DAYS`, a policy value, not an accident of which statuses the gate lists.
- 2026-09-03: Pre-launch signups park on a placeholder trial (`prelaunch_placeholder_days`) because the launch date is unknown at signup; `billing:apply-prelaunch-end` moves them all onto the real schedule on launch day. Run it BEFORE opening closed sections. It is one of only two irreversible launch steps.
- 2026-09-03: Cleared 21 security advisories (guzzle, psr7, commonmark) surfaced when the Stripe SDK was installed.
- 2026-09-02: **Postgres 18.** Moved off MySQL to the PG 18 cluster on port 5433 (`quantumlife_db`); `ltree` and `pgcrypto` enabled. The test suite now runs against a real Postgres database (`quantumlife_test`) instead of sqlite `:memory:` — sqlite cannot express `ltree`, so it could not have covered the genealogy. That switch immediately surfaced a live bug: the Stripe webhook's duplicate detection only recognised MySQL/sqlite unique violations, so on Postgres a redelivery would have 500'd and Stripe retries 5xx in a loop. Now uses `insertOrIgnore` (`ON CONFLICT DO NOTHING`), which is also the only form safe inside a transaction on Postgres.
- 2026-09-02: **Pre-launch guard (A3).** `config/prelaunch.php` + `App\Support\Prelaunch` + `PrelaunchGuard` middleware, applied to the whole `web` group so routes added to a closed section are closed the moment they exist. Sidebar and middleware read the same source, so a menu item can never point at a closed URL. `php artisan prelaunch:preview {email} --enable|--disable`.
- 2026-09-02: **Genealogy (E1/E2).** `users.sponsor_id`/`enrollment_path` and `placement_parent_id`/`placement_path` as Postgres `ltree` with GIST indexes. `GenealogyService` gives downline, upline and per-level team counts as single indexed queries. `EnrollmentService` is the *only* place an enrollment is written — it writes the genealogy and the legacy `sponsorships` row in one transaction so they cannot drift. Registration now places synchronously; `network:place-queued` is the safety net.
- 2026-09-02: Sponsorship is now recorded **active** at registration, not pending. Pre-launch requires partners in the tree immediately. Existing "pending sponsorships" counters on the admin and member dashboards will therefore read 0.
- 2026-06-29: Added `marketing-inputs.md` with recommended copy and approval checklist.

## Known debt
- `sponsorships` is legacy. ~15 screens still read it; the genealogy on `users` is authoritative. Retire the table once those screens move onto the `recruits`/`placementChildren` relations, then delete the dual-write in `EnrollmentService`.
- `Sponsorship.status` ('pending'/'active') is contrary to the pre-launch design and should go with it.
