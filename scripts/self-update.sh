#!/usr/bin/env bash
# Cron fallback for the hijaz.rihla.mv auto-deploy.
#
# The fast path is the webhook: GitHub Actions calls POST /api/deploy/pull
# after CI passes on main (docs/AUTO_DEPLOY.md). This cron catches the case
# where the webhook never arrived. It deploys only a commit whose CI checks
# are all green, so it cannot put untested code on the site either.
#
# Install once:  bash scripts/install-self-update-cron.sh
# Watch:         tail -f ~/hijaz-deploy.log
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export HOME="${HOME:-$(dirname "$ROOT")}"
export PATH="$HOME/bin:/usr/local/bin:/opt/cpanel/ea-php84/root/usr/bin:/opt/cpanel/composer/bin:/usr/bin:/bin:${PATH:-}"

REPO="ampilarey/hijaz"
PULL="$ROOT/scripts/pull-deploy.sh"

# Every CI check that must be green before this commit may deploy. Keep in
# step with the job names in .github/workflows/ci.yml.
CHECKS=("Tests (PHP 8.3)" "Tests (PHP 8.4)" "Static analysis" "Committed build is current" "Dependency audit")

log() { echo "$(date '+%F %T') $*"; }

command -v git >/dev/null || { log "git not found on PATH"; exit 1; }
command -v curl >/dev/null || { log "curl not found on PATH"; exit 1; }

cd "$ROOT" || { log "cannot cd to $ROOT"; exit 1; }

git fetch origin main --quiet || { log "git fetch failed"; exit 1; }

LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse FETCH_HEAD)
[[ "$LOCAL" == "$REMOTE" ]] && exit 0

# Anonymous API — the repository is public.
for NAME in "${CHECKS[@]}"; do
  ENCODED=$(printf '%s' "$NAME" | sed 's/ /%20/g;s/(/%28/g;s/)/%29/g')
  ONE=$(curl -fsS -m 20 -H 'Accept: application/vnd.github+json' \
      "https://api.github.com/repos/${REPO}/commits/${REMOTE}/check-runs?check_name=${ENCODED}&per_page=5") \
      || { log "GitHub API unreachable — will retry next run"; exit 0; }
  if ! printf '%s' "$ONE" | grep -q '"total_count": *[1-9]'; then
    log "${REMOTE:0:8}: no result yet for ${NAME} — holding."
    exit 0
  fi
  if ! printf '%s' "$ONE" | grep -qE '"conclusion": *"success"'; then
    log "${REMOTE:0:8}: ${NAME} is not green — holding."
    exit 0
  fi
done

[[ -x "$PULL" ]] || chmod +x "$PULL" 2>/dev/null || true

exec bash "$PULL" "$REMOTE"
