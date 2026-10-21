# The staff portal

The portal is at https://hijaz.rihla.mv/portal. It is for Hijaz staff only;
pilgrims never sign in here. What it does today is phase 0 of
[PORTAL_PLAN.md](PORTAL_PLAN.md): accounts, roles, the audit trail,
reference data (islands, exchange rates, bank and cash accounts), settings,
and the Telegram bot. Trips and pilgrims come next.

## The front door

hijaz.rihla.mv is the staff portal. The address itself opens the sign-in
page, which lists the trips ahead (dates, hotels, from-price, seats left)
for anyone who looks; a signed-in person lands on Today. A customer-facing
website can take the address over later; the portal stays at `/portal`.

## Signing in

With an email address or a mobile number, and the password. A number can
be typed as 777 5123; the country code is added. Admin and Finance accounts
also type the six-digit code from their authenticator app. Each staff
account's number is set under Admin → Staff; the first admin can be given
one with `--phone=7775123` on the command below.

## The first account

Nothing can be done until one Admin exists. In cPanel → Terminal:

```bash
cd /home/rihla/hijaz
php artisan portal:admin you@example.com 'a password of at least 8 characters'
```

Then sign in at `/portal`. Admin and Finance are asked to set up an
authenticator app (Google Authenticator, Authy, 1Password) on first sign-in;
recovery codes are shown once. The same command gives an existing account the
Admin role, and is the way back in if every Admin is ever lost.

## Roles

| Role | Can |
| --- | --- |
| Admin | Everything, including staff accounts, settings and the audit trail. Needs an authenticator app. |
| Operations | Reference data, islands, trips, pilgrims and documents, bookings and recording payments, buses, rooms, visas, cards and lists. |
| Finance | Exchange rates, accounts, verifying and refunding payments, discounts, lists and the readiness board, and from phase 6 the ledger. Needs an authenticator app. |
| Registration | Islands (read), pilgrim registration and documents, bookings and recording payments, visa statuses, printing cards, lists. |
| Group leader | Checklist ticks, pilgrims and bookings (read), additional trips and recording the riyal paid for them, buses and rooms (read), the Scan page and bus check-in, lists. |
| Read only | Lists and the readiness board, no changes. |

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

**Ask.** `/today` (the morning summary), `/trip` and `/trip CODE` (seats,
hotels, money received, readiness), `/ready` (what blocks the next trip),
`/find A123456` or a name (a pilgrim with copies, booking and balance),
`/balance HJZ-B-2027-0001` (or an ID number or a name), `/slips` (payments
waiting, with buttons), `/checklist` (open items, tap to tick), `/rates`,
`/me`, `/unlink`, `/help`.

**Get PDFs.** `/bus 1`, `/room makkah` (add `hotel` for the hotel copy),
`/manifest airline` (shapes: office, airline, hotel, ministry), `/invoice
HJZ-B-2027-0001`, `/receipt HJZ-P-2027-0001`. Add a trip code for a trip
other than the next one. The file arrives in the chat to forward on.

**Get told.** Everyone linked who holds the permission hears when a payment
is recorded (Finance, with *Verify* and *Not received* buttons), when a copy
is read and waits for acceptance (with *Accept as read* unless a passport's
MRZ check failed), and when an ID card and passport disagree. The person who
caused it is not told. The morning summary at 08:00 Maldives time
(`TELEGRAM_DAILY_SUMMARY_AT`) lists what waits for that person: payments to
verify, holds about to lapse, readings to accept, copies to verify, overdue
checklist items and instalments, passports expiring inside the window.

**Act.** Every button is two taps: the first shows exactly what will happen,
the second does it as you, through the same rules as the portal, and the
message is rewritten with the result. *Not received* asks for the reason in
your next message. A payment in another currency than the balance is verified
in the portal, where the rate is applied.

**Upload.** Send a photo (or PDF) with a caption: `A123456 passport` stores a
copy for that pilgrim and reads it; `HJZ-B-2027-0001 10000` (currency
optional, MVR by default) records a bank slip against that booking as a
payment waiting for Finance, exactly as the portal would.

Alerts are queued and sent from the scheduler within a minute.

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

## Bookings and money

- **A booking** puts a customer's pilgrims on a trip's seats. Money →
  Bookings → New booking: pick the trip, the customer, then each traveller
  and the room they share. The price comes from the trip's price list by room
  and by age on the departure day (infant under 2, child under 12, else
  adult; a missing child price falls back to the adult one). A single room is
  a dollar line beside the rufiyaa fare, so a booking keeps a balance per
  currency.
- **Seats** are held the moment the booking is opened, for
  `BOOKING_HOLD_DAYS` (7). *Confirm booking* makes them firm; a draft nobody
  confirms is cancelled by the hourly `bookings:expire-holds` and the seats
  go back. *Hold the seats longer* extends a draft.
