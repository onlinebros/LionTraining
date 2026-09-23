# Vendor Referral Framework

How a Quantum Life partner sells a **third-party physical product** and gets paid
for it, when the vendor owns the checkout and we own nothing but the referral.

First vendor: **PlasmaGuard** (plasmaguard.com, Ann Arbor MI) — non-thermal cold
plasma air purification. First product: **PlasmaGuard PRO In-Duct System**.

---

## 1. The constraint that shapes everything

PlasmaGuard's checkout is a **Stripe Payment Link on their own Stripe account**
(`buy.stripe.com/...`). We do not own it, cannot see its events by default, and
cannot change its price. Two consequences drive the whole design:

1. **We must record the sale attempt on our side *before* the customer leaves.**
   Attribution can never depend on anything coming back to us. If PlasmaGuard
   sends us nothing at all, we still know who referred whom — we just have to
   reconcile conversion by hand.
2. **The vendor's number is always the truth.** Quantity, shipping, tax and
   discounts are decided on their page, after we lose sight of the customer.
   Anything we record at capture is an *estimate*; the confirmation overwrites it.

The email from their side described a full custom checkout (EasyPost live rates →
AvaTax → server-computed total → charge). That architecture and a Payment Link
are alternatives, not stages — a Payment Link is fixed-price and cannot compute
either. **This framework is deliberately indifferent to which one they end up
with.** The vendor checkout is a URL in config with a parameter map; if they
replace the Payment Link with a real checkout, we change config, not code.

## 2. Who is who

| | |
|---|---|
| **Merchant of record** | PlasmaGuard. They take the money, owe the sales tax, ship the goods, and own the customer relationship after purchase. |
| **Quantum Life** | Referral channel. We capture the lead, attach the partner's identity, hand it over, and invoice PlasmaGuard for commission on confirmed sales. |
| **Partner (member)** | Sources the customer. Earns from our commission engine, never from PlasmaGuard directly. |

We take **no card data**, so we stay out of PCI scope, and we make no tax
representation. This split is non-negotiable — it is what keeps a referral
programme from becoming an unlicensed reseller operation.

## 3. The flow

```
Partner shares  /p/{referral_code}/{product}
        │
        ▼
Customer lands on the member-coded product page
        │  submits name / email / phone / address / qty
        ▼
  CrmContact  (owned by the partner, lead_source=referral)
  VendorLead  (status=new, public_ref=QLV-XXXXXXXXXX)
        │
        ▼  redirect, status=handed_off
Vendor checkout + ?client_reference_id=QLV-…&prefilled_email=…
        │
        ▼  customer pays on the vendor's Stripe
Vendor Stripe account fires checkout.session.completed
        │
        ▼  POST /api/webhooks/vendor/plasmaguard   (vendor's own signing secret)
  stripe_webhook_events  (endpoint = "vendor:plasmaguard")
        │
        ▼  VendorWebhookProcessor
  VendorLead → converted   (amount/currency/session id from THEIR payload)
  CrmContact → purchased
  CommissionLedger → credit, pending, clawback window open
```

Refunds arrive on the same endpoint as `charge.refunded` and drive the lead to
`refunded` plus a clawback against the ledger credit.

## 4. Attribution — three layers, deliberately redundant

Ranked by how little PlasmaGuard has to do:

1. **`client_reference_id` on the checkout URL.** Stripe Payment Links accept it
   as a query parameter (alphanumeric, `-`, `_`, ≤200 chars). It lands on the
   Checkout Session and appears in their dashboard and CSV exports. **Zero
   configuration on their side.** Our `public_ref` (`QLV-` + 10 chars) is built
   to fit that charset — this is the primary key of the whole integration.
2. **`prefilled_email`.** Also a supported Payment Link parameter. Its real job
   is to stop the customer typing a *different* email than the one we captured,
   because email is our fallback match key.
3. **A custom field on their link** ("Partner Code", text, optional). Two minutes
   in their dashboard. Puts the code on their receipts where a human can read it.

Layer 1 is the machine key, layer 3 is the human key, layer 2 protects the
fallback. Losing any one of them is survivable.

## 5. Confirmation — four options, in the order to push for them

| | Mechanism | Their effort | What we get |
|---|---|---|---|
| **A** | Webhook endpoint in their Stripe → our URL, plus the signing secret | one dashboard screen | Real-time, signed, exact amounts. **Ask for this.** |
| **B** | Restricted read-only API key (Checkout Sessions / Payment Intents) | one dashboard screen | Polled reconciliation. Good backstop even alongside A. |
| **C** | "After payment" redirect to our thank-you URL | two minutes | UX only. Client-side and forgeable — **never** the system of record. |
| **D** | They email/drop a CSV of orders | ongoing manual | Matched on `public_ref`, else email + amount + date. The floor. |

