# Owner-status API

A read-only JSON API that tells the owner how the business is doing: vendor sales,
subscriptions, sign-ups, support tickets and change requests, errors, and what is waiting
for approval. Its one consumer is the owner's AIM assistant at aim.onlinebros.com.

Built 2026-10-14 at the owner's request. Not deployed yet.

## The rules it keeps

- **GET only, read only.** No writes, no queued jobs, no calls out (it never calls Stripe).
- **Token or nothing.** Bearer token checked by `RequireOwnerStatusToken` (route alias
  `owner_status_token`). With no token issued, or the wrong one, every route is a 404.
  Throttled to 60 a minute.
- **Money is integer cents**, in fields named `*_cents`, with `currency` where the table has
  one. The commission tables keep decimal dollars and are converted (`round(x * 100)`).
- **Vendor gross is not our revenue.** `vendor_gross_cents` / `amount_total_cents` is what the
  vendor collected; `our_share_cents` is the company's share. Never add the two.
  `active_amount_cents` on subscriptions is the sum of per-period amounts, not normalised MRR.
- **No personal or secret data**: no emails, phones, addresses, tokens, Stripe ids, card data,
  request bodies, error context or stack traces. Names only; free text is truncated to 200
  characters with email addresses masked.
- Every section answers on an empty database or with an optional table missing.

## Endpoints

All under `/api/owner-status`, names `owner-status.*`.

| | |
|---|---|
| `GET /summary` | Headline counts from every section below (no lists), `generated_at`, `app` (site name). |
| `GET /sales?days=30` (1–365) | Vendor sales per vendor (converted, refunds, awaiting purchase, our share owed / invoiced / settled, last 10 sales), subscriptions (by status, entitling, new, canceled, past due, active amount), sign-ups by opportunity. |
| `GET /tickets` | Support tickets by status and open ones by priority, up to 50 open / in progress; change requests by status, up to 20 submitted / needs info. |
| `GET /errors?days=7` (1–90) | Unresolved error logs grouped by message, failed jobs, failed or bad-signature Stripe webhooks, failed partner webhooks, vendor payments needing attention, past-due subscriptions, failed payout transfers. |
| `GET /approvals` | Pending sponsorships, pending commission ledger, payouts pending and approved-not-paid, product partner payments to confirm, vendor leads waiting on attribution or address review. |

Each response carries a `notes` object where a figure needs explaining.

## The token

On live, in `current/backend`:

```
php artisan owner-status:token            # issue; prints OWNER_STATUS_TOKEN=<token> once
php artisan owner-status:token --revoke   # close the API
```

Only the token's SHA-256 is kept (site setting `owner_status_api_token_hash`). Issuing a new
one stops the old one at once. Hand the token to the AIM assistant's configuration; it is
not stored anywhere else here.

## Where things are

| | |
|---|---|
| Routes | `routes/api.php`, `owner-status.*` |
| Controller | `Api\OwnerStatusController` (clamps `days`) |
| Queries | `Services/OwnerStatus/OwnerStatusReport` |
| Guard | `Http/Middleware/RequireOwnerStatusToken` |
| Command | `Console/Commands/OwnerStatusToken` |
| Tests | `tests/Feature/OwnerStatus/OwnerStatusApiTest.php` |
