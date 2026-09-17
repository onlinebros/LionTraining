# Spot claim webhook — integration guide

When one of your members claims the position we imported for them, we POST a
signed JSON event to an HTTPS endpoint you give us. This is how you find out
which of your people have come across.

There is one event today: `spot.claimed`.

---

## 1. The request

```
POST https://<your endpoint>
Content-Type:  application/json
Q3-Signature:  t=1789412345,v1=5f2b…64 hex chars…
Q3-Event-Id:   3f7c1b8e-2a44-4c9d-9f1e-6b0d2c8a7e51
Q3-Event-Type: spot.claimed
User-Agent:    Quantum3Solution-Webhooks/1
```

```json
{
  "id": "3f7c1b8e-2a44-4c9d-9f1e-6b0d2c8a7e51",
  "type": "spot.claimed",
  "created": 1789412345,
  "data": {
    "external_user_id": "IHUB-10233",
    "claimed_at": "2026-10-02T14:31:08+00:00",
    "imported_at": "2026-09-20T09:12:44+00:00",

    "quantum": {
      "user_id": 4821,
      "referral_code": "K7QP2M9X",
      "referral_url": "https://q3.life/join/K7QP2M9X"
    },

    "position": {
      "external_parent_id": "IHUB-10001",
      "parent_quantum_user_id": 4610,
      "parent_is_partner_spot": true,
      "depth": 4,
      "placed_at": "2026-09-20T09:12:44+00:00",
      "team_size": 12,
      "unclaimed_below": 37
    },

    "membership": {
      "active": true,
      "billing_pending": false
    }
  }
}
```

### The fields

| Field | Meaning |
| --- | --- |
| `data.external_user_id` | **Your** ID for this position, exactly as you sent it in the import. This is the key to join on. |
| `data.claimed_at` | When the member entered their ID and activation code and completed the form. ISO 8601, UTC. |
| `data.imported_at` | When we created the position from your list. |
| `data.quantum.user_id` | Our internal ID. Stable, and worth storing for support conversations. |
| `data.quantum.referral_code` | Their referral code on our side. |
| `data.quantum.referral_url` | Their own invitation link on our side, built from that code. Safe to show them in your system. |
| `data.position.external_parent_id` | Your ID for the position directly above. `null` when the position above is not one of yours — which is the case for the top of every leg, since those hang beneath an existing Quantum partner. |
| `data.position.parent_quantum_user_id` | Our ID for the position directly above, whoever it belongs to. |
| `data.position.parent_is_partner_spot` | `true` when the position above also came from your list. When `false`, `external_parent_id` is `null` and this is a leg top. |
| `data.position.depth` | How deep the position sits in the whole structure, counting from the root. |
| `data.position.placed_at` | When the position was put into the structure — the import, not the claim. |
| `data.position.team_size` | Claimed members below them. Unclaimed positions are **not** counted. |
| `data.position.unclaimed_below` | Imported positions below them still waiting on their owner. |
| `data.membership.active` | Whether they have completed enrollment. **A claim and a paying member are not the same event** — somebody can claim their position and choose to be billed later. If you are counting conversions, this is the field you want, not the event itself. |
| `data.membership.billing_pending` | They chose to start paying once their commissions reach the threshold, rather than now. |

### `data.member` — only by agreement

Present **only if contact sharing has been agreed and switched on for your
account** (see § 5). Do not write code that assumes it exists.

| Field | Meaning |
| --- | --- |
| `data.member.name` | As the member typed it on our form, not as it appeared in your list. |
| `data.member.email` | The address they chose to sign in with here. It may differ from the one you hold. |
| `data.member.phone` | May be `null` — it is optional on our form. |
| `data.member.city` | May be `null`. |
| `data.member.state` | May be `null`. |
| `data.member.country` | Two-letter ISO code. |

## 2. Verifying the signature

`Q3-Signature` is `t=<unix timestamp>,v1=<hmac>`, where the HMAC is SHA-256 over
the literal string `"{t}.{raw request body}"` keyed with the shared secret we
exchange with you.

This is the same scheme Stripe uses, so if you already terminate Stripe webhooks
you have written this code before.

```python
import hmac, hashlib, time

def verify(raw_body: bytes, header: str, secret: str, tolerance: int = 300) -> bool:
    parts = dict(p.split("=", 1) for p in header.split(","))
    t, sig = parts["t"], parts["v1"]

    if abs(time.time() - int(t)) > tolerance:
        return False                      # too old — replayed

    expected = hmac.new(
        secret.encode(),
        f"{t}.".encode() + raw_body,
        hashlib.sha256,
    ).hexdigest()

    return hmac.compare_digest(expected, sig)
```

Two things that catch people out:

- **Sign the raw body, not a re-serialised object.** Parse the JSON *after* you
  have verified; any framework that decodes and re-encodes will change the bytes
  and the signature will not match.
- **Compare in constant time.** `compare_digest`, not `==`.

Reject anything that fails. A request that does not verify did not come from us.

## 3. Responding

Answer **2xx** once you have durably accepted the event — written it to a queue
or a table. Anything else is treated as a failure and retried.

Answer fast. We allow 15 seconds, and a slow endpoint just means more retries;
do the real work after you have acknowledged.

## 4. Retries and idempotency

We retry at roughly **30s, 5m, 30m and 1h**, then stop and raise it on our side
for somebody to look at and replay by hand.

**Every retry and every manual replay carries the same `Q3-Event-Id` and the
same bytes.** Key your idempotency on that ID. If you have seen it, answer 2xx
and discard — a replay is us saying "here it is again", never a second claim.

The event body is frozen when the claim happens. If we replay it a week later,
the `team_size` in it is the one from the moment of the claim, not today's.

## 5. Personal data

The import you send us carries **no personal data** — four columns: your user
ID, the activation code, and the two structure columns. We do not want your
members' names or email addresses, and we discard them if a file contains them.

This event is the only place data flows the other way, and by default it still
carries none: an identifier, timestamps, and the position.

`data.member` — name, email, phone, city, state, country — is sent **only** if
that has been agreed and switched on for your account. Those are details the
member gave *us*, so sharing them back to you is a disclosure to a third party.
It needs to be something both sides have agreed and that our claim page and
privacy policy tell the member about, not a default. Ask us and we will turn it
on; do not write code that depends on it before then.

## 6. Getting set up

Give us:

1. An **HTTPS** endpoint URL. We will not send to plain HTTP.
2. A **signing secret**, at least 16 characters. Generate it randomly; we store
   it encrypted and it is never displayed again after it is saved.
3. Whether you need `data.member` (see § 5).

We can fire a test `ping` event at your endpoint on request, before any real
claim happens. It has the same headers and signature, `"type": "ping"`, and a
`data` payload of `{"message": "…"}` with no spot behind it.
