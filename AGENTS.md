# Working in this repository

Hijaz is a Laravel 13 (PHP 8.3+) application served from cPanel shared hosting
at https://hijaz.rihla.mv. It deploys itself from `main` (docs/AUTO_DEPLOY.md).

Hijaz Hajj & Umrah is its own company, not part of Rihla. The site is only
hosted on a rihla.mv subdomain, so `rihla` appears in server paths and the
domain but never in anything visitors see. Brand rules: docs/BRAND.md.

## Facts that shape every change

- **The host has no Node.** `public/build` is committed and CI fails when it is
  stale: run `npm run build` and commit the result with any CSS/JS change.
- **The host has no long-running processes.** Queued jobs run from cron through
  the scheduler (`routes/console.php`), so they may start up to a minute late and
  must be safe to run late and twice. No Redis, no websockets.
- **The deploy is `git pull` + `composer install` + `migrate --force`.** Every
  migration on `main` runs on the live database unattended. Make them
  reversible and test them on MySQL when they touch indexes or foreign keys.
- **Secrets never go in the repository.** `.env` lives only on the server;
  `scripts/server-setup.sh` writes it. The deploy webhook secret is in the
  server `.env` and the GitHub environment `hijaz`, nowhere else.
- **CI check names are load-bearing.** `scripts/self-update.sh` lists them and
  holds a deploy until each is green. Renaming a job in `ci.yml` means updating
  that list in the same commit.

## The portal (Filament 5 at /portal)

- **Filament's published assets are not committed.** `composer dump-autoload`
  runs `filament:upgrade`, which publishes them, on every deploy. The theme
  in `resources/css/filament/portal/theme.css` *is* built by Vite into
  `public/build`, so it follows the build rule above.
- **Permissions are verbs built by inclusion** (`app/Support/Access.php`).
  Admin holds none and gets everything through `Gate::before`. Add the verb
  to `PERMISSIONS`, give it to roles in `matrix()`, name the resource in the
  policy; the seeder runs on every deploy.
- **Money is `App\Support\Money`**: integer minor units beside a currency
  column, never floats, never converted without an `ExchangeRate` the record
  snapshots.
- **Anything holding money or identity goes in `AppServiceProvider::AUDITED`**
  and encrypts its identifiers with the `EncryptedIdentifier` cast.
- **Phone first.** Design at 390 px, widen with `min-width`; at most four
  table columns on a phone (`visibleFrom`); 44 px targets and 16 px inputs
  on touch screens. `MobileFirstLayoutTest` enforces the CSS side.
- **The deploy takes a backup before migrating and stops if it cannot**
  (`hijaz:backup` in `scripts/pull-deploy.sh`). A migration on `main` runs on
  real data, so make it reversible.
- **Factories run unguarded, forms do not.** A new column needs a test that
  writes it through the guarded path, or a Filament form will drop it silently.
- **The Telegram bot acts as the linked user.** Every handler runs with
  `Auth::setUser($link->user)` and `AuditContext::use(AuditContext::TELEGRAM)`;
  never answer a chat that has no active link beyond the one explanation.

## Before pushing

```bash
vendor/bin/pint --test && php artisan test && npm run build && git status --porcelain public/build
```

The last command must print nothing.
