# Decisions

Record architecture and implementation decisions with date, context, decision, and consequences.

---

## OPEN — Pending Leadership Sign-off (gates schema work)

The two items below are **binding business/legal/comp-plan decisions**, not technical ones. They must be ratified by leadership (product + finance + legal where noted) before any `subscriptions`, `placements`, or comp-plan-touching migrations are written. Each item lists the options, trade-offs, schema implications, and a **Recommended default**. Nothing is final until the recommended line is initialed (or replaced) by an authorized signer and the entry is moved into the "RATIFIED" section below.

Cross-reference: same approval-gate pattern as [marketing-inputs.md](marketing-inputs.md).

---

### Decision 1 — Subscription Lapse Timing

**Question:** When a paying member's payment fails, does **earning eligibility** (ability to accrue affiliate commissions on downline activity) suspend **immediately on the failed charge**, or **at the end of the paid period**?

This is distinct from **access eligibility** (ability to view training content). Earning and access can — and in most healthy SaaS comp plans, *do* — lapse on different clocks. Locking earning timing is the gating decision because it shapes the `subscriptions` state machine, the commission-ledger eligibility join, and the clawback policy.

**Why it gates schema:**
- Drives the `subscriptions` table state enum (`active`, `past_due`, `grace`, `suspended`, `canceled`) and which states qualify for `commission_ledger` accrual.
- Determines whether a `paid_through_at` column or an `earning_eligible_through_at` column (or both) is the source of truth for nightly accrual jobs.
- Determines whether `commission_clawbacks` needs a "rolled-back-mid-grace" reason code.
- Determines refund/chargeback handling: a member who fails on day 2 of a 30-day cycle has a very different balance owed than one who fails on day 29.

**Options:**

- **Option A — Immediate suspension on payment failure**
  - Pros: Tightest financial control; no commissions accrue on accounts that will never collect; simplest "are they paid right now?" check (one boolean).
  - Cons: Punishes transient declines (expired card, bank fraud-hold, travel block) — these are ~60–80% of involuntary churn in card-based subs. Hostile UX. Drives manual support tickets. Risk that a top-earning affiliate loses a week of override income because of a 3-hour bank hold.
  - Schema shape: `subscriptions.status` flips to `suspended` on webhook; `commission_ledger` join filters on `status = 'active'`.

- **Option B — Period-end suspension (industry standard for SaaS / Netflix-style)**
  - Pros: Honors the paid period the member already bought. Standard consumer expectation. Allows dunning (retry attempts) without yanking benefits mid-cycle.
  - Cons: An account that lapses on day 30 has accrued 29 days of overrides we may never collect from. Needs clawback logic if downstream payouts have already been released.
  - Schema shape: `subscriptions.paid_through_at`; accrual job qualifies on `paid_through_at >= accrual_date`; lapse only on `paid_through_at < today AND grace_ends_at < today`.

- **Option C — Period-end access + dunning grace + earning-only suspension on first failed retry (Recommended)**
  - **Access** continues to `paid_through_at` (Option B behavior — member keeps using what they paid for).
  - **Earning eligibility** suspends on the **first failed retry** (typically 3–5 days after initial decline), even if access continues to period end.
  - **Restoration** is retroactive on the same cycle if the card recovers before `paid_through_at`: the gap days re-qualify and accrued-but-held commissions release.
  - Pros: Separates the two clocks correctly. Doesn't strand a member who fixes their card the next morning. Limits earning exposure to ~3–5 days of grace rather than a full period. Matches what mature affiliate platforms (ClickFunnels, Kajabi affiliate, Kartra) do.
  - Cons: Two columns of truth (`paid_through_at`, `earning_eligible_through_at`) and a dunning state machine. More to test.
  - Schema shape: `subscriptions` table with `status`, `paid_through_at`, `earning_eligible_through_at`, `grace_ends_at`, `last_payment_attempt_at`, `last_payment_failure_at`. Commission accrual joins on `earning_eligible_through_at >= accrual_date`. Content gate joins on `paid_through_at >= today`.

**Recommended:** **Option C** — period-end access, earning suspends on first failed retry, retroactive restore within the same cycle. Initial here when ratified: `__________ / __________`.

**Decision required from:** Product owner + Finance (for clawback exposure) + Legal (for affiliate-terms disclosure language).

**Open sub-questions if Option C is chosen:**
- How many retry attempts before earning suspends? (Recommended: 1st failure → 3-day grace → 2nd failure suspends earning, access continues to period end.)
- If commissions accrue and then the card recovers, are payouts released on the original schedule or rolled to the next cycle? (Recommended: original schedule, no penalty.)
- If the card never recovers and access lapses, are grace-period accruals **forfeited** or **paid out anyway**? (Recommended: forfeited — this is the whole point of the grace gate. Disclosed in affiliate ToS.)

