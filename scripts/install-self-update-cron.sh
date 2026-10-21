#!/usr/bin/env bash
# Install (or refresh) the one-minute auto-deploy fallback cron. Safe to re-run.
#
#   bash /home/<account>/hijaz/scripts/install-self-update-cron.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT="$ROOT/scripts/self-update.sh"
LOG="$HOME/hijaz-deploy.log"
CRON_LINE="* * * * * $SCRIPT >> $LOG 2>&1"

export PATH="$HOME/bin:/usr/local/bin:/opt/cpanel/ea-php84/root/usr/bin:/opt/cpanel/composer/bin:/usr/bin:/bin:$PATH"

for cmd in git php composer curl; do
  command -v "$cmd" >/dev/null || { echo "ERROR: '$cmd' not found on PATH=$PATH"; exit 1; }
done

chmod +x "$ROOT"/scripts/*.sh

cd "$ROOT"
git fetch origin main --quiet || { echo "ERROR: git fetch origin main failed — check the remote URL"; exit 1; }

CURRENT="$(crontab -l 2>/dev/null || true)"
FILTERED="$(printf '%s\n' "$CURRENT" | grep -vF "$SCRIPT" | sed '/^$/d' || true)"

{
  [[ -n "$FILTERED" ]] && printf '%s\n' "$FILTERED"
  echo "$CRON_LINE"
} | crontab -

echo "✓ Auto-deploy fallback cron installed (every minute):"
crontab -l | grep -F "$SCRIPT"
echo ""
echo "  Server HEAD : $(git rev-parse --short HEAD)"
echo "  origin/main : $(git rev-parse --short FETCH_HEAD)"
echo "  Log         : tail -f $LOG"
