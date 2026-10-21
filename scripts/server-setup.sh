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
case "$DB_PASSWORD" in
  *\'*|*$'\n'*) die "the MySQL password contains a single quote or a line break, which .env cannot hold safely. In cPanel → MySQL® Databases → Current Users → $DB_USERNAME → Change Password, generate a new one and re-run." ;;
esac
# Prove the login works before anything is written. The values travel as
# environment variables, so no quoting can mangle them on the way.
DB_DATABASE="$DB_DATABASE" DB_USERNAME="$DB_USERNAME" DB_PASSWORD="$DB_PASSWORD" php -r '
  try {
    new PDO("mysql:host=127.0.0.1;port=3306;dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
    echo "database login ok: ".getenv("DB_USERNAME")."@127.0.0.1/".getenv("DB_DATABASE")."\n";
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
  }
' || die "MySQL refused that user/password for database $DB_DATABASE.
       Check in cPanel → MySQL® Databases that the user is listed under the
       database's Privileged Users, then set a fresh password there (Current
       Users → Change Password) and re-run with it. Pasting the password from
       a browser can also drop characters; retype it if in doubt."

say "3/8 checkout at $ROOT"
if [[ -f "$ROOT/artisan" ]]; then
  echo "already a checkout — leaving it as is"
elif [[ -d "$ROOT" ]]; then
  # cPanel creates the subdomain's document root as an empty folder, often
  # with only cgi-bin in it, and CloudLinux PHP Selector adds php.ini and
  # .user.ini for the domain's PHP settings. Anything else is somebody's
  # work: stop.
  LEFTOVER=$(find "$ROOT" -mindepth 1 -maxdepth 1 \
    ! -name cgi-bin ! -name .well-known ! -name .htaccess ! -name php.ini ! -name .user.ini | head -5)
  if [[ -n "$LEFTOVER" ]]; then
    die "$ROOT exists and is not empty:
$LEFTOVER
Move it aside (mv \"$ROOT\" \"$ROOT.old\") if it is not needed, then re-run."
  fi
  ASIDE="$ROOT.cpanel-default.$(date +%Y%m%d%H%M%S)"
  mv "$ROOT" "$ASIDE"
  echo "moved cPanel's default folder to $ASIDE"
  git clone --branch main "$REPO_URL" "$ROOT"
  # Keep the PHP Selector's settings: .user.ini applies to this directory
  # and everything below it, which includes the new public/ document root.
  for ini in php.ini .user.ini; do
    [[ -f "$ASIDE/$ini" ]] && cp -p "$ASIDE/$ini" "$ROOT/$ini" && echo "kept $ini"
  done
else
  git clone --branch main "$REPO_URL" "$ROOT"
fi
cd "$ROOT"
echo "HEAD: $(git rev-parse --short HEAD)"

say "4/8 composer install"
composer install --no-dev --optimize-autoloader --no-interaction

say "5/8 .env"
# Written by PHP rather than sed so no character in a value needs escaping.
# A fresh .env starts from .env.example; an existing one keeps everything it
# has (APP_KEY, the webhook secret, anything added by hand) and only the
# database values given to this run are replaced.
FRESH=0
if [[ -f .env ]]; then
  echo ".env exists — keeping it, updating the database values"
else
  cp .env.example .env
  FRESH=1
fi
SET_SECRET=""
grep -qE '^DEPLOY_WEBHOOK_SECRET=.{16,}' .env || SET_SECRET=$(openssl rand -hex 32)
FRESH="$FRESH" HOST="$HOST" DB_DATABASE="$DB_DATABASE" DB_USERNAME="$DB_USERNAME" DB_PASSWORD="$DB_PASSWORD" SET_SECRET="$SET_SECRET" php -r '
  $values = [
    "DB_CONNECTION" => "mysql",
    "DB_HOST" => "127.0.0.1",
    "DB_PORT" => "3306",
    "DB_DATABASE" => getenv("DB_DATABASE"),
    "DB_USERNAME" => getenv("DB_USERNAME"),
    "DB_PASSWORD" => getenv("DB_PASSWORD"),
  ];
  if (getenv("FRESH") === "1") {
    $values += [
      "APP_ENV" => "production",
      "APP_DEBUG" => "false",
      "APP_URL" => "https://".getenv("HOST"),
      "LOG_LEVEL" => "warning",
      "DEPLOY_ALLOWED_HOSTS" => getenv("HOST"),
    ];
  }
  if (getenv("SET_SECRET") !== "") {
    $values["DEPLOY_WEBHOOK_SECRET"] = getenv("SET_SECRET");
  }
  $lines = file(".env", FILE_IGNORE_NEW_LINES);
  foreach ($values as $key => $value) {
    // Single quotes: Laravel reads the value literally, so $ and \ are safe.
    $line = $key."=\x27".$value."\x27";
    $found = false;
    foreach ($lines as $i => $existing) {
      if (preg_match("/^#? ?".preg_quote($key, "/")."=/", $existing)) {
        $lines[$i] = $line;
        $found = true;
        break;
      }
    }
    if (! $found) {
      $lines[] = $line;
    }
  }
  file_put_contents(".env", implode("\n", $lines)."\n");
'
grep -qE '^APP_KEY=.+' .env || php artisan key:generate --force
echo ".env written"

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
echo "Log:         tail -f $ROOT/storage/logs/deploy.log"
echo "================================================================"
