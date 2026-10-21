#!/usr/bin/env bash
# Immediate pull + Laravel deploy for hijaz.rihla.mv.
#
# Used by:
#   - POST /api/deploy/pull   (GitHub Actions, after CI passes on main)
#   - scripts/self-update.sh  (cron fallback)
#
# Optional arg: the full SHA expected on origin/main. The script waits briefly
# for the tip to reach it and refuses to deploy anything else.
#
# The checkout is wherever this script lives, so the same file serves any
# path without editing: /home/<account>/hijaz.rihla.mv on cPanel.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export HOME="${HOME:-$(dirname "$ROOT")}"
export PATH="$HOME/bin:/usr/local/bin:/opt/cpanel/ea-php84/root/usr/bin:/opt/cpanel/composer/bin:/usr/bin:/bin:${PATH:-}"

LOCK="$HOME/.hijaz-deploy.lock"
EXPECTED_SHA="${1:-}"

log() { echo "$(date '+%F %T') $*"; }

for tool in php git composer; do
  command -v "$tool" >/dev/null || { log "$tool not found on PATH=$PATH"; exit 1; }
done

log "pull-deploy starting (root=$ROOT expected=${EXPECTED_SHA:-none})"

mkdir "$LOCK" 2>/dev/null || { log "deploy already in progress — skipping"; exit 0; }
trap 'rmdir "$LOCK"' EXIT

cd "$ROOT" || { log "cannot cd to $ROOT"; exit 1; }

git fetch origin main --quiet || { log "git fetch failed"; exit 1; }

if [[ -n "$EXPECTED_SHA" ]]; then
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    [[ "$(git rev-parse FETCH_HEAD)" == "$EXPECTED_SHA" ]] && break
    log "waiting for origin/main to reach ${EXPECTED_SHA:0:8}"
    sleep 2
    git fetch origin main --quiet || true
  done
  REMOTE=$(git rev-parse FETCH_HEAD)
  if [[ "$REMOTE" != "$EXPECTED_SHA" ]]; then
    log "origin/main tip ${REMOTE:0:8} != expected ${EXPECTED_SHA:0:8} — aborting (deploy only accepts main SHAs)"
    exit 1
  fi
fi

LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse FETCH_HEAD)

if [[ "$LOCAL" == "$REMOTE" ]]; then
  log "already on ${LOCAL:0:8} — nothing to deploy"
  exit 0
fi

log "deploying ${LOCAL:0:8} -> ${REMOTE:0:8}"
git merge --ff-only FETCH_HEAD || { log "fast-forward failed — manual attention needed"; exit 1; }

if git diff --name-only "$LOCAL" "$REMOTE" | grep -qE '^composer\.(lock|json)$'; then
  composer install --no-dev --optimize-autoloader --no-interaction \
    || { log "composer install failed"; exit 1; }
else
  # The optimised classmap maps class => file. A commit that deletes or
  # moves a class leaves it pointing at a file that is gone, and the page
  # fatals on the first class_exists(). Rebuild it every deploy.
  composer dump-autoload --optimize --no-interaction \
    || { log "dump-autoload failed"; exit 1; }
fi

php artisan storage:link --force >/dev/null 2>&1 || log "WARN: storage:link failed"

php artisan migrate --force \
  && php artisan config:cache \
  && php artisan route:cache \
  && php artisan view:clear \
  || { log "Laravel deploy steps failed"; exit 1; }

log "deploy complete: ${REMOTE:0:8}"
