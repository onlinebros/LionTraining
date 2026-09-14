# Tech Context

Describe frameworks, languages, package managers, databases, queues, external services, and local development commands.

## Stack

- **Backend:** Laravel 13 (PHP 8.3), Sanctum (session-based SPA auth), MySQL (prod) / SQLite-in-memory (test).
- **Frontend (Laravel-side):** Vite 8 + Tailwind v4, entrypoints `resources/css/app.css` + `resources/js/app.js`.
- **Static front-end theme bundle:** `landing/` and root `package.json` build the legacy Cuba theme via webpack; not part of the Laravel CI build.

## CI gates (GitHub Actions — `.github/workflows/ci.yml`)

Two jobs run on every push to `main` and every pull request:

**`backend`** (working directory `backend/`)

1. `php scripts/codegen-events.php --check` — fails on event-taxonomy drift.
2. `./vendor/bin/pint --test` — lint (scope limited via `backend/pint.json` to taxonomy/scripts/new tests; legacy paths are excluded from CI lint pending a dedicated style pass).
3. `./vendor/bin/phpstan analyse` — type check via PHPStan 2 + Larastan 3 over `app/` and `tests/Static/`. Pre-existing Laravel-style errors are held in `phpstan-baseline.neon`; new code (including all event-taxonomy call sites) is not exempt.
4. `./vendor/bin/phpunit` — full unit + feature suite. `tests/Feature/ExampleTest.php` is currently excluded in `phpunit.xml` (depends on Vite-compiled assets that the PHP test env doesn't produce); replace once funnel pages exist.

**`frontend`** (working directory `backend/`)

- `npm ci` (falls back to `npm install` when no lockfile) → `npm run build` (Vite).

## Local one-shot for the same gate

```bash
cd backend
php scripts/codegen-events.php --check \
  && ./vendor/bin/pint --test \
  && ./vendor/bin/phpstan analyse --no-progress \
  && ./vendor/bin/phpunit \
  && npm run build
```

## After editing the event taxonomy

```bash
cd backend
php scripts/codegen-events.php       # regenerate app/Events/Taxonomy/*.php
git add config/events/taxonomy.v1.json app/Events/Taxonomy/
```

## Dependencies added in the CI bootstrap

`require-dev` (composer): `phpstan/phpstan:^2.1`, `larastan/larastan:^3.0`.
