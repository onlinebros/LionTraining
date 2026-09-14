# Active Context

## Current focus
Pre-launch phase — enrolling reps and placing them in the structure. Foundation is built and tested; billing (card-on-file) is the next blocking chunk.

## Project rename
Project is **Quantum Life** (was LionTraining), 2026-09-02. Display name lives in the `site_settings` table (`site_name`), not in code. Note: the lion metaphor throughout `marketing-inputs.md` — "Heart of the Lion", the Cub → Explorer → Lion → Pride Leader ladder — is now off-brand and needs a copy pass.

## Ratified decisions
1. **Placement model: unilevel.** Every partner sits directly beneath their sponsor; no spillover. Chosen 2026-09-02 over the binary recommendation. Consequence accepted: early position confers no advantage relative to others, so the pre-launch campaign cannot honestly sell position. `users.placement_*` columns are kept separate from `sponsor_id`/`enrollment_path` so a later switch to binary or matrix is a config change, not a migration.
2. **Pre-launch billing: card on file, billed at launch.** Scheduling constraint: Stripe setup-intent capture and the webhook ledger must ship *before* the campaign opens, not alongside it.

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
- 2026-09-14: **PlasmaGuard own purchases + first-100 promotion.** Partners can buy for themselves from Product Sales → Buy for yourself. A partner's own purchase counts for them but pays their **sponsor**, whichever link was used: detected automatically on back-office orders and on an email or phone match, and held for admin review on an address-only match. The raised commission now applies the configured basis (10% of our revenue share rather than of the order total). The promotion tracker for the first 100 PRO systems has a partner leaderboard and an admin order list. See `vendor-referral-framework.md` §11–12. Migration `2026_09_14_220000` has been applied on dev; production has not been deployed.
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
