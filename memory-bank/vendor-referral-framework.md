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
