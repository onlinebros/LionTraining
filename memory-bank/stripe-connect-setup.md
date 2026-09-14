# Stripe Connect — setup runbook

How we take PlasmaGuard orders on **their** Stripe account, collect our $3,000
per unit automatically, and never hold a credential of theirs.

Verified against Stripe's Connect OAuth reference and direct-charges docs on
2026-09-09. Model: **Standard connected account, direct charges, application
fee.**

---

## 0. The shape of it

```
Customer pays on our page
        │
        ▼  PaymentIntent created with OUR platform secret key
           + header  Stripe-Account: acct_<plasmaguard>
           + application_fee_amount = $3,000 × qty
        │
        ▼
Charge lands on PLASMAGUARD's account          ── they are merchant of record
   their balance   += everything minus our fee minus Stripe's fee
   our balance     += $3,000 × qty              ── settles instantly, no invoice
```

We authenticate as ourselves and name their account in a header. **Their
credentials never exist on our servers.** That is the whole point.

---

## 1. What PlasmaGuard has to do

Genuinely all of it:

1. Open an authorisation link we send them.
2. Log into their existing Stripe account.
3. Read the permissions screen and click **Connect**.

That's it — perhaps three minutes, and it must be someone with admin rights on
their Stripe account. They keep the same account, dashboard, bank account and
payout schedule. Every order appears in their own dashboard. They can disconnect
us at any time from **Settings → Connected applications** without asking us.

Not Connect-related but still on them: confirm **Stripe Tax is enabled with
their state registrations completed**. It returns zero tax for an unregistered
state rather than erroring, so it fails silently and lands on them.

---

## 2. Our Stripe dashboard setup — no code

### 2.1 Enable Connect

**Dashboard → Connect → Get started.** Choose **Platform** (not marketplace) and
complete the platform profile: business details, what the platform does, support
contact.

> Stripe reviews platform applications. This can take a few days and is the long
> pole in the whole project. It does not depend on PlasmaGuard in any way, so
> **start it first.**

### 2.2 Accept the Connect platform agreement

Enabling Connect makes us a platform in Stripe's eyes, with obligations attached.
Worth someone actually reading rather than clicking through.

### 2.3 Brand the consent screen

**Connect → Settings → Onboarding options.** Business name, icon, brand colour,
support email and URL. This is the screen PlasmaGuard sees when they authorise —
it should say Q3, not look like an unbranded developer test.

### 2.4 Register redirect URIs and collect the client IDs

**https://dashboard.stripe.com/settings/connect/onboarding-options/oauth**

Register both:

```
https://q3.onlinebros.com/admin/vendors/{vendor}/connect/callback     (live)
http://127.0.0.1:8000/admin/vendors/{vendor}/connect/callback         (test)
```

Live redirect URIs **must** be HTTPS. Stripe only ever redirects to a
preregistered URI.

Collect **two** client IDs from this page — they are different values:

| | Looks like | Used with |
|---|---|---|
| Development | `ca_…` | test-mode secret key |
| Production | `ca_…` | live-mode secret key |

The client ID mode and the API key mode **must match** or the token exchange
fails with `invalid_grant`.

### 2.5 Add the Connect webhook endpoint

**Developers → Webhooks → Add endpoint**, and critically tick **"Listen to
events on connected accounts"** — a normal endpoint will not receive them.

URL: `https://q3.onlinebros.com/api/webhooks/stripe/connect`

Events:

| Event | Why |
|---|---|
| `payment_intent.succeeded` | The sale. Convert the lead, raise commission. |
| `payment_intent.payment_failed` | Let the partner see it failed. |
| `payment_intent.processing` | Delayed methods; not yet a sale. |
| `charge.refunded` | Unwind the sale, claw back commission. |
| `charge.dispute.created` | Chargeback — theirs to fight, ours to know about. |
| `application_fee.created` | Our money actually arriving (see §6.3). |
| `application_fee.refunded` | Our money going back out. |
| `account.application.deauthorized` | **They disconnected us.** Stop trying to charge. |

