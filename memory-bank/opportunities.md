# Opportunity Associations

One back office, several front doors.

An **opportunity** is a business line somebody can join us for. Two exist:

| Key | What it is | Membership |
|---|---|---|
| `q3-training` | The Q3 Training Program | $49.99/mo, **not on sale yet** |
| `plasmaguard` | Selling PlasmaGuard PRO systems (B2B) | **none — no card, no fee** |

`q3-training` is the **default** — the line a member with no recorded one falls
back to. Every account that existed before this shipped is on it. Nothing was
backfilled: a null column reads as the default, which is the behaviour those
accounts already had. **Do not flip the default without backfilling first**, or
every one of those accounts silently loses the training library at once.

There is **one public site**, q3.life, and it is product-first (owner,
2026-09-22). Its `opportunity.key` is `plasmaguard`, so joining from it is free.
On dev it is served at the **root** of q3.onlinebros.com, with everything nginx
cannot find on disk falling through to Laravel.

## Nobody is asked for a card (owner, 2026-09-22)

The training program is not being sold yet, so
`MEMBERSHIP_ENROLLMENT_OPEN=false` and **no card is requested anywhere in the
application** — not at sign-up, not on any gate, not from a bookmarked URL.
`Opportunity::enrollmentOpen()` is the switch, and closing it means:

- `requiresMembership()` is false on every line, so `RequireActiveSubscription`
  lets everyone through, including accounts that predate all of this.
- `member.billing.start` redirects to the Training Program section.
- `setup-intent` and `confirm` refuse on their own, because they are the calls
  that reach the card network and a page left open across the switch being
  thrown must not still work.
- The dashboard card and the sidebar item read "Opening soon".

**Opening it is one variable.** `MEMBERSHIP_ENROLLMENT_OPEN=true` turns the
section into a real offer, brings card capture back for anyone who chooses to
join, and re-arms the subscription gate. Nobody is charged retroactively: a
partner is only ever billed after choosing an enrollment option themselves.
The q3.life Training Program page has to be rewritten in the same change, or the
site and the app will say opposite things — `site.json` `_confirm` says so too.

`phpunit.xml` runs the suite with enrollment **open**, because that is the state
the gate and card capture exist for. `TrainingProgramClosedTest` closes it.

## The Training Program section

`/member/training-program`, outside the subscription gate for the same reason
Billing is: a page about buying access cannot sit behind having bought it. Three
states, one page — closed (announcement + "tell me when it opens"), open (hands
off to card capture), held (points at Billing).

"Held" asks `hasActiveMembership()`, **not** `hasOpportunity()`. Every account
older than the business lines sits on the membership line by default without
having paid for anything; asking the line would tell all of them they already
have the program and hide the section from exactly the people it is for. The
sidebar item and the dashboard card use the same test.

Interest is `users.training_interest_at` — a timestamp, not a flag, so "when did
they ask" stays answerable. It is deliberately **not** an opportunity
association: holding a line grants its features, wanting one does not.

---

## 1. Why this exists (owner, 2026-09-21)

The brief was a replicated marketing site focused on PlasmaGuard, where a
visitor coming in that way is tracked and **is not asked for a card to select the
$49.99 system**. Then, in the owner's words:

> We want to maintain one backoffice but just allow multiple paths in. We may
> have a different domain name for the front facing system that can also be
> replicated as well to split the 2 products. But Just want to have a way to
> categorize a member what they are interested in as well.
>
> There will be a B2B side of things where these people will only be interested
> in selling the PlasmaGuard products. So we need to be able to have a
> Opportunity Association that we can link users to and control what they see
> based on this.

So this is not a flag on the PlasmaGuard site. It is a first-class association
between a member and a business line, and it drives two things: **whether a card
is ever asked for**, and **what the back office shows them**.

## 2. Where it lives

| | |
|---|---|
| `backend/config/opportunities.php` | The registry. Names, the site each is sold from, whether the membership is required, and the feature list. |
| `App\Support\Opportunity` | A read-only view over one registry entry. Value object, not a model — opportunities are defined by us, not created by users. |
| `users.primary_opportunity` | The line they came in by. Denormalised because it is read on every gated request. Null = the default. |
| `users.entry_site` | The host of the marketing site they arrived through, recorded verbatim. |
| `user_opportunities` | Every line they hold, with how the association happened and who made it. |
| `App\Models\UserOpportunity` | That join row. |
| `App\Services\Opportunities\OpportunityTracker` | Carries the choice from a marketing site into the account. Same shape as `ConversionTracker` on purpose. |
| `App\Http\Middleware\EnsureOpportunityFeature` | `opportunity:<feature>` on a route. |

The registry is **config, not a table**. What a B2B partner should be shown is a
commercial decision that will be argued about, and that argument should end in an
edit to a config file, not a deploy of new middleware.

A key removed from the registry degrades to the default rather than throwing,
because these keys arrive from public query strings.

## 3. The flow

```
Visitor on q3.life  →  Join link carries ?o=plasmaguard&s=q3.life
        │              (built by sites/_shared/assets/ref.js from site.json)
        ▼
GET /join/{code}?o=…&s=…   OpportunityTracker::remember()  → session
        │                  the page says "No card needed"
        ▼
POST /join/{code}          OpportunityTracker::attribute()
        │                  users.primary_opportunity = plasmaguard
        │                  users.entry_site          = q3.life
        │                  user_opportunities        += (plasmaguard, signup)
        ▼
afterSignup()  →  member.dashboard, NOT member.billing.start
```

