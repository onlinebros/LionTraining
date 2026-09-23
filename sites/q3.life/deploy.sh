#!/usr/bin/env bash
# Publish the q3.life site. Dev first, then production.
#
#   ./deploy.sh dev       highlighted preview  -> https://q3.onlinebros.com/ (dev server, this machine)
#   ./deploy.sh preview   highlighted preview  -> https://q3.life/preview/ (password protected)
#   ./deploy.sh live      strict build         -> https://q3.life/  (refuses while anything is TODO)
#
# dev writes to dev-www/site/, which nginx on the dev server serves at the ROOT of
# q3.onlinebros.com, falling through to the Laravel app for anything not on disk.
# preview and live need the `liontraining-prod` SSH alias (see memory-bank/deployment.md).
set -euo pipefail
cd "$(dirname "$0")"

HOST="${Q3_HOST:-liontraining-prod}"

case "${1:-}" in
  dev)
    # The Turnstile widget in site.json covers q3.onlinebros.com too, so dev uses
    # the real site key and the dev app verifies against the real secret.
    python3 ../build.py --preview --base / --api-url https://q3.onlinebros.com
    mkdir -p dev-www/site
    rsync -rl --delete dist/ dev-www/site/
    echo "Published dist/ to https://q3.onlinebros.com/"
    exit 0
    ;;
  preview) python3 ../build.py --preview; dest=/var/www/q3.life/preview/ ;;
  live)    python3 ../build.py;           dest=/var/www/q3.life/public/ ;;
  *)       echo "usage: $0 dev|preview|live" >&2; exit 2 ;;
esac

rsync -rlz --delete --chmod=D2775,F664 dist/ "$HOST:$dest"
echo "Published dist/ to $HOST:$dest"
