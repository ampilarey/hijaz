# The staff portal

The portal is at https://hijaz.rihla.mv/portal. It is for Hijaz staff only;
pilgrims never sign in here. What it does today is phase 0 of
[PORTAL_PLAN.md](PORTAL_PLAN.md): accounts, roles, the audit trail,
reference data (islands, exchange rates, bank and cash accounts), settings,
and the Telegram bot. Trips and pilgrims come next.

## The first account

Nothing can be done until one Admin exists. In cPanel → Terminal:

```bash
cd /home/rihla/hijaz
php artisan portal:admin you@example.com 'a password of at least 12 characters'
```

Then sign in at `/portal`. Admin and Finance are asked to set up an
authenticator app (Google Authenticator, Authy, 1Password) on first sign-in;
recovery codes are shown once. The same command gives an existing account the
Admin role, and is the way back in if every Admin is ever lost.

## Roles

| Role | Can |
| --- | --- |
| Admin | Everything, including staff accounts, settings and the audit trail. Needs an authenticator app. |
| Operations | Reference data, islands, and from phase 1 trips, pilgrims and documents. |
| Finance | Exchange rates, accounts, and from phase 3 payments and the ledger. Needs an authenticator app. |
| Registration | Islands (read), and from phase 2 pilgrim registration and documents. |
| Group leader | From phase 4, their bus only. |
| Read only | Reports and lists, no changes. |

Roles are given on the Staff screen. Deactivating an account keeps it and its
history but lets nobody sign in with it. The last Admin cannot be deleted or
demoted.

The matrix lives in `app/Support/Access.php` and is seeded on every deploy
(`php artisan db:seed --force`), so a permission removed from the matrix is
revoked on the next deploy.

## Telegram

1. In Telegram, open **@BotFather**, send `/newbot`, name it ("Hijaz Portal")
   and give it a username ending in `bot`.
2. Put the token in the server's `.env` as `TELEGRAM_BOT_TOKEN=…` and the
   username (without the `@`) as `TELEGRAM_BOT_USERNAME=…`. Never in the repo.
3. The next deploy registers the webhook. To do it by hand:
   `php artisan telegram:webhook` (`--show` prints what Telegram has).
4. Each staff member opens **Telegram** in the portal menu, taps *Get a link
   code*, and opens the link on their phone. From then on the bot acts as
   them, with their permissions, and everything it does is in the audit
   trail with channel `telegram`.

Commands today: `/today`, `/me`, `/unlink`, `/help`. A morning summary goes to
every linked staff member at 08:00 Maldives time (`TELEGRAM_DAILY_SUMMARY_AT`).

## Backups, preflight, scheduler

- `php artisan hijaz:backup` dumps the database to `storage/app/backups/`,
  gzipped, keeping the last ten. The deploy runs it before every migration
  and refuses to migrate if it fails. A nightly copy runs at 02:30.
- `php artisan hijaz:backup:verify` checks the newest dump is whole.
- `php artisan hijaz:preflight --production` says whether the install is fit
  to serve: database, roles seeded, build and Filament assets present,
  storage writable, production settings, scheduler alive, bot configured.
- The scheduler needs the cron line from `scripts/install-scheduler-cron.sh`
  (once, in cPanel Terminal). Without it nothing runs on a timer and
  preflight warns.

## Settings in `.env`

| Key | Meaning |
| --- | --- |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME` | The bot. Empty switches it off. |
| `TELEGRAM_WEBHOOK_SECRET` | Optional; derived from the app key when unset. |
| `PORTAL_SECOND_FACTOR_REQUIRED` | `true` by default. `false` only for a first sign-in on a phone with no authenticator app yet, and back to `true` the same day. |
| `APP_TIMEZONE` | `Indian/Maldives` by default. |

## Conventions for the code

- Permissions are verbs (`rate.update`), listed in `Access::PERMISSIONS`, and
  role sets are built by inclusion. Admin holds none and gets everything
  through `Gate::before`.
- Every policy uses `ChecksPermissions` and names its resource. A resource
  without a `delete` verb cannot be deleted from any screen.
- Money is `App\Support\Money`: integer minor units beside a currency,
  never floats, never converted without an `ExchangeRate` the record keeps.
- Models holding money or identity are in `AppServiceProvider::AUDITED`.
  Identifiers (`account_number`, later `id_number` and `passport_number`)
  use the `EncryptedIdentifier` cast and are redacted in the trail.
- Screens are designed at 390 px. Tables show at most four columns on a
  phone (`visibleFrom('sm'|'md')`); the rest open in the record. The theme in
  `resources/css/filament/portal/theme.css` is built by Vite and committed.
