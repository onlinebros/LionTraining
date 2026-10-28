#!/usr/bin/env bash
#
# The Proposal Studio worker, on dev. Run it as the user the Claude CLI is
# logged in as (on dev, `ai`), never as www-data, from that user's crontab:
#
#   @reboot     /srv/workspaces/agent1/LionTraining/backend/scripts/proposal-studio-worker.sh >/dev/null 2>&1
#   */5 * * * * /srv/workspaces/agent1/LionTraining/backend/scripts/proposal-studio-worker.sh >/dev/null 2>&1
#
# It answers turns waiting in this server's database. The command exits every
# hour (--max-time) and the loop starts it again, so code changes are picked
# up. flock keeps it to one copy, so the 5-minute entry only restarts a dead one.
#
# Find it:  ps -eo pid,args | grep "[p]roposal-studio"

set -u
cd "$(dirname "$0")/.." || exit 1
LOG=storage/logs/proposal-studio-worker.log

exec 9>storage/app/proposal-studio-worker.lock
flock -n 9 || exit 0

while true; do
    php artisan proposal-studio:work --max-time=3600 >>"$LOG" 2>&1
    sleep 5
done
