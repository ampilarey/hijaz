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

## Pilgrims and copies

- **Register with a name and a phone.** Everything else can come later. A
  pilgrim belongs to a customer: an individual is their own customer, a
  family member joins the family (Customers & families).
- **Scan the ID card** on the New pilgrim screen: the fields are filled in
  for you to check. If somebody with that ID number is already on file the
  portal says so. The scan is saved with the record as the ID card copy.
- **Copies** (ID card, passport, photo, visa, vaccination) are uploaded from
  the pilgrim's page, from the Copies queue, from a bulk upload, or by sending
  a photo to the Telegram bot with the ID number in the caption
  ("A123456 passport"). Every copy is encrypted on disk, versioned, and only
  reachable through a short-lived signed link that is audited.
- **Reading.** ID cards, passports, visas and (later) bank slips are read and
  the result is a proposal. *Accept reading* shows the scan beside the fields;
  what you save is what counts. A passport's machine-readable zone is checked
  by its own check digits; a failed check is flagged in red.
- **Verify.** Accepting a reading (Registration) and verifying a copy
  (Operations, Admin) are separate steps; an Operations user can do both at
  once. *Verify registration* on the pilgrim completes their registration.
- **ID card versus passport.** Once both are accepted the portal compares
  date of birth, gender and name and flags any difference until somebody
  explains it (*More → Explain a difference*).
- **Photos** are checked for size, shape and a plain background; problems are
  warnings, not blocks.
- **Visas in bulk.** Pilgrims → Visa uploads → Upload visas: a folder of
  files or one multi-page PDF, matched to pilgrims by passport number, then
  by name. Review, assign the unmatched ones, confirm. E-visa PDFs carry real
  text, so no OCR is needed for them.
- **Import** the old spreadsheet with `php artisan pilgrims:import file.csv
  --dry-run` (columns: name, id_number, date_of_birth, gender, phone,
  address, island, passport_number, passport_expires_on, family).

### Reading on the server (Tesseract)

The default reader is Tesseract, run as a binary on the server so no image
leaves it. On cPanel there is no package manager, so the binary is uploaded
by hand, once:

1. Download a static Tesseract build for Linux x86_64 (the AppImage from the
   Tesseract releases works: extract it with `--appimage-extract`), and the
   `eng.traineddata` model (plus `div.traineddata` for Dhivehi) from the
   tessdata repository.
2. Put the binary at `/home/rihla/bin/tesseract` (executable) and the models
   in `/home/rihla/tessdata/`.
3. In `.env`: `TESSERACT_BIN=/home/rihla/bin/tesseract`,
   `TESSDATA_PREFIX=/home/rihla/tessdata`, `TESSERACT_LANGS=eng` (or
   `eng+div`).
4. Check with `php artisan hijaz:preflight`: it reports whether the reader
   is available. Without it, uploads still work and staff type the fields.

Expect good results on passports (the machine-readable zone) and clean
scans, fair results on phone photos of ID cards, and poor results on Dhivehi.
If the correction rate is too high, `DOCUMENTS_READER=anthropic` with an
`ANTHROPIC_API_KEY` sends images to Claude's API instead, which reads far
better; that is the owner's call because the image leaves the server.

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