---

### Decision 2 — Payfield Placement Model

**Question:** Is the **placement tree** (the structure that determines who is *under whom* for commission-pay purposes) the **same** as the **sponsor/enrollment tree** (who personally referred whom), or is it a **separate structure** with its own placement rules (spillover, forced matrix, binary, etc.)?

**Why it gates schema:**
- Determines whether `users.sponsor_id` (already implied by the existing `sponsorships` table) is sufficient, or whether a parallel `placements` table is required.
- Determines the comp-plan calc shape: a unified tree is a recursive CTE on one parent column; a separate placement tree is a recursive CTE on a different parent column with its own depth, leg, and spillover semantics.
- Determines what affiliates see in their dashboard ("my downline" — sponsorship view? placement view? both?).
- Determines whether re-placement (moving a user under a different upline) is a supported operation, and if so, what audit trail it needs.

**Options:**

- **Option A — Unified tree (placement = sponsor/enrollment tree) (Recommended for v1)**
  - Pros: Simplest possible schema — `users.sponsor_id` is the only parent pointer. One recursive CTE. Easy for affiliates to understand ("the person who signed you up is your upline, period"). No re-placement edge cases. Matches the current `sponsorships` table as-built. Fastest path to a shippable v1 comp plan.
  - Cons: No spillover. No binary or forced-matrix structures. No way to reward a top recruiter by placing new signups under their weakest leg. Comp-plan creativity is bounded to "uni-level" (pay N levels deep at decreasing % rates).
  - Schema shape: Reuse existing `sponsorships`. Commission accrual is a recursive CTE on `sponsor_id` with a `max_depth` cap from `commission_plans`.
  - Best fit when: comp plan is uni-level percentage overrides (which is the simplest, most defensible structure and the one most aligned with the "heart-centered, not MLM-coded" brand positioning in `marketing-inputs.md`).

- **Option B — Separate placement tree (binary / matrix / spillover)**
  - Pros: Enables binary, forced-matrix, spillover-to-weak-leg, and other "MLM-power-tool" structures. Lets a sponsor's recruiting strength help their team via spillover.
  - Cons: Two trees to maintain. Two recursive CTEs. Re-placement audit log required. Significantly more confusing to explain to a member ("your sponsor is Alice but your *placement* is under Bob, because Alice's left leg was full…"). Reads as classic MLM, which directly conflicts with the brand-tone instruction in `marketing-inputs.md` ("not MLM-coded"). Doubles QA surface area. Harder to audit for regulators.
  - Schema shape: New `placements` table with `(user_id PK/unique, placement_parent_id, leg ENUM('left','right')|nullable, placed_at, placed_by, placement_reason)`. Plus a `placement_history` audit table. Commission accrual joins on `placements` instead of `sponsorships`.

**Recommended:** **Option A — unified tree for v1.** Defer a separate placement tree to v2 only if Sales/Marketing makes a documented case that uni-level overrides will not hit the earning numbers the comp plan promises. The brand positioning ("not MLM-coded") is an active argument *against* binary/matrix structures — they are the structures consumers most associate with MLM. Initial here when ratified: `__________ / __________`.

**Decision required from:** Product owner + Comp-plan owner (whoever designs the % structure) + Legal (placement-tree mechanics affect what the affiliate ToS has to disclose).

**Open sub-questions if Option A is chosen:**
- What is the max pay depth? (Common: 5–10 levels; needs to be a `commission_plans` config, not a hard-coded constant.)
- Is the override % flat across all levels or tapered? (Tapered is more sustainable; flat is easier to market.)
- Does an inactive sponsor (lapsed earning eligibility per Decision 1) **roll up** their override to the next active upline, or does that level **compress out**? (This is itself a comp-plan design decision and should be ratified before the accrual job is written.)

**Open sub-questions if Option B is chosen instead:**
- Binary, forced 3×N matrix, or spillover-uni-level?
- Who controls placement on each new signup — the sponsor, an admin, or an automated rule?
- How is re-placement (moving an existing user to a new parent) authorized and audited?

---

## How to ratify

1. A signer reviews each decision above, writes their initials and the date on the "Recommended" line (or strikes it through and writes the chosen alternative).
2. The signed decision moves down into the **RATIFIED** section below with date and signer name.
3. Only then does schema work on `subscriptions`, `placements` (if Option B), the accrual job, or the dunning state machine begin.

---

## RATIFIED

_(empty — no decisions ratified yet)_