The code supports A and D today; B and C are additive and need no schema change.
**We build assuming D and treat A as the upgrade**, because D is the version that
ships no matter how the meeting goes.

## 6. Matching, when the reference is missing

`VendorReferralService::resolve()` tries, in order:

1. `client_reference_id` → exact `public_ref` lookup. Authoritative.
2. Their session id, if we have already seen it (replay safety).
3. Customer email, restricted to leads in `handed_off` within a configurable
   window (`match_window_days`, default 30), most recent first.

A payload that matches nothing is stored, logged and left for a human on the
admin reconciliation screen. **It is never guessed at** — a wrongly attributed
commission is worse than an unattributed one, because it pays the wrong partner
and someone has to claw it back.

## 7. What we send PlasmaGuard

Admin → **Vendor Leads → Export CSV**: `public_ref`, captured timestamp, customer
name/email/phone/address, quantity, status, and the referring partner's name,
email and referral code. That is the "who actually placed the order" artefact.
Until they take an API, this is emailed or dropped on a schedule.

## 8. Commission

A converted lead is exactly the *qualifying revenue event* the compensation
engine has been missing (see `activeContext.md` — one-time product sales were
unbuilt). `CommissionLedger.source` is already polymorphic, so a `VendorLead`
becomes a commission source with no schema change:

- credit raised at `converted`, status `pending`
- `clawback_eligible_until` = converted_at + `clawback_days` (config, default 60)
- refund → clawback via the existing `CommissionClawback` path

The **rate is config, not code** (`vendors.plasmaguard.commission`), because it
is a commercial term that will change and must not need a deploy.

## 9. Open items for the PlasmaGuard meeting

1. May we append `client_reference_id` and `prefilled_email`? Will the link URL
   stay stable, and will they tell us before regenerating it?
2. Will they add the webhook endpoint and give us the signing secret? (Option A.)
3. Will they point "After payment" at our confirmation URL?
4. Will they add the "Partner Code" custom field?
5. **What does the price on that link actually cover** — is shipping included, is
   tax added, is quantity adjustable, is address collection on? We cannot read
   the page; it renders client-side.
6. Confirm merchant of record, tax filing, shipping SLA, warranty handling, and
   the commission rate + payment terms.
7. How do they want orders delivered — API, scheduled CSV, or email?
8. Are they proceeding with the EasyPost/AvaTax build? If so, when, and what
   replaces the Payment Link?
9. **Written permission to use their product photography and product names** on
   partner pages. Three device shots are mirrored into
   `assets/images/vendors/plasmaguard/` (see `SOURCES.md` there) on the ordinary
   footing that an authorised reseller may show what it is selling — but that is
   an assumption, not an agreement. Ask for a brand-asset pack while we are at
   it; theirs are 1080px squares scraped off a WordPress media library.

Only item 1 is required to launch. Item 2 is what makes it not manual.

## 10. Known limits of the current build

- **Quantity/price drift.** If the customer changes quantity on the vendor's page
  we only learn it from the confirmation. Handled by always overwriting from
  their payload, never trusting ours.
- **Abandoned handoffs.** Leads sit at `handed_off` indefinitely. They need an
  expiry sweep once we know the vendor's typical time-to-purchase.
- **One product, one link.** The config is a registry keyed by vendor and product
  so a second product or a second vendor is data, not a new integration.
- **Address validation (owner, 2026-09-15).** Both order paths (the customer
  checkout and Buy for yourself) now check the delivery address with FedEx
  before payment. See §13.

## 11. A partner's own purchase (owner, 2026-09-14)

A partner is never paid commission on their own purchase. It counts as their
sale (their record, promotions), and the commission goes to **their sponsor**,
whichever share link was used. Buying through your own link, or swapping links
with another partner, therefore cannot cut the sponsor out. A partner with no
sponsor buying for themselves pays no one.

Partners order for themselves from **Product Sales → Buy for yourself**
(`member.sales.buy`). The order uses the account email and goes through the
normal checkout.

Each lead records four people:

| Column | Meaning |
|---|---|
| `member_id` | whose share link was used |
| `buyer_user_id` | the partner who bought for themselves, if any |
| `credited_member_id` | who the sale counts for |
| `earner_id` | who commission is paid to |

`App\Services\Vendor\PurchaseAttribution` sets them at capture and again at
conversion. The `attribution` column records the outcome:

