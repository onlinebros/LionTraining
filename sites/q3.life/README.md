# q3.life

The public company site for Quantum Life (Q3): what the business is, pricing, and every policy a payment processor or cardholder needs to find without an account. The member app will live separately at app.q3.life.

Static HTML with no framework or install step. The Q3 black-and-gold tokens are copied from `assets/css/q3-theme.css`, and fonts are self-hosted so policy pages don't send visitors to Google.

## Editing

- **Business facts** (entity, address, contact details, prices, refund windows): `site.json` only. Pages reference them as `{{ business.legal_name }}`.
- **Page copy:** `src/pages/*.html`. Shared header and footer: `src/layout.html`. Styles: `src/assets/site.css`.

A value beginning `TODO:` is unfilled. A preview build highlights it; a live build refuses to run. `_confirm` in `site.json` lists values taken from app config that the business still has to confirm.

## Build and publish

Review on dev first, then publish to production.

```bash
python3 build.py --preview   # dist/ with TODOs highlighted
./deploy.sh dev              # https://q3.onlinebros.com/site/ (dev server; TODOs highlighted)
./deploy.sh preview          # https://q3.life/preview/ (basic auth)
./deploy.sh live             # https://q3.life/ (strict build)
```

The build also checks that every internal link and `#anchor` resolves.

`dev` copies the build into `dev-www/site/`. The dev server's nginx config (`/etc/nginx/sites-available/q3.onlinebros.com`) serves that folder at `/site/`, alongside the Laravel app. That `location` block was added by hand, so re-add it if the config is ever regenerated.

## Before going live

The policy pages are drafted from standard practice and the platform's actual behaviour. They are not legal advice. Have a lawyer review the Terms, Privacy Policy, Partner Program and Earnings Disclaimer, and make sure the Compensation Plan matches what the Partner Program page says.
