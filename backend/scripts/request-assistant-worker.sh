#!/usr/bin/env bash
#
# The Request Assistant relay, on dev. Run it as the user the Claude CLI is
# logged in as (on dev, `ai`), never as www-data:
#
#   nohup backend/scripts/request-assistant-worker.sh >/dev/null 2>&1 &
#
# and from that user's crontab, so it survives a reboot:
#
#   @reboot /srv/workspaces/agent1/LionTraining/backend/scripts/request-assistant-worker.sh >/dev/null 2>&1
#
# It asks the live site for waiting turns, answers them on this server's
# Claude CLI and posts the answers back. The relay exits every hour
# (--max-time) and the loop starts it again, so code changes are picked up
# without anyone restarting it. flock keeps it to one copy.
#
# Stop it:  pkill -f request-assistant-worker.sh; pkill -f request-assistant:relay

set -u
cd "$(dirname "$0")/.." || exit 1
LOG=storage/logs/request-assistant-worker.log

exec 9>storage/app/request-assistant-worker.lock
flock -n 9 || exit 0

while true; do
    php artisan request-assistant:relay --max-time=3600 >>"$LOG" 2>&1
    sleep 5
done