- `self`: back-office order, or the buyer's email or phone (last 10 digits)
  matches a partner account. Automatic.
- `review`: only the shipping address (line 1 + ZIP5) matches, or a phone
  matches several partners. Commission is held until an admin decides on the
  lead page (`admin.vendor-leads.attribution`). The decision is final and later
  webhooks do not re-evaluate it.
- `customer`: no match. The link owner is credited and paid.

Attribution cannot be changed after commission is raised. Use a clawback.

**Not detectable:** a partner using a different email and phone and shipping
elsewhere. Card fingerprints are no help, because the charge is on the vendor's
Stripe account.

**Commission basis** is now honoured: 10% of our revenue share ($300 per
system), not of the order total. Credits raised before 2026-09-14 used the order
total.

## 12. Promotions

`config/promotions.php`. The current one is **First 100 PlasmaGuard PRO
Systems**:

- Counts **units** (a 3-system order fills 3 places), in order of confirmed payment.
- Starts 2026-09-14 00:00 Eastern (`PROMO_PG_PRO_100_STARTS_AT`).
- Refunded orders drop out and the next sale moves up.
- The order that fills the last place counts only the places left.

`PromotionTracker` computes standings from converted leads on every read. No
places are stored. Partners see a leaderboard at `member.sales.promotion`, and
admins see every qualifying order at `admin.vendor-leads.promotion`.

**The "buy yours" offer (owner, 2026-09-15).** The first 100 are a **launch
special**, not the product's supply. More than 100 systems will be sold, but
only a partner who gets one of the first 100 gets the special. Copy must never
suggest only 100 exist. Buying for yourself is marketed wherever partners look
while qualifying places remain:

- **Member dashboard:** a banner under the welcome strip.
- **Product Sales:** the hero at the top, and the share-link button reads "Buy yours".
- **Sidebar:** a "N left" badge on Product Sales, plus a "Buy Yours" link.
- **Promotion page:** a "Buy yours" button.

`BuyYoursPromoComposer` feeds all four with `$buyYours`, and caches places-left
for 60 seconds. It is null, and the offer disappears everywhere, when no
promotion is running, when the vendor is switched off, or once every place is
taken.

The copy names **no reward**. The bonus terms are not written down yet, so
partners with a sponsor are told "Ask your sponsor about the launch bonus".
It always states who is paid on an own purchase. Once the terms exist, update
`member/vendor/partials/buy-yours.blade.php` to state them.

**The launch special's money (owner, 2026-09-17).** Configured in
`promotions.*.bonus` and paid by `App\Services\Vendor\PromotionBonuses`:

- **Place bonus.** Each of the first 100 systems earns **$500** for the partner the
  sale counts for (the link owner, or the buyer on an own purchase). It is per
  system and on top of the normal $300 commission.
- **Pool.** Each of the next **500** systems adds **$100** to a pool shared one share
  per place ($1 a share), credited as each sale is confirmed. After system 600 the
  special is over. The maximum is $50,000 in bonuses plus $50,000 in the pool.
- **Payment.** Every credit is a pending `commission_ledger` row, so normal payout
  runs pay it. `promotion_awards` records what each credit is for: a place
  (order + unit) or a pool share (contributing order + earner).
- **Reconcile.** `reconcile()` recomputes the standings and adds or voids credits to
  match. It runs after every convert, refund and attribution decision, and by hand
  with `php artisan promotions:reconcile-bonuses`. It is idempotent and holds a
  Postgres advisory lock.
- **Refunds.** Within `lock_days` (60), the refunded system's credits are voided and
  the next sale moves up. After that the place is final, and a refunded order keeps
  its place.
- **Paid credits.** A credit that must go but was already approved or paid is not
  reversed. It becomes `needs_clawback` on the admin promotion page.
- **Held places.** A place waiting for an attribution review earns nothing, and its
  pool share waits with it, until the review is decided.
- **Tests.** `phpunit.xml` sets `PROMO_PG_PRO_100_BONUS=false`, so commission tests
  count only commission credits. `PromotionBonusTest` switches it on.

**CRM (owner, 2026-09-17).** A partner's own purchase is filed in their
**sponsor's** CRM, linked to the buyer's account (`linked_user_id`). A partner
with no sponsor buying for themselves is filed nowhere. A customer sale is filed
with the link owner, as before.

## 13. Delivery address check (owner, 2026-09-15)

Both order paths check the delivery address with **FedEx Address Validation**
before payment: the customer checkout (`/p/order/{ref}`) and Buy for yourself.
The check runs in `App\Services\Vendor\AddressCheck`, backed by
`FedExAddressVerifier`, and uses the same FedEx project keys as rating.

