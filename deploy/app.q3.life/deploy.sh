#!/usr/bin/env bash
# Deploys the member app to app.q3.life from GitHub. Run on the droplet as deploy:
#
#   /var/www/app.q3.life/deploy.sh [branch-or-tag]     (default: main)
#
# Every deploy is a fresh clone in releases/<timestamp>. `current` switches only
# after composer, the Vite build, migrations and caches have all succeeded, so a
# failed deploy leaves the previous release serving.
#
# Roll back to an earlier release (migrations are NOT reversed):
#   ln -sfn /var/www/app.q3.life/releases/<older> /var/www/app.q3.life/current.tmp
#   mv -Tf /var/www/app.q3.life/current.tmp /var/www/app.q3.life/current
#   sudo systemctl reload php8.5-fpm && sudo systemctl restart q3-queue
set -euo pipefail

BASE=/var/www/app.q3.life
REPO=git@github.com:onlinebros/LionTraining.git
REF=${1:-main}
REL=$BASE/releases/$(date +%Y%m%d%H%M%S)
KEEP=5

trap 'echo "Deploy failed. current still points at the previous release. Removing $REL" >&2; rm -rf "$REL"' ERR

echo "==> Cloning $REF into $REL"
git clone --quiet --depth 1 --branch "$REF" "$REPO" "$REL"
git -C "$REL" log --oneline -1

cd "$REL/backend"
rm -rf storage
ln -s "$BASE/shared/storage" storage
ln -s "$BASE/shared/.env" .env

echo "==> Composer"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress

echo "==> Front-end build"
if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; else npm install --no-audit --no-fund; fi
npm run build
rm -rf node_modules

echo "==> Migrations"
php artisan migrate --force

echo "==> Caches"
php artisan storage:link
php artisan optimize

echo "==> Switching current"
ln -sfn "$REL" "$BASE/current.tmp"
mv -Tf "$BASE/current.tmp" "$BASE/current"
sudo systemctl reload php8.5-fpm
sudo systemctl restart q3-queue
trap - ERR

echo "==> Pruning old releases (keeping $KEEP)"
live=$(readlink -f "$BASE/current")
for dir in $(ls -1d "$BASE"/releases/*/ | sort | head -n -"$KEEP"); do
    [ "$(readlink -f "$dir")" = "$live" ] && continue
    rm -rf "$dir"
done

echo "Deployed $(git -C "$REL" rev-parse --short HEAD) to https://app.q3.life"