Copy the signing secret into `STRIPE_WEBHOOK_CONNECT_SECRET`.

**This endpoint already exists in the codebase** — `routes/api.php` →
`StripeWebhookController::handleConnect`, verifying against its own secret, with
the event ledger and idempotency already built.

---

## 3. What we build

### 3.1 Configuration

```
STRIPE_CONNECT_CLIENT_ID=ca_…          # matches the mode of STRIPE_SECRET
STRIPE_WEBHOOK_CONNECT_SECRET=whsec_…
```

### 3.2 A table for the connection

The vendor registry is config, but the account ID is a runtime credential that
differs per environment and can be revoked. It belongs in the database.

`vendor_connections`

| Column | Notes |
|---|---|
| `vendor` | Registry slug, e.g. `plasmaguard` |
| `stripe_account_id` | `acct_…` — the only thing OAuth gives us worth keeping |
| `scope` | `read_write` |
| `livemode` | Test and live connections coexist; never mix them up |
| `connected_at` / `connected_by` | Who authorised, when |
| `disconnected_at` | Set by `account.application.deauthorized` |

Unique on `(vendor, livemode)`.

### 3.3 The OAuth round trip

**Step 1 — send them to Stripe.** `GET /admin/vendors/{vendor}/connect` builds:

```
https://connect.stripe.com/oauth/authorize
  ?response_type=code
  &client_id=ca_…
  &scope=read_write
  &redirect_uri=https://q3.onlinebros.com/admin/vendors/plasmaguard/connect/callback
  &state=<signed, single-use, short-lived>
```

`scope=read_write` is required. `read_only` is for extensions and cannot create
charges. The `state` parameter is our CSRF defence and Stripe hands it back
untouched — generate it signed, store it, and reject a callback whose state we
did not issue.

**Step 2 — Stripe redirects back** to our callback with `code`, `scope`, `state`.

**Step 3 — exchange the code:**

```
POST https://connect.stripe.com/oauth/token
  grant_type=authorization_code
  code=<the code>
```
authenticated with **our** secret key.

The response field we want is **`stripe_user_id`** — that is the `acct_…`. Also
returns `livemode` and `scope`.

> `access_token`, `refresh_token` and `stripe_publishable_key` are **deprecated**.
> Do not store them. Everything is done with our platform key plus the
> `Stripe-Account` header.

**Step 4 — disconnect**, for completeness:

```
POST https://connect.stripe.com/oauth/deauthorize
  client_id=ca_…
  stripe_user_id=acct_…
```

### 3.4 Building the order

A `VendorOrderService` that, given a lead:

1. Computes the product subtotal from config × quantity.
2. Rates the parcel (EasyPost) → shipping. **Blocked on their carton data.**
3. Adds the $12 handling, combined with shipping into one line (see the tax note
   in `config/vendors.php`).
4. Lets Stripe Tax compute tax from the shipping address.
5. Computes our fee via `Vendors::revenueShare($vendor, $product, $qty, $subtotal)`
   — already written and tested, and it throws rather than let a misconfigured
   share exceed the goods.
6. Creates the PaymentIntent:

```php
$intent = $stripe->paymentIntents->create([
    'amount'                 => $total,          // subtotal + shipping + handling + tax
    'currency'               => 'usd',
    'application_fee_amount' => $ourShare,       // $3,000 × qty
    'automatic_payment_methods' => ['enabled' => true],
    'shipping' => [
        'name'    => $lead->fullName(),
        'address' => [...],
    ],
    'metadata' => [
        'ql_reference'     => $lead->public_ref,
        'ql_referral_code' => $lead->referral_code,
        'ql_partner_id'    => $lead->member_id,
    ],
], ['stripe_account' => $connection->stripe_account_id]);
```

`metadata` is what makes the charge legible in *their* dashboard — they can see
which partner sourced it without asking us.

Stripe enforces that `application_fee_amount` is positive and **less than the
charge**, which is a second net under our own guard.