**When it runs.** When an address is saved, or when a back-office order is
placed. An order whose address was never checked is checked the first time it
is opened. Changing only the quantity or the notes does not check again.
Changing the address clears any earlier confirmation or review.

**Outcomes.** Stored in `vendor_leads.address_status`:

| Status | What happens |
|---|---|
| `verified` | FedEx confirmed the address (DPV). Its standard form is saved (for example "Court" becomes "CT") and payment opens. |
| `suggested` | FedEx moved the address: a different ZIP, city, state or house number. The buyer chooses "Use suggested address" or "Keep my address". |
| `unverified` | FedEx couldn't confirm it (not found, missing or invalid unit, several matches). The buyer ticks "ship to this address as entered". |
| `unavailable` | No FedEx keys, FedEx down, or a sandbox canned reply. The buyer ticks to confirm. |
| `rejected` | A PO Box. It can't be confirmed, and the buyer must enter a street address. The forms also refuse PO Boxes before FedEx is called. |

**Buyer-confirmed addresses.** The buyer can pay, and the order is flagged:

- **Admin:** the Vendor Orders "Address Checks" count, a badge on the order, and
  "I have checked this address" on the order page.
- **PlasmaGuard:** the Stripe metadata carries `ship_address_check` and
  `ship_address_type`, so their team can see this too.

**Payment gate.** The Pay endpoint and `VendorOrderService::place()` both
refuse until `VendorLead::addressReadyForPayment()` is true.

**Rating.** A delivery FedEx classifies as residential is rated with
`residential: true`, which gives Home Delivery rates. On a Green Bay home that
is $23.30, not the $14.78 business Ground rate.

**Tests.** They never call FedEx: `phpunit.xml` blanks the keys, and
`AddressVerificationTest` fakes production-shaped replies. In those replies the
attribute values are the strings "true" and "false".

## 14. The Product Partner portal (owner, 2026-09-22)

The vendor's own people, inside our back office, in a section that is neither
the member area nor the admin area.

**Why a third section.** A product partner is an outside company with a login.
The member area would put them through the subscription gate and ask a vendor
for a card for a training program nobody sold them; the admin area would show
them the whole business. So: its own role, its own middleware, its own layout,
and its own route prefix — `/product-partner`. The tooling for helping them
close deals hangs off this section when it is built.

**The role grants nothing.** `roles.product_partner` (level 5, `is_admin` false,
inserted by migration because production is migrated and not seeded) says what
kind of account it is. `product_partner_assignments` says what it can see: one
row per vendor per product, with `'*'` meaning every product that vendor has now
or adds later. An account with the role and no assignment signs in and reaches a
holding page saying so — that is the state between "role set" and "products
linked", and it is minutes long in practice.

Everything the portal reads goes through `App\Support\ProductPartner`, which
intersects the grants with the vendor registry. A grant naming a vendor removed
from `config/vendors.php` grants nothing. **A query in this section that reaches
`vendor_leads` without `ProductPartner::scopeLeads()` is a bug**, because the
failure mode is one vendor seeing another's customers.

**Admins get in two ways, and they are not the same thing.**

1. **As themselves.** Any admin can open the portal — sidebar → Vendor Orders →
   Open Partner Portal. They hold *every* vendor, and the page says so in a
   banner. This answers "does the portal work", not "what does PlasmaGuard see".
2. **View as.** Admin → Product Partners → **View as** on a row. The whole
   portal then scopes to that partner's grants: their vendors, their products,
   their orders, their totals, and their holding page if nothing is linked. This
   is the one that answers the second question.

`ProductPartner::viewedBy()` decides whose eyes a request is read through, from
a session key, and `PortalController::subject()` is what every screen scopes to.
**A controller in that namespace using `$request->user()` for scoping is a
bug** — it would silently widen the page back to the admin's own access.

Three properties hold it honest:

- **It only ever narrows.** `viewedBy()` is the identity function for anyone who
  is not an admin, so a product partner cannot scope themselves to another
  partner by any means, including putting the key in their own session.
- **It announces itself on every page**, including the holding page, which has
  no navigation of its own and so carries its own way back.
- **It is read-only.** Recording a payment is refused while it is on. A payment
  filed by an admin wearing the vendor's face would read as the vendor's claim
  and would not be one, on the one screen whose purpose is that both companies
  trust the same numbers. Admins settle from the admin side, under their own
  name.

A view whose subject is deleted or taken off the role is dropped rather than
kept — a view corresponding to nobody looks like data and is not.

**The privacy line runs at the point of payment.** Decided by the owner, and the
reason the Pipeline screen is counts rather than a list:

