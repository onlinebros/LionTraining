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
- **No address validation.** We capture the address for the vendor's benefit but
  do not validate it — that is the merchant of record's job, and duplicating it
  invites two systems disagreeing about the same address.
