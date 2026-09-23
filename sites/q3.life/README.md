# q3.life

The public company site for Quantum 3 Solution (Q3): what the business is, pricing, and every policy a payment processor or cardholder needs to find without an account. The member app will live separately at app.q3.life.

**Product-first since 2026-09-22 (owner).** The site leads with the PlasmaGuard PRO In-Duct System, presents the free Partner Program as the way to sell it, and presents the $49.99 Training Program as an **optional add-on** bought from inside the back office. Its `opportunity.key` is `plasmaguard`, so every Join link here takes no card.

**The home page does not mention the Training Program (owner, 2026-09-22).** `src/pages/index.html` — which is also every partner's replicated site at `q3.life/CODE` — sells the system and the free Partner Program, and nothing else: no course card, no $49.99, no "the only thing we charge for" sentence. The Training Program page itself stays, with its nav link and its footer links, because the price and terms have to be published in advance where a cardholder or a processor can read them. Don't put the training back on the home page without the owner.

**It is never a "Membership" (owner, 2026-09-22).** A member account is free, so the paid part is an optional **Training Program** any member can add at this price. The word is gone from every page, and the `site.json` block that holds the price is `program`, not `membership`. The page is at `/training-program` (the old `/membership` 301s to it, by a hand-added nginx rule that the droplet still needs). Only the live Stripe product name still carries the old word.

The training program's own name is **Rediscover your Heart** (owner, 2026-09-22; it replaces the working title *Vision of the Heart Training*). It is only ever written as `{{ course.name }}`, so the name lives in `site.json` alone. "Training Program" stays as the generic label in the nav, the footer and the back office.

Two things must not be quietly undone in a later copy pass, and `_confirm` in `site.json` says so too: the **Partner Program stays in the main nav** and the **Earnings Disclaimer stays in the footer**, with the home page saying plainly that partners are paid commissions. PlasmaGuard's charges land on *their* connected account, so what Stripe sees on ours is still $49.99 subscriptions plus Connect payouts down a sponsor tree. A site that reads as product-only while the account activity says otherwise is the mismatch a review looks for. The comp plan is what decides the MLM question, not the home page.

Static HTML with no framework or install step. The Q3 black-and-gold tokens are copied from `assets/css/q3-theme.css`, and fonts are self-hosted so policy pages don't send visitors to Google.

This is the one public front door onto the back office. The builder (`../build.py`) and the fonts, stylesheets and referral script (`../_shared/assets/`) are kept separate from it so that a second front door — a campaign landing site, a separate B2B domain — is a directory and a `site.json`, not a fork. `sites/air.q3.life` was one such site, created 2026-09-21 and retired 2026-09-22 at the owner's request.

Every Join link carries `?o=plasmaguard&s=q3.life`, so the app records which door a partner came in through and knows not to ask for a card. Beyond that, the **training program is not on sale at all** (`MEMBERSHIP_ENROLLMENT_OPEN=false`), so nobody is asked for a card anywhere. When it opens, that variable and this site's "Opening soon" copy change together. See `backend/config/opportunities.php` and `memory-bank/opportunities.md`.

## Editing

- **Business facts** (entity, address, contact details, prices, refund windows): `site.json` only. Pages reference them as `{{ business.legal_name }}`.
- **Page copy:** `src/pages/*.html`. Shared header and footer: `src/layout.html`.
- **Styles:** `../_shared/assets/site.css`, shared with every Q3 site. A change here changes both.

A value beginning `TODO:` is unfilled. A preview build highlights it; a live build refuses to run. `_confirm` in `site.json` lists values taken from app config that the business still has to confirm.

## Build and publish

Review on dev first, then publish to production.

```bash
python3 ../build.py --preview   # dist/ with TODOs highlighted
./deploy.sh dev                 # https://q3.onlinebros.com/ (dev server; TODOs highlighted)
./deploy.sh preview             # https://q3.life/preview/ (basic auth)
./deploy.sh live                # https://q3.life/ (strict build)
```

The build also checks that every internal link and `#anchor` resolves.

`dev` builds with `--base /` into `dev-www/site/`. The dev server's nginx config (`/etc/nginx/sites-available/q3.onlinebros.com`) serves that folder at the **root** of q3.onlinebros.com and falls through to the Laravel app for anything not on disk, so this is the default page there exactly as it will be on q3.life. `/site/` 301s to `/`, and `/membership` 301s to `/training-program`. Those `location` blocks were added by hand — see `memory-bank/deployment.md` for the four that are easy to lose.

## Partner websites

Each partner's copy of the site is `q3.life/CODE`, where CODE is their 8-character referral code (`?ref=CODE` works too). nginx serves `index.html` for `/CODE`, and `../_shared/assets/ref.js` looks the code up at `{app}/api/sponsors/CODE`. It then shows an "invited by" bar and points every `data-join` link at `{app}/join/CODE?o=…&s=…`, where the two parameters come from the `opportunity` block in `site.json`. Elements marked `data-ref` appear only when a code is known, and `data-ref-hide` elements appear only when there is none. The code is remembered in the browser for 30 days.

### Ordering on a shared site

A visitor with a code can **buy**, not just enquire. `data-buy` links become
`{app}/p/CODE/plasmaguard/pro-in-duct` — that partner's storefront in the back
office, which takes the address, computes PlasmaGuard's sales tax and charges
their Stripe account. The vendor and product keys come from the `storefront`
block in `site.json` and must match `backend/config/vendors.php`; the layout
puts them on `<body>` as `data-buy-vendor` / `data-buy-product`, and a site that
declares neither leaves its `data-buy` links alone.

The price is printed on `/system` because it is PlasmaGuard's and is the same
for every buyer — the owner's point (2026-09-22) being that a quote is worthless
when nobody can change the price. It lives in `site.json` as
`storefront.price_display` and has to move with `PLASMAGUARD_PRO_PRICE` in the
app, or the site advertises one price and the checkout charges another.

A visitor with **no** code sees the contact form instead: with no partner in the
URL there is nobody to attribute the sale or the commission to.

Which site a partner is given to share depends on their business line. With one site they all resolve to `QL_SITE_URL`; a line with its own domain sets `site_url` in `backend/config/opportunities.php`. See `App\Support\Opportunity::partnerSiteUrl()`.

The `/CODE` rule is a `location ~ "^/[A-Za-z0-9]{8}/?$"` in both the dev config and `/etc/nginx/sites-available/q3.life` on the droplet, added by hand. On dev it needs a companion `location = /register`, because `register` is eight characters and the regex would otherwise swallow it.

## Before going live

The policy pages are drafted from standard practice and the platform's actual behaviour. They are not legal advice. Have a lawyer review the Terms, Privacy Policy, Partner Program and Earnings Disclaimer, and make sure the Compensation Plan matches what the Partner Program page says.