- **Charges** are lines: fares, room upgrades, fees and extras in any of the
  three currencies, and discounts as negative lines with a reason and an
  approver (Finance or Admin). Removing a line or taking back a discount
  leaves the trail.
- **Payments** are recorded (by Registration or Operations, from the booking's
  Payments tab) and then **verified by Finance** (Money → Payments). A
  transfer takes its slip; the slip is read and the amount, reference and
  remarks are compared with what was typed. *Upload a slip* on the Payments
  screen starts from the slip alone: the booking reference or an ID number
  in the remarks finds the booking. A payment is never edited: a wrong one
  is cancelled, a refused one is marked not received, and money going back
  is a refund, which is a negative payment with its own receipt.
- **Currencies.** A payment keeps its own currency. Verifying says which
  balance it settles; dollars paid against a rufiyaa balance use the
  exchange rate in force on the day paid (Money → Exchange rates) and the
  rate is kept on the allocation.
- **Invoices and receipts** are PDFs rendered when asked for, never stored:
  *Invoice* on the booking page (balances, bank accounts marked *show on
  invoices*, instalments), *Receipt* on a verified payment. Bank accounts
  come from Money → Accounts.
- **Additional trips** (Taif, Jeddah) are booked from the booking page for
  some or all of its travellers, priced in riyal, paid in Saudi Arabia, and
  have their own invoice and reference (`HJZ-X-…`).
- **Instalments** are optional dates and amounts on a booking; they appear on
  the invoice and the Today page flags any that fall behind.
- **Telegram.** `/balance HJZ-B-2027-0001` (or an ID number, or a name) says
  what a booking owes; `/slips` lists what waits for Finance; a recorded
  payment is announced to everyone who may verify.

## On the road: buses, rooms, lists and cards

- **Travellers** on the trip page lists everyone on a live booking, one row
  each, with bus, rooms, visa, copies and ticket. Select rows for the bulk
  actions: put on a bus, take off, put in a room, mark visa (with agent and
  date), add to an additional trip, print customer cards or luggage tags,
  export the selection as CSV. *Visa / ticket* on a row edits that person's
  ticket number, seat and visa details.
- **Buses**: add Bus 1, Bus 2… with a capacity and a leader (staff or a
  name and phone). *Add people* on a bus, or the bulk action above. The
  checker reports a bus over capacity, a bus with no leader, a family split
  across buses, and confirmed travellers on no bus.
- **Rooms** belong to a hotel stay: number, beds, and whether it is a
  men's, women's or family room. *Put people in* a room, or *Room a family*
  to put one booking into one family room (a new one is made if none has
  the beds). *Check rooming* reports over capacity, mixed gender in a
  non-family room, an undesignated room, a child with no adult of their
  family in the room, somebody in two rooms, confirmed travellers with no
  room, and anyone in a room with fewer beds than they paid for.
- **Readiness** (on the trip page) is the morning screen: what blocks the
  trip (visas not issued, passports expiring inside the window, missing
  passport copies, blocking checklist items) and what needs attention
  (unverified copies, ID-passport mismatches, late visas, missing tickets,
  balances due, bus and rooming problems, overdue checklist items). It is
  computed every time, never stored. A trip cannot be marked *travelling*
  while something blocks unless an Admin overrides with a reason, which is
  recorded on the transition. Today lists trips departing within 45 days
  that are not ready; `/ready` on Telegram says the same.
- **Lists** (on the trip page) prints or exports: the manifest in four
  shapes (office, airline, hotel, ministry), the payment status list, the
  documents-owed list, one list per bus with a tick column for boarding,
  one rooming list per hotel in an office copy and a hotel copy (no phone
  numbers or families), and participant lists per additional trip. PDF
  prints; CSV opens in a spreadsheet. Every print is audited.
- **Customer cards and luggage tags** carry the logo, trip, names in
  English and Dhivehi, bus, rooms, the office phones and a QR code. The QR
  holds a random token, never an ID number. Cards print eight to a sheet
  with the photo; tags two to a sheet in large print. Printing again reuses
  the same card; *reissue* revokes a lost one.
- **Scan** (menu, and the bottom bar for group leaders): scanning a QR with
  the phone's camera app opens the traveller here; the Camera button reads
  QR codes in Chrome on Android; the serial under the QR can be typed. The
  page shows bus, rooms, balance, visa and copies, and *Check in* marks the
  person on their bus.

## The books and the reports

