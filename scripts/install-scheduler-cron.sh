#!/usr/bin/env bash
# Install (or refresh) the one-minute Laravel scheduler cron. Safe to re-run.
#
#   bash /home/<account>/hijaz/scripts/install-scheduler-cron.sh
#
# The scheduler is what runs queued jobs on cPanel (no long-running worker is
# possible there): routes/console.php runs queue:work --stop-when-empty once
# a minute.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="$(basename "$ROOT")"
LOG="$HOME/schedule-$NAME.log"

export PATH="$HOME/bin:/usr/local/bin:/opt/cpanel/ea-php84/root/usr/bin:/usr/bin:/bin:$PATH"

PHP="$(command -v php || true)"
[[ -n "$PHP" ]] || { echo "ERROR: php not found on PATH=$PATH"; exit 1; }
[[ -f "$ROOT/artisan" ]] || { echo "ERROR: $ROOT does not look like the application (no artisan)."; exit 1; }

CRON_LINE="* * * * * cd $ROOT && $PHP artisan schedule:run >> $LOG 2>&1"

CURRENT="$(crontab -l 2>/dev/null || true)"
FILTERED="$(printf '%s\n' "$CURRENT" | grep -vF "cd $ROOT && " | sed '/^$/d' || true)"

{
  [[ -n "$FILTERED" ]] && printf '%s\n' "$FILTERED"
  echo "$CRON_LINE"
} | crontab -

echo "✓ Scheduler cron installed for $ROOT (every minute):"
crontab -l | grep -F "cd $ROOT && "
echo ""
echo "  Log: tail -f $LOG"
