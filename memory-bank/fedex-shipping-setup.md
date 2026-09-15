# FedEx shipping rates — integration runbook

How to replace the flat placeholder with live rates on **PlasmaGuard's own FedEx
account (769848738)**, so quotes use their negotiated pricing rather than retail.

**Current state (2026-09-15): live.** Production keys from our FedEx developer
project are installed (`FEDEX_MODE=live`, `FEDEX_LIVE_API_KEY`,
`FEDEX_LIVE_SECRET_KEY`). PlasmaGuard's account 769848738 returns negotiated
`ACCOUNT` rates. Livonia → Green Bay WI measured on 2026-09-15:

| Service | Negotiated | List |
|---|---|---|
| FedEx Ground (business) | $14.78 | $25.48 |
| FedEx Home Delivery (residential) | $23.30 | — |

- **Rating.** `FedExShippingRater` quotes these rates. It sends `residential: true`
  whenever the FedEx address check classified the delivery as a home.
- **Address checks.** The same keys drive `FedExAddressVerifier`.
- **No outage fallback.** There is no flat fallback (`PLASMAGUARD_SHIPPING_FLAT=0`),
  so while FedEx is down an order goes to a human.
- **Sandbox.** It can't test address checks: every request gets the same canned
  Chilean address back.

The rest of this runbook records how rating was set up.

---

## 0. Decide one thing first: rating only, or labels too?

| | What we need | What it means for them |
|---|---|---|
| **Rate only** (recommended) | Read access to price a parcel | We quote, they ship on their own systems |
| **Rate + labels** | Ability to buy postage on their account | We could print labels that bill them |

They are merchant of record and they fulfil, so **rate-only is the right scope**.
Say so explicitly when asking — "we need to quote your rates, we do not need to
create shipments" is a much easier request to approve, and it keeps us out of
their postage billing.

---

## 1. Route A — EasyPost (recommended)

One integration, multiple carriers, and the parcel/DIM arithmetic is theirs
rather than ours. If PlasmaGuard ever add UPS or switch carriers, that is a
config change instead of a second integration.

### 1.1 Our side

1. Create an EasyPost account and complete billing.
2. Take the **production** API key. Carrier accounts can only be managed with the
   production key, even while testing.
3. `EASYPOST_API_KEY=` in `.env`.

### 1.2 Link PlasmaGuard's FedEx account (BYOCA)

"Bring Your Own Carrier Account" is what makes rates *theirs*. The only field
FedEx requires is the **account number**, plus the address as it appears on the
FedEx account:

```
account_number    769848738
shipping_streets  30933 Industrial Rd.
shipping_city     Livonia
shipping_state    MI
shipping_postal   48150
```

**The part that needs them:** since **1 March 2026** every FedEx account must be
registered through FedEx's **Multi-Factor Authentication API flow** — the legacy
SOAP/WebServices path is gone. FedEx sends a verification code to the registered
account holder, which is PlasmaGuard, not us. So this cannot be completed
without someone at their end relaying a code in real time.

Book fifteen minutes with whoever controls the FedEx account rather than sending
an email and waiting. It is a five-minute job if you are both on the call and a
fortnight of round-trips if you are not.

Once linked, negotiated rates come back in ordinary rate requests — same
response shape as published rates, different numbers.

### 1.3 The fallback if they will not link it

EasyPost's **Wallet** carrier accounts give immediate FedEx access with
EasyPost's own pre-negotiated rates and no registration at all. Useful to unblock
development, but those are EasyPost's rates, not PlasmaGuard's — and quoting a
rate PlasmaGuard did not negotiate on an order PlasmaGuard fulfils is a
reconciliation argument waiting to happen. Development only.

---

## 2. Route B — FedEx direct

Fewer moving parts commercially, more code. Worth it only if EasyPost's pricing
is objectionable or a compliance rule forbids the intermediary.

1. Register at **developer.fedex.com** and create a project.
2. The project issues an **API key** and **secret key**. There are separate
   sandbox and production credentials.
3. Authenticate with OAuth2 client credentials to get a bearer token, then call
   the **Rate and Transit Times API**.
4. Associate account 769848738 with the project for negotiated rates.

I could not verify the current portal screens — they are behind a login — so
treat the specifics as a sketch and follow the portal's own guide. The important
point is the same as Route A: the account association needs PlasmaGuard, and
FedEx's MFA applies here too.

The cost of this route is that we then own DIM-weight arithmetic, surcharge
handling, service-code mapping and address classification, all of which EasyPost
does for us.

---

## 3. What we build either way

One class, because the seam already exists:

```php
class FedExShippingRater implements ShippingRater
{
    public function quote(array $origin, array $destination, array $parcel, int $cartons): ShippingQuote
}
```

Then in `AppServiceProvider`, bind `ShippingRater` to it instead of
`FlatShippingRater`. Nothing else in the checkout changes — `VendorOrderService`
already asks for a quote and already handles one that says "a human must price
this".

It must:

- **Send dimensions, not just weight.** The carton is 12 × 12 × 15 in at 9 lb,
  which DIM-weights to about **16 lb** at FedEx's 139 divisor. Rate on 9 lb and
  every quote is roughly 40% light.
- **Rate `cartons` parcels, not one.** One unit per box, so three systems is
  three parcels.
- **Return `SOURCE_CARRIER`** so the order records where the number came from.
- **Fail rather than fall back.** `require_negotiated` is already true in config:
  if the carrier account is not linked, published rates must not be quoted
  silently. A failed quote routes the order to a human; a silent retail rate
  makes the product look overpriced and nobody finds out for weeks.
- **Classify residential vs commercial.** FedEx charges a residential surcharge.
  EasyPost's address verification determines this; skipping it means every
  house delivery is under-quoted.

Config already has the slots — `pricing.shipping.carrier.easypost_id`,
`origin`, and the product's `parcel` block.

---

## 4. Test it against reality

Once linked, quote a known lane and check the number against what PlasmaGuard
would pay shipping the same box themselves:

- Livonia MI 48150 → Ann Arbor MI 48104 (local, cheapest)
- Livonia MI → Austin TX 78701 (cross-country)
- Livonia MI → a residential address (surcharge applies)
- 3 cartons (should be roughly, not exactly, 3×)

If our number and theirs disagree, the negotiated rates are not actually linked
and we are quoting retail.

---

## 5. What to ask PlasmaGuard

> To quote your negotiated FedEx rates we need to link account **769848738** to
> our rating provider. Two things:
>
> 1. FedEx now requires their Multi-Factor Authentication flow to link an
>    account — they send a verification code to you as the account holder, so we
>    need fifteen minutes with whoever manages the FedEx account to complete it
>    together.
> 2. Please confirm the account's registered address so the details match. We
>    have 30933 Industrial Rd., Livonia, MI 48150.
>
> To be clear about scope: we only need to **quote** rates, not create shipments
> or buy labels on your account.

---

## 6. Until then

The checkout works on a flat per-carton figure from
`PLASMAGUARD_SHIPPING_FLAT`, labelled `flat` on the order so no one mistakes it
for a carrier quote. Set it to something defensible — ask PlasmaGuard what they
typically pay to ship one carton — and it is honest interim behaviour rather
than a guess dressed as a rate.

Orders above `freight_threshold_units` (8) already route to a human for an LTL
quote and will continue to, carrier integration or not.