### 3.5 New columns on `vendor_leads`

`subtotal_amount`, `shipping_amount`, `handling_amount`, `tax_amount`,
`application_fee_amount`, `vendor_account_id`, plus `carrier`, `tracking_number`,
`shipped_at` for fulfilment.

### 3.6 Front end

```html
<script src="https://js.stripe.com/basil/stripe.js"></script>
```
```js
const stripe = Stripe(PLATFORM_PUBLISHABLE_KEY, {
  stripeAccount: 'acct_…'        // same account used to create the intent
});
const elements = stripe.elements({ clientSecret, appearance: { /* Q3 tokens */ } });
elements.create('payment').mount('#payment-element');
```

The Appearance API takes our `--q3-*` values, so the card fields sit inside the
black-and-gold rather than dropping the customer onto white Stripe chrome. Card
data stays inside Stripe's iframe — **we remain PCI SAQ-A**.

`confirmPayment` with a `return_url` back to our thank-you page.

### 3.7 Webhook handling

Extend `VendorWebhookProcessor`: resolve the lead from
`metadata.ql_reference`, mark converted, raise commission from our share.
The existing attribution, CRM sync, ledger and clawback all keep working — a
paid order is still a paid order.

---

## 4. Testing — one account proves the whole thesis

**You do not need a second Stripe account, and you do not need PlasmaGuard.**
A platform in test mode creates its own test connected accounts, of both kinds,
on demand. Everything below runs against our account alone.

Note that **a sandbox is not a second party.** Sandboxes are isolated
environments inside one account; Connect needs a platform and a connected
account, which is a different relationship. The only thing that actually gates
this work is Connect being enabled on our own account.

Test mode also relaxes two rules that would otherwise get in the way: the
development `ca_…` accepts **non-HTTPS and localhost redirect URIs**, and the
OAuth flow can **skip the account form** so the round trip completes in seconds.

### 4.1 Create the stand-ins

```bash
# PlasmaGuard's stand-in — Standard, the kind we take charges on
curl https://api.stripe.com/v1/accounts -u sk_test_…: \
  -d type=standard -d country=US -d email=vendor@example.test

# A rep's stand-in — Express, the kind we pay out to
curl https://api.stripe.com/v1/accounts -u sk_test_…: \
  -d type=express -d country=US -d email=rep@example.test
```

Or **Dashboard → Connect → Accounts**. SMS code for test accounts is `000-000`.

### 4.2 Test data that makes verification pass

| Field | Value | Result |
|---|---|---|
| Date of birth | `1901-01-01` | Verification succeeds |
| SSN / ID number | `000000000` (last 4: `0000`) | Succeeds |
| Business tax ID | `000000000` | Succeeds |
| Address line 1 | `address_full_match` | Succeeds |
| Bank routing / account | `110000000` / `000123456789` | Payout succeeds |

Failure paths worth exercising too: DOB `1900-01-01` raises an OFAC alert, ID
`111111111` mismatches, address `address_no_match` puts the account into
`currently_due`, and bank account `000111111113` fails as `account_closed`.

### 4.3 The seven things to prove

| # | Prove | How |
|---|---|---|
| 1 | A vendor can authorise us | Full OAuth round trip; we store `stripe_user_id` |
| 2 | We can charge on their account | PaymentIntent with `Stripe-Account`; card `4242 4242 4242 4242` |
| 3 | **Our $3,000 actually arrives** | **Connect → Collected fees** shows the ApplicationFee |
| 4 | A refund returns our fee | Refund with `refund_application_fee=true`; fee reverses |
| 5 | A rep can onboard | Express account + AccountLink, completed with §4.2 data |
| 6 | We can pay a rep | `Transfer` from platform balance → rep balance → payout |
| 7 | A failed payout is handled | Bank account `000111111116` → `no_account` |

Item 3 is the one that matters commercially. Everything else is plumbing; that
line in **Collected fees** is the proof the revenue model works.