An unknown or missing `?o=` means the default, so every existing link keeps
working unchanged.

## 4. The two gates

**Card.** `RequireActiveSubscription` passes a member whose lines do not include
the membership. `User::requiresMembership()` is true if **any** line requires it,
so a PlasmaGuard partner who later buys the membership is an ordinary paying
member from that moment and nothing has to remember they once were not.

**Visibility.** `User::canSee($feature)` is the **union** of their lines'
features. Routes are gated with `opportunity:<feature>` and the sidebar asks the
same question, so the menu can never point at a 404. Admins pass everything.

Feature names are validated against `opportunities.features` and an unknown one
throws — a typo that silently opened a route to everybody is the failure this
prevents.

`opportunity:training` answers **404**, not 403, matching `EnsureTrainingVisible`.
For a partner on the PlasmaGuard side the training library is not a locked door
they should ask about; it is not part of what they joined. What they *can* have
is offered where it belongs — on the billing screen, as adding the membership.

## 5. Skipping the card is not the same as having paid

This is the distinction the whole design turns on, and it is easy to lose.

A PlasmaGuard partner has **no subscription row at all**. They are not on a
trial, not on commission hold, and not in arrears. They bought nothing, so there
is nothing to unlock. The training library is closed to them by
`opportunity:training`, which is a different gate from `training.unlocked` (the
partner who deferred payment) and from `subscribed` (the partner who never
finished card capture).

The way in is `BillingService::startSubscription()`, which associates the default
line on any successful subscription. That is the single door from the card-free
side to the paying side, and it is where a PlasmaGuard partner who decides they
want the training program comes through. Their primary stays `plasmaguard` —
which door they came in by is a historical fact and stays true.

## 6. What Stripe actually sees (2026-09-22)

The owner's reason for making the site product-first was so "Stripe will be able
to reference that". Worth writing down accurately, because the site is not the
lever it looks like:

- PlasmaGuard's charges are Connect **direct charges on their connected
  account** (`config/vendors.php`, `checkout.mode = direct`). Our cut arrives as
  an `application_fee_amount`.
- So on **our** Stripe account the activity is: $49.99 recurring subscriptions,
  application fees, and Connect payouts to partners down a sponsor tree.
- Stripe's review compares the website against that activity. A site that reads
  as product-only, over an account whose charges are a recurring fee and whose
  payouts go down a tree, is a **worse** match than one that describes both.

So the Partner Program stays in the main nav, the Earnings Disclaimer stays in
the footer, and the home page says plainly that partners are paid commissions.
This is recorded in `sites/q3.life/site.json` `_confirm` and in the site README
so a later copy pass does not quietly strip it.

**The comp plan is the actual question.** Stripe prohibits "multilevel marketing
services offering commission or recruitment-based sales". If the plan pays more
than one level up the sponsor tree, that is what conflicts, and the fix is the
plan or the processor — not the home page.

## 7. Partner websites are per line

`Opportunity::partnerSiteUrl($code)` decides which site a partner is given to
share. With one public site every line falls back to `QL_SITE_URL` in
`config/registration.php`, the single source of truth for it. A line with its
own domain sets `site_url` in the registry entry.

The mechanism is kept even with one site because getting it wrong is not
cosmetic: a partner handed the wrong front door sends their prospects to
something they were never offered, and the link still works, so nobody notices.

## 8. Admin

- **Users list**: an *Opportunity* column and a filter. Filtering by the default
  line includes null, i.e. every account that predates the feature.
- **User page**: an *Opportunities* card listing the lines held, whether each
  needs a card, how it was added and by whom. Staff can add a line, optionally
  making it primary, and remove a non-primary one. The primary cannot be removed
  — add the replacement as primary first.

## 9. Adding a third business line

1. Add an entry to `backend/config/opportunities.php`.
2. If it has its own marketing site: copy `sites/q3.life` into a new directory,
   set `site.json`'s `opportunity` block, add a `*_SITE_URL` env var and a
   `site_url` in the registry entry, add the origin to `backend/config/cors.php`,
   and add the nginx block (including the `/CODE` rule). The builder already
   takes a site directory, so nothing in `sites/build.py` changes.
3. That is all. No migration, no new middleware, no new templates.

## 10. Known limits

- **`Opportunity::all()` caches statically.** A runtime `config()` change is not
  picked up outside tests. Fine for config that is read at boot; worth knowing.
- **The primary is never changed automatically.** Buying the membership adds the
  line but does not move the primary, deliberately. Staff move it by hand.
- **Genealogy is shared.** A PlasmaGuard partner and a membership partner sit in
  the same tree. Whether the compensation plan should treat cross-line sponsorship
  differently has not been asked, let alone answered.
- **No per-line dashboard yet.** A PlasmaGuard partner sees the ordinary
  dashboard with the sections they hold, minus the "Upgrade" button and modal,
  which are now conditioned on `canSee('training')`. The stat cards and the
  welcome strip are otherwise the same for both lines.
