#!/usr/bin/env bash
# First-time server setup for hijaz.rihla.mv on cPanel. Run once, as the
# cPanel account user, in cPanel → Terminal (or over SSH). Safe to re-run:
# every step checks before it changes anything.
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/ampilarey/hijaz/main/scripts/server-setup.sh)
#
# Before running it, create the MySQL database and user in cPanel → MySQL®
# Databases (docs/SERVER_SETUP.md, step 2) and have the three values ready.
# Pass them as environment variables, or the script asks:
#
#   DB_DATABASE=rihla_hijaz DB_USERNAME=rihla_hijaz DB_PASSWORD='…' \
#     bash <(curl -fsSL https://raw.githubusercontent.com/ampilarey/hijaz/main/scripts/server-setup.sh)
#
# It will: clone the repository to $ROOT, install Composer dependencies,
# write .env, generate the app key and the deploy webhook secret, migrate,
# link storage, cache config, and install both crons. At the end it prints
# the webhook secret to paste into GitHub.
set -euo pipefail

export PATH="$HOME/bin:/usr/local/bin:/opt/cpanel/ea-php84/root/usr/bin:/opt/cpanel/composer/bin:/usr/bin:/bin:$PATH"

REPO_URL="${REPO_URL:-https://github.com/ampilarey/hijaz.git}"
ROOT="${ROOT:-$HOME/hijaz}"
HOST="${HOST:-hijaz.rihla.mv}"

say()  { echo; echo "==> $*"; }
die()  { echo "ABORT: $*" >&2; exit 1; }
ask()  { # ask VAR "prompt" [secret]
  local var="$1" prompt="$2" secret="${3:-}"
  if [[ -z "${!var:-}" ]]; then
    [[ -t 0 ]] || die "$var is not set and there is no terminal to ask on. Pass it as an environment variable."
    if [[ -n "$secret" ]]; then read -r -s -p "$prompt: " "$var"; echo; else read -r -p "$prompt: " "$var"; fi
  fi
  [[ -n "${!var:-}" ]] || die "$var is empty"
}

say "1/8 tools"
for tool in php git composer curl openssl; do
  command -v "$tool" >/dev/null || die "$tool not found on PATH=$PATH"
done
php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || die "PHP 8.3+ required, found $(php -v | head -1). Pick 8.4 in cPanel → MultiPHP Manager for $HOST first."
echo "php:      $(php -v | head -1)"
echo "composer: $(composer --version 2>/dev/null | head -1)"

say "2/8 database credentials"
ask DB_DATABASE "MySQL database name (e.g. rihla_hijaz)"
ask DB_USERNAME "MySQL user (e.g. rihla_hijaz)"
ask DB_PASSWORD "MySQL password" secret

say "3/8 checkout at $ROOT"
if [[ -f "$ROOT/artisan" ]]; then
  echo "already a checkout — leaving it as is"
elif [[ -d "$ROOT" ]]; then
  # cPanel creates the subdomain's document root as an empty folder, often
  # with only cgi-bin in it. Anything else is somebody's work: stop.
  LEFTOVER=$(find "$ROOT" -mindepth 1 -maxdepth 1 ! -name cgi-bin ! -name .well-known ! -name .htaccess | head -5)
  if [[ -n "$LEFTOVER" ]]; then
    die "$ROOT exists and is not empty:
$LEFTOVER
Move it aside (mv \"$ROOT\" \"$ROOT.old\") if it is not needed, then re-run."
  fi
  ASIDE="$ROOT.cpanel-default.$(date +%Y%m%d%H%M%S)"
  mv "$ROOT" "$ASIDE"
  echo "moved cPanel's empty default folder to $ASIDE"
  git clone --branch main "$REPO_URL" "$ROOT"
else
  git clone --branch main "$REPO_URL" "$ROOT"
fi
cd "$ROOT"
echo "HEAD: $(git rev-parse --short HEAD)"

say "4/8 composer install"
composer install --no-dev --optimize-autoloader --no-interaction

say "5/8 .env"
if [[ -f .env ]]; then
  echo ".env exists — keeping it (edit by hand if the database changed)"
else
  cp .env.example .env
  SECRET=$(openssl rand -hex 32)
  set_env() { # set_env KEY VALUE
    local key="$1" value="$2"
    value=${value//\\/\\\\}; value=${value//&/\\&}; value=${value//|/\\|}
    if grep -qE "^#? ?${key}=" .env; then
      sed -i -E "s|^#? ?${key}=.*|${key}=${value}|" .env
    else
      echo "${key}=${value}" >> .env
    fi
  }
  set_env APP_ENV production
  set_env APP_DEBUG false
  set_env APP_URL "https://$HOST"
  set_env LOG_LEVEL warning
  set_env DB_CONNECTION mysql
  set_env DB_HOST 127.0.0.1
  set_env DB_PORT 3306
  set_env DB_DATABASE "$DB_DATABASE"
  set_env DB_USERNAME "$DB_USERNAME"
  set_env DB_PASSWORD "\"$DB_PASSWORD\""
  set_env DEPLOY_WEBHOOK_SECRET "$SECRET"
  set_env DEPLOY_ALLOWED_HOSTS "$HOST"
  php artisan key:generate --force
  echo ".env written"
fi

say "6/8 database"
php artisan migrate --force
php artisan storage:link --force >/dev/null 2>&1 || echo "WARN: storage:link failed"

say "7/8 caches"
php artisan config:cache
php artisan route:cache
php artisan view:clear

say "8/8 crons"
chmod +x scripts/*.sh
bash scripts/install-scheduler-cron.sh
bash scripts/install-self-update-cron.sh

echo
echo "================================================================"
echo "Server setup complete. Two things remain, both outside this box:"
echo
echo "1. cPanel → Domains → $HOST → Document Root must be:"
echo "     $ROOT/public"
echo
echo "2. GitHub → ampilarey/hijaz → Settings → Environments → New: hijaz"
echo "   Add environment secret DEPLOY_WEBHOOK_SECRET with this value:"
echo
grep -E '^DEPLOY_WEBHOOK_SECRET=' .env | cut -d= -f2-
echo
echo "Then check:  curl -s https://$HOST/api/health"
echo "Log:         tail -f ~/hijaz-deploy.log"
echo "================================================================"
