#!/usr/bin/env bash
# Publish the q3.life site. Dev first, then production.
#
#   ./deploy.sh dev       highlighted preview  -> https://q3.onlinebros.com/site/ (dev server, this machine)
#   ./deploy.sh preview   highlighted preview  -> https://q3.life/preview/ (password protected)
#   ./deploy.sh live      strict build         -> https://q3.life/  (refuses while anything is TODO)
#
# dev writes to dev-www/site/, which nginx on the dev server serves at /site/.
# preview and live need the `liontraining-prod` SSH alias (see memory-bank/deployment.md).
set -euo pipefail
cd "$(dirname "$0")"

HOST="${Q3_HOST:-liontraining-prod}"

case "${1:-}" in
  dev)
    # 1x00000000000000000000AA is Cloudflare's always-pass Turnstile TEST site key:
    # the dev form works end to end but gets no real bot protection.
    python3 build.py --preview --base /site/ --api-url https://q3.onlinebros.com \
      --set support.turnstile_site_key=1x00000000000000000000AA
    mkdir -p dev-www/site
    rsync -rl --delete dist/ dev-www/site/
    echo "Published dist/ to https://q3.onlinebros.com/site/"
    exit 0
    ;;
  preview) python3 build.py --preview; dest=/var/www/q3.life/preview/ ;;
  live)    python3 build.py;           dest=/var/www/q3.life/public/ ;;
  *)       echo "usage: $0 dev|preview|live" >&2; exit 2 ;;
esac

rsync -rlz --delete --chmod=D2775,F664 dist/ "$HOST:$dest"
echo "Published dist/ to $HOST:$dest"