- **Books** (Money → Books) is the single record of money: every rufiyaa,
  dollar and riyal in or out of an account (Money → Accounts: Bank MVR, Bank
  USD, the cash boxes, each group leader's riyal float). A verified pilgrim
  payment posts itself as income against its trip the moment Finance
  verifies it; a refund posts out. Finance records the rest with the four
  buttons: *Record income or expense* (category, trip or overhead,
  counterparty, reference, the receipt), *Transfer between accounts* (same
  currency: cash to the bank, a leader handing riyal back), *Buy currency*
  (MVR out, USD in, the bank's charges; the realised rate is kept on both
  entries and compared with the table rate), *Send a TT* (beneficiary, their
  bank, reference, charges, the advice scan). Nothing is edited: a wrong
  entry is *voided* with a reason and stays in the list.
- **MVR figures.** Every entry in another currency carries an MVR amount at
  the exchange rate in force on its date (Money → Exchange rates). When no
  rate is set for that date the entry has none, and every report says so
  instead of adding it in as nothing. Set the rate, and new entries use it;
  old entries keep the rate they were recorded under.
- **Reports** (Money → Reports, and *Reports* on a trip): pick the report
  and its filters; it shows on the page and prints as PDF or CSV. Trip
  profit and loss (income and expenses by category per currency, MVR
  totals, margin per traveller, what pilgrims still owe, the costs
  estimated on the trip); trip summary (seats, bookings, money, copies,
  visas, buses, rooms, readiness); yearly income and expenses (by month, by
  category, by trip with overheads, CSV for the accountant); cash book per
  account with a running balance; currency purchases with the exchange cost
  against the table rate; outstanding balances with ageing; payment
  collections by day and method; discounts with reasons and approvers;
  documents status and expiring passports; additional trips' participation
  and income; pilgrim demographics (gender, age bands, islands);
  verification audit (who verified which copy and payment).
- **Bank USD list.** On the trip's Travellers tab select the pilgrims and
  *Put on the bank USD list* with the amount each; Money → Reports → Bank
  USD list prints it with a cover letter on the letterhead, one row per ID
  number. Adjust the columns when the bank changes its form.
- Book categories are under Reference; new ones can be added, old ones
  deactivated, never deleted.

## On the ground and the customer's own page

- **Ground** (`/ground`, and the bottom bar for group leaders) is the
  leader's phone page: the trips they lead a bus on, their people with bus,
  rooms and a call button, head counts, incidents, and the riyal to collect
  for additional trips. Open it once with a signal and it keeps working
  without one: every tap is saved on the phone first and sent when the
  signal returns, in order, and a retry never makes a second record. The
  banner at the top says what is still waiting to send. The page clears
  its local copy when the person signs out.
- **Head counts**: *Start a count* at every move (boarding, lobby, airport),
  for one bus or the whole trip, then tap Here / Not here / Excused by each
  name. The phone's clock decides between two marks for the same person. The
  trip's *Head counts* tab in the portal shows each count and who is still
  unaccounted for, with their phone numbers.
- **Incidents**: raised from the ground page (or logged by the office under
  Trips → Incidents) with severity, category, who, where and what happened.
  Serious and emergency incidents go to everyone who may see incidents on
  Telegram at once. The office resolves them with what was done.
- **Riyal on the ground**: *Paid SAR …* beside each additional-trip booking
  records cash into that leader's own float account, verified at once; the
  leader hands the float to Finance after the trip as a transfer in the
  Books.
- **The customer's own page** (`/my`): *Link for the customer* on a booking
  makes a personal link to paste on WhatsApp or Viber, good for
  `SELF_SERVICE_LINK_DAYS` (30). With it the customer sees the trip and
  hotels, totals, what is paid and owed, the instalments, the bank accounts
  to pay into, their travellers and which copies are on file, and can send
  bank slips and missing copies from the phone. Everything they send
  arrives exactly as an upload in the portal would, marked channel `self`,
  read, and waiting for staff. *Cancel customer links* on the booking turns
  the links off.

## Settings in `.env`

| Key | Meaning |
| --- | --- |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME` | The bot. Empty switches it off. |
| `TELEGRAM_WEBHOOK_SECRET` | Optional; derived from the app key when unset. |
| `PORTAL_SECOND_FACTOR_REQUIRED` | `true` by default. `false` only for a first sign-in on a phone with no authenticator app yet, and back to `true` the same day. |
| `APP_TIMEZONE` | `Indian/Maldives` by default. |
| `BOOKING_HOLD_DAYS` | How long a draft booking keeps its seats. `7` by default. |
| `VISA_SLA_DAYS` | Days after a visa is submitted before the board calls it late. `7` by default. |
| `SELF_SERVICE_LINK_DAYS` | How long a customer's link to their own booking page works. `30` by default. |
| `INVOICE_TAX_NOTE`, `INVOICE_TERMS_NOTE` | Printed in the footer of invoices and receipts when set. |

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