Other useful triggers: card `4000000000004210` blocks charges on an account,
`4000000000004236` blocks payouts — both worth seeing before a real rep hits
them. `4000 0025 0000 3155` forces 3DS, `4000 0000 0000 9995` declines.

Run `stripe listen --forward-to localhost:8000/api/webhooks/stripe/connect` to
receive connected-account events locally.

### 4.4 When a second account is worth having

Only for realism, never for capability:

- To see the OAuth **consent screen exactly as PlasmaGuard will** — our branding,
  the permissions wording, the whole experience from their side.
- To rehearse the request end to end before asking them to do it for real.

PlasmaGuard's sandbox invite can serve this purpose. It is a nice-to-have, and
it should not hold up a single line of the build.

---

## 5. Going live

1. Stripe approves the platform application.
2. Swap to the **production** `ca_…` and live keys.
3. Re-run the OAuth flow against their **live** account — a sandbox connection
   does not carry over.
4. Add the live Connect webhook endpoint and its secret.
5. Confirm on their live account: Stripe Tax on, registrations done.
6. Place one real order, refund it, confirm the application fee comes back.

---

## 6. Gotchas that will bite

**6.1 The authorisation code is single use and expires in 5 minutes.** Stripe's
docs are explicit: *consuming it more than once revokes the account connection.*
A retry loop or a double-submitted callback disconnects the vendor. Guard the
callback against replay.

**6.2 Direct-charge objects live on THEIR account, not ours.** PaymentIntents and
Charges are not visible to a plain platform-level API query — every read needs
the `Stripe-Account` header. This shapes our admin screens: we cannot list "all
our payments" from our own account. Our `vendor_leads` table is the platform-side
record, which is another reason it is written before the customer ever pays.

**6.3 Application fees are created asynchronously.** The `ApplicationFee` object
does not exist the instant the charge does. For reporting, listen for
`application_fee.created` rather than reading it back immediately.

**6.4 Refunds do not return our fee automatically.** Stripe: *"the connected
account loses that amount"* unless we pass `refund_application_fee=true`. On
$3,000 that is not a detail. Partial refunds return a proportional share.
Already defaulted to `true` in `config/vendors.php` — but it is a commercial
term and they should agree it.

**6.5 They pay Stripe's processing fee.** On direct charges, Stripe deducts its
fee from the *connected account's* balance, computed on the full charge including
shipping and tax — roughly $190 on a $6,612 order, entirely from their half while
ours stays whole. Get it agreed in writing before the first statement.

Good news: **there is no Stripe fee on the application fee itself.** Our $3,000
arrives whole.

**6.6 Data visibility runs both ways.** Everything we create on their Standard
account is visible to them — and to any *other* platform they have connected.
Worth knowing before we write anything sensitive into metadata.

**6.7 `read_write` cannot connect an account already controlled by another
platform.** If PlasmaGuard's account is controlled by some other software, OAuth
will refuse. Unlikely, but it is a hard stop if true, so worth establishing early.

---

## 7. Sequence and effort

| # | Task | Depends on | Rough |
|---|---|---|---|
| 1 | Enable Connect, platform review | nothing — **start now** | days, mostly waiting |
| 2 | Brand consent screen, redirect URIs, client IDs | 1 | 1 h |
| 3 | Connect webhook endpoint | 1 | 30 min (endpoint exists) |
| 4 | `vendor_connections` table + model | — | 2 h |
| 5 | OAuth authorise / callback / disconnect | 2, 4 | 1 day |
| 6 | PlasmaGuard authorises | 5 | 3 min of their time |
| 7 | Order builder + new money columns | 4 | 1–2 days |
| 8 | EasyPost rating | **their carton data** | 1 day |
| 9 | Payment Element front end | 7 | 1–2 days |
| 10 | Webhook handling, refunds, clawback | 3, 7 | 1 day |

Roughly **a week of build**, gated by two things that are not code: Stripe's
platform review, and PlasmaGuard supplying carton dimensions, weights and a
ship-from origin.

Items 1 and 2 depend on nobody. Start them today.
