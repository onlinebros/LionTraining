# Marketing Inputs (DRAFT — pending Sales/Marketing sign-off)

**Status:** Proposed candidates. Each item shows one **Recommended** option plus 1–2 **Alternates**. Nothing here is final until Sales/Marketing initials the recommended line (or substitutes their own). Dev work that hard-codes this copy should pull from a single source-of-truth config (`config/brand.php` or equivalent) so a single edit propagates.

**Brand premise (one paragraph, anchor for everything below):**
LionTraining teaches heart-centered visionary leadership. The lion is the symbol — courage, sovereignty, presence, voice. The promise is *internal transformation that becomes external leadership*. The funnel is: Free E-Book → free account → marketed-to-upgrade → paid training → opt-in affiliate. Tone: grown-up, warm, confident, not woo-woo, not bro-y, not MLM-coded.

---

## 1. One-Sentence Transformation Promise

This is the single line that anchors the landing-page hero, the email signature, the E-Book cover blurb, and the elevator pitch. Every other piece of copy is a paraphrase of this sentence.

- **Recommended:** *Become the visionary the world keeps asking you to be — by leading from your heart, not your head.*
- Alternate A (shorter, punchier): *Lead with your heart. Build a life that roars.*
- Alternate B (more aspirational): *Stop performing your life. Start leading it — heart-first.*

**Why this one:** It names the audience's quiet self-perception ("the world keeps asking you to be"), frames the method ("from your heart"), and the outcome ("visionary") in one breath. It is also short enough to fit a hero block on mobile without wrapping awkwardly.

**Where dev uses it:** Landing hero H1, E-Book back-cover blurb, member welcome screen, transactional-email tagline, OG/social share card.

---

## 2. Final E-Book Title

The E-Book is the free lead magnet that earns the email + account. The title has to feel like an entry point to a real journey, not a PDF tip-sheet. It should promise transformation in a way the rest of the funnel keeps delivering on.

- **Recommended:** *The Heart of the Lion: 7 Shifts From Reactive Life to Visionary Leader*
- Alternate A: *Roar From the Heart: The First Steps of the Visionary*
- Alternate B: *Heart-Led: The Lion's Path to Becoming a Visionary*

**Why this one:** "Heart of the Lion" carries the full brand metaphor in five words. "7 Shifts" makes it concrete and scan-able (readers like a countable promise). "Reactive Life → Visionary Leader" gives both the before-state and the after-state in one line.

**Where dev uses it:** Lead-magnet landing page title tag, opt-in form headline, post-signup download page, E-Book cover art, transactional-email subject ("Your copy of *The Heart of the Lion* is inside").

---

## 3. Status-Ladder Tier Names

Replaces the bland `free_member` / `paid_member` labels currently in `RoleSeeder.php` with names that signal progress and belonging. The ladder advisor suggested (Explorer ↔ Lion) gets extended to a four-rung version that maps cleanly onto the funnel states the codebase already implies (free, paid, affiliate-active, top performer).

| Rung | Tier Name        | Internal slug    | Funnel state                                                | Display moments                            |
| ---- | ---------------- | ---------------- | ----------------------------------------------------------- | ------------------------------------------ |
| 1    | **Cub**          | `cub`            | Free account, has E-Book, no paid training yet              | Dashboard badge, header avatar ring        |
| 2    | **Explorer**     | `explorer`       | Active paid subscription                                    | Dashboard badge, certificate footer        |
| 3    | **Lion**         | `lion`           | Paid + opted into affiliate terms, has at least 1 referral  | Public-profile badge, share-link page      |
| 4    | **Pride Leader** | `pride_leader`   | Top-performing affiliates (criteria set with commissions)   | Leaderboard, member-of-the-month, comms    |

- **Recommended ladder:** Cub → Explorer → Lion → Pride Leader (as above)
- Alternate A (3-rung, simpler): Explorer (free) → Lion (paid) → Pride (affiliate)
- Alternate B (more poetic): Seeker → Explorer → Lion → Pride Keeper

**Why this one:** Four rungs gives the system room to celebrate the affiliate opt-in as a distinct moment (it is the moment the business model fully activates). "Cub" lands gently for someone who just gave an email — they are not failing at anything, they are at the start of a journey. "Pride Leader" gives top affiliates a title to aspire to without being a sales rank.

**Where dev uses it:** `RoleSeeder.php` `display_name` field, member dashboard header pill, all admin user-list columns, automated emails ("Welcome, Explorer"), affiliate-page CTA ("Become a Lion").