| | What the vendor sees |
|---|---|
| **Open prospect** (`new`, `handed_off`) | Counts, medians and trends only. No name, no email, no address, no partner. It is a customer one of our partners found and has not closed, and handing it over lets the vendor close it themselves. |
| **Confirmed order** (`converted`, `refunded`) | Everything: customer, address, qualifiers, amounts, our share, invoice state, tracking. They are the merchant of record, their order desk is already emailed all of it, and withholding it here would be theatre. |

The referring partner's **name and code** appear on a confirmed order; their
contact details do not. The partner is our relationship until there is a channel
built for it.

**The settlement account.** One page both companies read, at
`/product-partner/statement`. The arithmetic, which lives once in
`App\Services\ProductPartner\PartnerStatement`:

```
outstanding = earned - credits - paid
```

- `earned` — `our_share_amount` on every confirmed sale, ever.
- `credits` — share on orders refunded **after** being invoiced. A refund moves
  the lead off `converted` so it leaves `earned` by itself; that is right for an
  order never billed and wrong for one that was, where the money goes back.
- `paid` — payments the vendor recorded **and we confirmed**.
- `pending` — recorded and not yet confirmed. **Never in the balance.**

`settled` (what we ticked off invoice by invoice) and `paid` (what they said
they sent and we agreed) measure the same money from two directions and are both
shown. When they disagree, that difference is the conversation the screen exists
to have — do not derive one from the other.

**The vendor can add to the ledger and cannot move it.** They record a payment;
it is pending until a super admin confirms it on
Admin → Vendor Orders → Vendor Payments. Confirming a payment that names an
invoice settles every order on that invoice **in the same transaction**: a
confirmed payment sitting next to an invoice still marked owed is precisely the
disagreement this was built to stop. A payment can only be decided once, and a
rejection requires a reason, which the vendor sees.

There is no invoices table. An invoice is a reference an admin typed onto a
batch of orders (§7, the existing reconciliation screen), so the statement
builds each one by grouping its orders — a line can therefore never disagree
with its total. An invoice with any unsettled order on it reads as open.

**The two counts the vendor asked for**, on the dashboard:

- **Active partners on this line** — accounts holding the business line whose
  `vendor` key points at them (`config/opportunities.php`), with, underneath,
  how many have actually sourced a prospect. Never names, only sizes.
- **In approval** — orders waiting on a decision at *our* end before the sale is
  final: attribution review (§11) and address review (§13). Shown because it is
  the honest answer to "why is that order not on my statement yet", and the
  screen says plainly that nothing is needed from the vendor.

**Isolation, and the one hole in it (owner, 2026-09-22).**
`ProductPartnerSectionAccess` is on the whole `web` group and matches by
route-name prefix — the same mechanism as the pre-launch guard, so a section
added later is covered the day it exists. Two rules, for two reasons:

- `admin.*` — **never**. They are an outside company's employee, not staff, and
  no amount of business line changes that.
- `member.*` — **only once they are put on a business line.** A vendor's sales
  people sell for us and want the back office; their accountant does not.

That second rule is not tidiness. An account with no business line recorded
reads as the **training** line, which requires a card — so letting an unlinked
product partner into the member area walks a vendor into a card capture screen
for a $49.99 membership nobody sold them. `User::canUseMemberArea()` therefore
tests for an **explicit** `user_opportunities` row and never `opportunities()`,
which folds in that default.

**Letting a partner sell.** Admin → the user's page → *Give member access*. One
button rather than "add the line, and remember to tick primary": a non-primary
line leaves the default in place and the card gate armed, and the person it
happens to works for the vendor. It puts them on the line whose `vendor` key
matches a vendor they already hold, **as primary**, so "can see PlasmaGuard's
numbers" and "can sell PlasmaGuard" stay paired. They get a referral code and
**earn ordinary commission** (owner's call, 2026-09-22) — worth remembering that
this means the vendor's own staff take a cut of what the vendor pays us.

Each side links to the other in the sidebar. Holding two back offices with no
way between them is worse than holding one.

**Where people land.** `users.landing_preference` ('admin' | 'member' |
'portal', null = the default for that kind of account) with the control in the
profile menu of all three shells. It renders only for accounts holding more than
one section, so an ordinary member never sees it. A stored preference for a
section since lost is ignored rather than obeyed — otherwise losing access
strands somebody on a redirect that bounces them straight back.

**Not built yet.** Deal-closing tools: no way for the vendor to message a
partner, claim a prospect, or be assigned one. That is the next piece, and the
section exists to hold it.
