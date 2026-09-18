# Quantum 3 → iHub webhook: signature troubleshooting

Your endpoint is reachable and responding. It is rejecting us:

```
HTTP 401
{"status":"error","message":"Invalid signature. Digest did not match."}
```

13 consecutive attempts, all the same. So the connection, TLS, routing and your
handler are all fine — the only thing we disagree about is the signature.

This document has everything needed to find where. Start with § 1; it takes two
minutes and tells you which half of the problem you have.

---

## 1. Start here: is it the algorithm or the secret?

Run this against a known input. The secret below is a throwaway for this test
only — it is not the real one.

**Secret**

```
quantum3-test-secret-do-not-use-in-production
```

**Body** — exactly these 113 bytes, no trailing newline

```
{"id":"00000000-0000-4000-8000-000000000000","type":"ping","created":1700000000,"data":{"message":"test vector"}}
```

**We compute**

```
sha256=cec7084feaac94b031bc8768f17cfd84af4c1d5d56c4bfaf6d9cfd8a93dff5e7
```

Now run your own verification code over that body and secret.

| Your result | What it means | Where to go |
|---|---|---|
| Same digest | Your algorithm matches ours. The secret differs. | § 2 |
| Different digest | The algorithm differs. A new secret will not help. | § 3 |

Quick check from a shell:

```bash
printf '%s' '{"id":"00000000-0000-4000-8000-000000000000","type":"ping","created":1700000000,"data":{"message":"test vector"}}' \
  | openssl dgst -sha256 -hmac 'quantum3-test-secret-do-not-use-in-production'
```

## 2. If the algorithm matches: the secret

We generated a 48-character secret and it was passed to your side on
2026-09-18. If you are verifying against something else — an older value, one
you generated yourselves, or a placeholder — that is the whole problem.

Confirm with us which secret you hold. We can read ours back only by reissuing
it, so if there is any doubt we will issue a fresh one and send it over, and you
replace it at your end. Say the word.

Common ways this goes wrong:

- The secret was copied with a trailing newline or a space
- It was stored in one environment and the endpoint reads another
- Someone regenerated it on one side only

## 3. If the algorithm differs: what we actually send

### The request

```
POST https://app.ihub.global/api/_webhook/partner/quantum3
Content-Type:          application/json
X-Partner-Signature:   sha256=<64 lowercase hex characters>
Q3-Event-Id:           b5273150-8b11-4cd9-a0bc-6aadd4bf148c
Q3-Event-Type:         spot.claimed
User-Agent:            Quantum3Solution-Webhooks/1
```

### How the signature is computed

```
HMAC-SHA256( key = <shared secret>, message = <raw request body> )
```

then hex-encoded, lowercase, and prefixed with `sha256=`.

This is the GitHub convention. Three things it is **not**, each of which is a
plausible mismatch:

- **There is no timestamp in the signed material.** Stripe's scheme signs
  `"{timestamp}.{body}"`. Ours signs the body alone. If your handler was written
  against a Stripe-style integration, this is the most likely difference.
- **The key is the secret as a plain UTF-8 string**, not hex-decoded, not
  base64-decoded.
- **The digest is hex, not base64.**

### The exact body of a real failed delivery

633 bytes. Its SHA-256 is
`47cd6b667a50d518506c26b37df6f57760a528f31075f8adb727ffde7d837e9f` — check that
first, and if it does not match what your handler received, the problem is
before verification and you are re-serialising the payload somewhere.

```json
{"id":"b5273150-8b11-4cd9-a0bc-6aadd4bf148c","type":"spot.claimed","created":1789737590,"data":{"external_user_id":"iHub-3","claimed_at":"2026-09-18T12:57:10+00:00","imported_at":"2026-09-18T02:35:40+00:00","quantum":{"user_id":1304403,"referral_code":"XZSWDKEC","referral_url":"https://app.q3.life/join/XZSWDKEC"},"position":{"external_parent_id":"iHub-4","parent_quantum_user_id":1304509,"parent_is_partner_spot":true,"depth":4,"placed_at":null,"directs_claimed":0,"directs_unclaimed":244},"membership":{"active":true,"billing_pending":false},"merged_into":{"quantum_user_id":4,"referral_code":"ZLJLXWAL","external_user_id":null}}}
```

We sent `X-Partner-Signature: sha256=0abfe2b7a54bc46f998d16e224967ca8151d48fc8b35da691646010988f6ad15`
for that body.

### The mistake that catches almost everyone

**Sign the raw bytes off the wire, before any parsing.**

Most frameworks hand you a decoded object and re-encode it if you ask for the
body again. That re-encoding changes key order, spacing or unicode escaping, and
the digest changes with it — while the JSON still looks identical to a human.

```php
// Laravel
$raw = $request->getContent();                 // right
$raw = json_encode($request->all());           // wrong

// Express — needs the raw body kept before json parsing
app.use('/api/_webhook', express.raw({type: 'application/json'}));

// Django
raw = request.body                             // right
raw = json.dumps(json.loads(request.body))     // wrong
```

Our body has no spaces after `:` or `,`, does not escape forward slashes, and
does not escape non-ASCII. If your re-encoded copy differs in any of those, that
alone breaks it.

### Reference verification

```php
function verify(string $rawBody, ?string $header, string $secret): bool
{
    if ($header === null || ! str_starts_with($header, 'sha256=')) {
        return false;
    }

    $expected = hash_hmac('sha256', $rawBody, $secret);

    return hash_equals($expected, substr($header, 7));
}
```

```python
import hmac, hashlib

def verify(raw_body: bytes, header: str, secret: str) -> bool:
    if not header or not header.startswith("sha256="):
        return False
    expected = hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, header[7:])
```

```javascript
const crypto = require('crypto');

function verify(rawBody, header, secret) {
  if (!header || !header.startsWith('sha256=')) return false;
  const expected = crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
  return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(header.slice(7)));
}
```

Compare in constant time, and reject anything that fails.

## 4. Once it verifies

Answer **2xx** as soon as you have durably accepted the event — a queue or a
table row is enough. Do the real work afterwards; we allow 15 seconds and a slow
endpoint only means more retries.

**Key your idempotency on `Q3-Event-Id`.** Every retry and every manual replay
carries the same id and the same bytes. If you have seen it, answer 2xx and
discard — a replay means "here it is again", never a second claim.

We retry at roughly 30s, 5m, 30m and 1h, then stop and flag it for a human. The
two events currently failing will be replayed by hand once this is fixed, so
nothing is lost.

## 5. What we can change on our side

If it is easier for you, any of these is a small change for us — ask:

- **Switch to the timestamped scheme**: `Q3-Signature: t=<unix>,v1=<hmac of "t.body">`, the Stripe convention
- **Use a different header name**
- **Issue a fresh secret**

## 6. What is waiting

Two `spot.claimed` events for `iHub-3`, both stuck on the 401. There are
1,304,352 imported positions and one has been claimed so far, so the backlog is
small — but it will not stay that way once claiming opens, which is why this is
worth closing now.

Reach us with the delivery id (`2`) or the event id
(`b5273150-8b11-4cd9-a0bc-6aadd4bf148c`) and we can show you exactly what we
sent, byte for byte.