**Mapping to existing roles:** keep `free_member` → display "Cub", `paid_member` → display "Explorer". Add two new roles `lion` and `pride_leader` (or a `tier` field on the user record orthogonal to the existing admin/support roles — admins should decide which model). Flag for dev: this is a **display-layer rename plus two new tiers**, not a permission-model rewrite.

---

## 4. "Is This an MLM?" Objection Reframe

This objection will hit on the upgrade page and the affiliate-opt-in page. The reframe has to be confident, short, and answer the real fear underneath ("am I going to have to pester my friends and manage a downline?"). Don't dodge the word — name it.

- **Recommended (long form, FAQ + affiliate page):**

  > **Is this an MLM?**
  > No. We are a content company. The training stands on its own — you can buy it, finish it, and never share a link, and you got full value. The affiliate program is opt-in and one level deep: if someone joins through your link, we pay you for that introduction, full stop. There is no downline to manage, no team to recruit, no monthly volume quota, no required purchases beyond your own subscription. We pay you for sending people to something they would have benefited from anyway.

- **Recommended (short form, upgrade-page sidebar / tooltip):**

  > Not an MLM. One-level affiliate, fully opt-in, no recruiting required, no downline. You get paid for direct referrals — that's the whole structure.

- Alternate (one-liner, for cards and badges):

  > *Content company first. Affiliate program second. Opt-in always.*

**Why this one:** It uses the audience's own vocabulary ("downline," "recruit," "volume quota") to prove we know what they are afraid of, then dismantles each piece. The "you can buy it, finish it, and never share a link" sentence is load-bearing — it makes the product feel like a product, not a recruitment funnel.

**Where dev uses it:** FAQ page entry, upgrade-page sidebar, affiliate-opt-in page hero, objection-handling email in the upgrade nurture sequence.

**Compliance note for dev:** any earnings claims (see Section 5) must sit next to a typical-results disclaimer. Have legal review the final wording before launch.

---

## 5. "Let Your Subscription Pay for Itself" Earning Hook

This is the headline of the affiliate-opt-in page and the framing for the in-app prompt that shows up after a member finishes the core training. The hook has to make the math feel obvious and the action feel low-pressure.

- **Recommended (headline + subhead pair):**

  > **Let your subscription pay for itself.**
  > Share what changed you. When two people you refer subscribe, your monthly cost is covered — and so is the next month, as long as they stay.

- **Recommended (inline prompt, post-training):**

  > You just finished what most people only talk about. Someone in your life is asking the same questions you were 90 days ago. Send them the E-Book — if they upgrade, your next month is on them.

- Alternate (math-forward, for landing tiles):

  > *One referral covers half your month. Two and you're net positive. Three and we owe you money.*

**Why this one:** "Pay for itself" is the lowest-pressure earning claim possible — it reframes the affiliate program as cost recovery, not income generation, which is exactly the tone that defuses the MLM fear from Section 4. The post-training prompt is timed to the member's peak conviction moment ("You just finished what most people only talk about"), which is when natural referrals actually happen.

**Where dev uses it:** Affiliate-opt-in page H1, post-completion modal in the training player, footer of monthly receipt email ("Did you know your subscription can pay for itself?"), share-link page hero.

**Compliance note for dev:** "Two referrals covers your month" is a math statement, not an earnings claim, but only if the price math actually works out. Confirm with commissions team before locking the number. If the commission % shifts during pricing finalization, update this hook to match — don't ship two different numbers.

---

## Approval Checklist (for Sales/Marketing reviewer)

Sign off here. Dev sprint can start the moment these five lines are initialed.

- [ ] **Promise:** ____________________________________________________
- [ ] **E-Book title:** _________________________________________________
- [ ] **Tier ladder:** __________________________________________________
- [ ] **MLM reframe (short form):** ____________________________________
- [ ] **Earning hook:** ________________________________________________

Reviewer: ______________________  Date: ______________

---

## Follow-up artifacts (NOT in scope for this task, listed so they don't get lost)

These were implied by the advisor session but are separate deliverables — flag them as their own tasks:

- Full funnel copy deck (landing → opt-in → upgrade → affiliate-terms → share page)
- Visual brand-system brief for UI team (lion iconography, gold/heart color story, "high-tech and cool looking" treatment of training pages)
- Affiliate Terms of Service draft (legal — required before "Sell or promote Access" step in the flow)
- Pricing + commission math (required to lock the "pay for itself" number)
- Typical-results / earnings disclaimer copy (legal — required next to any earning hook)
