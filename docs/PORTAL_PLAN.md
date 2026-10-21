# Hijaz Umrah Portal: the plan

**Status:** approved in direction by the owner on 2026-10-21 (see "Owner's
answers" at the end). Nothing in it is built yet.
**Date:** 2026-10-21, revised the same day after the owner's answers
**Reads:** `ampilarey/rihla` at `fde6cc9` (Rihla Travels' app), this repo at the commit this file was added in.

The portal is an internal tool for Hijaz Hajj & Umrah staff: it runs the Umrah
trips, the pilgrims on them, the money in and out, and the paperwork, from a
phone. It lives in this repository and deploys to https://hijaz.rihla.mv the
same way the placeholder page does today.

This document has six parts:

1. What Rihla already has, and what we take from it
2. The decisions that shape everything else
3. The domain model
4. Every requested feature, one by one, with how it will work
5. Features the owner did not list but should want
6. Build order, open questions, and engineering rules

---

## 1. What Rihla gives us

Rihla is a 2,372-test Laravel 13 / Filament 5 application with a full Umrah
operations core. Its code is tested, runs on the same cPanel account, and was
written under the same constraints (no Node, no queue worker, no Redis). Most of
the Hijaz portal is a port of it with the guesthouse marketplace, the public
website, the CMS and the learning academy left out.

| Area | Rihla has | For Hijaz |
| --- | --- | --- |
| Admin panel | Filament 5 at `/staff`, 40 resources, role matrix of 10 roles and ~130 permissions, policies, Super Admin via `Gate::before`, last-admin guard | **Port.** One panel at `/portal`, fewer roles. |
| Two-factor login | Hand-rolled TOTP, recovery codes, persistent `mfa` middleware | **Port as is.** |
| Trips | `packages` + `departures`, price tiers by occupancy and pax type, hotels, flights, transfers, itinerary, checklists, costs, seat capacity with a database constraint and row locks, waitlist | **Port and simplify** to one `trips` table (section 2, D-3). Flights, hotels, checklists, costs, capacity lock: port. |
| Bookings | `bookings` state machine, `booking_travellers`, signed `booking_lines` (seat, extra, discount, fee), snapshot of the package at booking time, transition log | **Port.** Add a staff "create booking" screen (Rihla has none) and per-pilgrim occupancy. |
| Customers | `customers` (payer) + `travellers`; one payer, many travellers; no login for travellers | **Port** as `customers` + `pilgrims`. Add family head, relationships, ID card fields, Dhivehi name. |
| Documents | Versioned, encrypted at rest, SHA-256 dedupe, signed 5-minute download links, audit row on every download, verify/reject with reason, 6-month passport window | **Port as is.** Add extraction (OCR) on top. |
| Payments | Polymorphic `payments`, integer minor units, bank-transfer slip vault (encrypted, checksummed, replace keeps history), `awaiting_review` → reconcile by Finance only, refunds as negative payments, paid total re-derived under a row lock | **Port as is.** Add slip reading, cross-currency settlement, discounts UI. |
| Invoices and receipts | dompdf, rendered on demand, Dhivehi font declared at both weights, brand colours only | **Port.** Add luggage tags, customer cards, bus lists, room lists, manifests (Rihla has none of these). |
| Rooming | `rooms` per hotel stay with capacity and gender, manual assignment, a checker that reports over-capacity, mixed gender, unaccompanied child, double-booked, unroomed | **Port.** Add a printable room list. |
| Visas and Nusuk permits | Per-pilgrim state machines with attempts, SLA, evidence documents, a Nusuk prerequisite gate | **Port the visa tracker** in a light form; Nusuk permits optional (section 5). |
| On the ground | Roll calls, incidents, operations log, announcements, emergency broadcasts, an offline-capable tour-leader page with an idempotent outbox | **Port roll calls and the leader page** in phase 7; the rest later. |
| Audit | Immutable `audit_logs` with redaction of passport and ID numbers | **Port as is.** |
| Privacy | `data:forget`, `data:anonymise`, retention report, every personal-data table classified | **Port as is.** |
| Ops | Production deploy script with backup gate, CI with committed-build check, Lighthouse budget, scheduler heartbeat, preflight command, `mysqldump` backup | Hijaz already has the deploy pipeline. **Port backup, preflight, scheduler heartbeat.** |
| Phone-first | Coarse-pointer CSS rules (44 px targets, 16 px inputs), `visibleFrom()` on table columns, a test that forbids desktop-first media queries | **Port and go further** (section 2, D-5). |

What Rihla does **not** have, verified by grep, and the portal needs:

- No OCR, MRZ reading or image text extraction of any kind.
- No ID-card-versus-passport cross-check; no ID number or passport number format validation.
- No family or guardian model; `relationship` is never filled.
- No bus or coach groups.
- No optional excursions on a booking; `extra` lines exist as a type only.
- No discount UI, codes or approval.
- No exchange-rate table; a payment must be in the booking's currency.
- No income/expense ledger beyond per-trip cost lines; no yearly report; no CSV or Excel money export.
- No manifests, bus lists, room lists, luggage tags, badges or cards.
- No Telegram, no SMS provider, no real email sending (`mail.default` is `log`).
- No staff-created bookings, no traveller editing screen.
- No passport-photo specification.

Everything in that list is new work in this plan.

---

## 2. Decisions

### D-1. A separate application in this repository, ported from Rihla

Hijaz is a different company with its own data, staff and bank accounts; the
only thing shared with Rihla is the hosting account and the `rihla.mv`
subdomain (confirmed by the owner). It
will not be a tenant inside Rihla (Rihla's own ADRs defer multi-tenancy and its
tenant model is for guesthouse hosts), and a shared Composer package between
two repos with one owner would cost more than it saves. Code is copied module
by module, with its tests, and adapted. Where Hijaz later improves something,
Rihla can copy it back.

### D-2. Filament 5 at `/portal`, one panel, staff only

Rihla's ADR 0003 explains the costs (32 packages, inline-script CSP exception,
committed assets, `filament:upgrade` on composer dump) and they are paid
already. The pilgrim-facing side, if it comes, is a separate plain-Blade area
(like Rihla's portal) at `/my`, not a second Filament panel.

### D-3. One `trips` table, not package + departure

Rihla splits the product (`packages`) from its dated run (`departures`) because
it sells the same package many times and publishes it on a website. Hijaz runs
a few trips a year, each priced and hotelled individually, by staff on phones.
One `trips` row per trip, with a **"Copy this trip"** action that duplicates
last year's set-up with shifted dates, is simpler to use and to build. If
Hijaz later needs a public catalogue, a `packages` layer can be added above
without touching bookings.

### D-4. Money: integer minor units, three currencies, each used where Hijaz really uses it

Every amount is an integer in minor units (laari, cents, halalas) beside a
3-letter currency column, as in Rihla. Three currencies are first-class:
**MVR, USD, SAR**, and the owner described exactly how each is used:

| Money | Currency | Where |
| --- | --- | --- |
| The trip package price | MVR | Paid in the Maldives, bank transfer or cash at the office |
| A private room (single occupancy) upgrade | USD | Paid by the pilgrim in USD |
| Additional trips from Makkah or Madinah (Taif, Jeddah, Ziyarah) | SAR | Paid in cash in Saudi Arabia, to the group leader |
| Hijaz buying USD with MVR at the bank | MVR out, USD in | Company side, before the trip |
| Telegraphic transfers to hotels, airlines, agents | USD or MVR out | Company side |
| Expenses on the ground | SAR | Paid from SAR cash carried by staff |

So the model has to carry more than one currency on one booking, and more
than one currency in one company account book. The rules:

- **A booking line has its own currency.** The package lines are MVR, a
  private-room line is USD. The booking keeps a balance **per currency**
  ("MVR 28,500 and USD 300 due"), and the invoice prints them as separate
  totals. Nothing is added across currencies on an invoice.
- **A payment keeps its own currency and amount forever** and is allocated to
  the booking's balance in that same currency by default. When a pilgrim pays
  MVR for the USD room (or USD for the MVR package), Finance allocates it
  across currencies at that moment and the rate used is snapshotted on the
  allocation. The paid total per currency is re-derived from allocations under
  a row lock (Rihla's ledger pattern).
- **Excursion money is SAR, collected in Saudi.** Excursion bookings are SAR
  payables; the group leader records cash received on a phone (offline-capable,
  synced later), the pilgrim gets a receipt, and the SAR goes into the leader's
  cash account until it is handed over to Finance.
- **The company's books are a multi-currency cash book.** There is an
  **account** for every place money sits: bank MVR, bank USD, office cash MVR,
  office cash USD, each leader's SAR cash on a trip. Every ledger entry names
  its account. A **currency purchase** (USD bought with MVR) is one entry that
  moves money out of one account and into another at the *actual* rate the
  bank gave; a **TT** is an expense entry out of the bank USD or MVR account
  with the beneficiary, charges and reference.
- **Two kinds of rate.** The `exchange_rates` table (staff-entered, dated) is
  only for reporting and for allocating a pilgrim's cross-currency payment.
  The company's own purchases record the rate they really got, and reports show
  both: what the bank charged Hijaz, and what the trip cost in MVR at that
  realised rate. No rate is ever fetched automatically and no stored conversion
  is ever recalculated when a rate changes.
- **MVR is the reporting base.** Every report shows each currency on its own
  and an MVR total, and prints the rates it used.

### D-5. Phone first, measured

Every screen is designed at 390 px wide first. Rules, enforced by tests where
Rihla already has them:

- No `max-width` media queries; 44 px touch targets; 16 px inputs.
- Tables show at most four columns on a phone; the rest open in the record.
- Every list has a search box at the top and a single primary action.
- Uploads use the camera (`capture="environment"`) by default.
- Printables are A4 PDFs, but every list is also a phone-readable page.
- A bottom navigation bar with the five things staff do most: Today, Trips,
  Pilgrims, Money, Scan.
- The on-the-ground pages (bus check-in, room list, roll call) work offline and
  sync later, copied from Rihla's tour-leader outbox.
- Telegram is the fastest phone interface of all and gets the same features
  where they fit a chat (section 4, item 21).

### D-6. Reading documents on the server first, never trusted, cloud only as an optional upgrade

The owner asked for OCR **on the server** rather than sending images to a
cloud model. That is possible, with limits that should be understood up front.

**What the server can and cannot run.** This is cPanel shared hosting: no
root, no `apt install`, no Python, no OpenCV. The one OCR engine that can run
there is **Tesseract as a self-contained binary** uploaded into the account's
home directory and called from PHP (the deploy webhook already proves the
account may run processes). Tesseract has an English model, a Dhivehi (Thaana)
model and an OCR-B model for passport machine-readable zones. Image
preparation (crop, rotate, threshold, contrast) is done in PHP with GD or
Imagick, which the host has.

**What to expect from it, honestly:**

| Document | On-server Tesseract | Why |
| --- | --- | --- |
| Passport MRZ (the two lines at the bottom) | Good | Designed to be machine read; check digits confirm the result |
| Bank slips and banking-app screenshots | Good | Clean rendered text |
| ID card: ID number, dates, gender | Fair to good | Printed Latin text and digits in known positions |
| ID card: English name, address | Fair | Depends on photo quality; glare and angle hurt |
| ID card: Dhivehi name and address | Poor to fair | The Thaana model exists but is weak; expect corrections |
| Passport visual zone (name as printed) | Fair | The MRZ carries the same name, which is read well |

Reading is a **proposal** either way. The result is stored in
`document_extractions` with the raw text, the reader, per-field confidence,
and `status = proposed`. Staff see the image and the fields side by side,
correct, and accept; only then does anything reach the pilgrim's record, and
only then is the document `verified`. Passports are also checked by the MRZ
check digits computed locally; a failed digit is flagged before a human looks.
If the engine is missing or fails, the same screen works with empty fields.

**The reader is an interface with two implementations.** `TesseractReader`
runs on the server and no image leaves it. `ClaudeVisionReader` sends the
image to Anthropic's API and reads every field, including Dhivehi, markedly
better. The portal ships with the on-server reader as the default and the
cloud reader switched off. If, after a few weeks of real ID cards, the
correction rate is too high, the owner can turn the cloud reader on for ID
cards only (a config switch and an API key), with a line in the pilgrim data
notice. That is the owner's call, and the plan does not assume it.

**Phase 2 starts with a feasibility check on the live server**: upload the
Tesseract binary and models, run it on ten real ID cards and passports, and
measure the field accuracy before building the screens around it. If the
binary cannot run on this account (CloudLinux CageFS sometimes blocks
non-standard binaries), the fallback is manual entry with the cloud reader
offered as the only automatic option, and the owner decides.

### D-7. Telegram through a webhook, acting as the linked staff member

Telegram needs an HTTPS URL to call; the site has one. No long-running process
is needed. A staff member links their account by opening "Link Telegram" in the
portal, getting a one-time code, and sending `/start CODE` to the bot. From
then on every command runs **as that user, with that user's permissions**, and
is written to the audit log with `channel = telegram`. Money and verification
actions in the bot need a second tap to confirm. The bot is built on Laravel's
HTTP client against the Bot API directly; no bot framework package.

### D-8. Same host, same constraints

Rihla's ADR 0002 applies unchanged: database queue woken by cron, no Redis,
no websockets, PDFs rendered synchronously, jobs idempotent and tolerant of
running late. Tesseract runs synchronously inside the upload action (one to
five seconds per image) with a "Read again" button, and the queue, woken by
cron, is the fallback when a read takes longer.

---

## 3. Domain model

Field lists are indicative; migrations are written per phase. `*_minor` is an
integer in minor units with a `currency` beside it. Every table has timestamps;
tables with history have `created_by`.

### People

- **`customers`**: the paying party, individual or family.
  `id`, `type` (`individual`|`family`), `name` (family name or the person's name),
  `head_pilgrim_id` (the person who signs and pays), `phone`, `phone_alt`,
  `email`, `address`, `island_id`, `notes`, `source` (how they heard of Hijaz).
- **`pilgrims`**: every person who travels. One row per human, reused across
  years.
  `customer_id`, `relationship_to_head` (`self`|`spouse`|`child`|`parent`|`sibling`|`relative`|`other`),
  `name_en`, `name_dv` (Thaana, from the ID card), `gender`, `date_of_birth`,
  `id_number` (encrypted, `A` + 6 digits), `id_expires_on`, `address`,
  `island_id`, `atoll_code`, `nationality` (default `MV`),
  `passport_number` (encrypted), `passport_issued_on`, `passport_expires_on`,
  `passport_issuing_country`, `phone`, `emergency_contact_name`,
  `emergency_contact_phone`, `medical_notes`, `needs` (JSON: wheelchair, elderly
  companion, dietary), `photo_document_id`, `registration_status`
  (`registered`|`documents_pending`|`verified`), `verified_by`, `verified_at`,
  `identity_mismatches` (JSON, see item 9). A pilgrim is `registered` as soon
  as a name and phone are typed; documents can come weeks later (item 23).
- **`islands`**: master list, seeded. `atoll_code` (`K`, `GDh`…), `atoll_name`,
  `name`, `name_dv`. Used to resolve the island from an ID card address.
- **`users`**: staff. Ported from Rihla with TOTP columns and `telegram_chat_id`.

### Documents and reading

- **`documents`** and **`document_versions`**: ported. `type` is one of
  `id_card`, `passport`, `photo`, `vaccination`, `visa`, `bank_slip`, `other`.
  `documentable` is polymorphic (pilgrim or payment). A visa document also
  carries `visa_number`, `valid_from`, `valid_until`, `trip_id` (item 24).
- **`document_batches`**: a bulk upload of visas or other copies.
  `trip_id`, `type`, `uploaded_by`, `file_count`, `matched_count`; with
  **`document_batch_items`** (`batch_id`, `document_version_id`,
  `matched_pilgrim_id`, `match_reason` (`passport_number`|`name`|`manual`),
  `status` (`matched`|`unmatched`|`assigned`|`discarded`)).
- **`document_extractions`**: `document_version_id`, `reader`
  (`tesseract`|`anthropic`|`manual`), `model`, `fields` (JSON of proposed
  values), `confidence` (JSON per field), `raw_response` (JSON or text),
  `mrz_valid` (nullable bool), `status`
  (`proposed`|`accepted`|`rejected`), `reviewed_by`, `reviewed_at`,
  `accepted_fields` (JSON of what staff actually saved). Append-only.

### Trips

- **`trips`**: `code` (`UMR-2027-07`), `name` ("July 2027 Umrah"),
  `departs_on`, `returns_on`, `capacity_total`,
  `capacity_held`, `capacity_confirmed` (with Rihla's database constraint),
  `status` (`draft`|`open`|`closed`|`travelling`|`completed`|`cancelled`),
  `route_summary` (derived, e.g. "MLE → JED → Makkah → Madinah → MED → MLE"),
  `leader_user_id`, `notes`.
- **`price_tiers`**: ported. `trip_id`, `occupancy` (`single`|`double`|`triple`|`quad`|`quint`),
  `pax_type` (`adult`|`child`|`infant`), `amount_minor`, `currency` (MVR for
  the package tiers; the single-room upgrade is a separate `room_upgrade`
  tier in USD).
- **`trip_flights`**: ported. `direction` (`outbound`|`connecting`|`return`),
  `airline`, `flight_number`, `from_airport`, `to_airport`, `departs_at`,
  `arrives_at`, `pnr`, `seats`, `notes`.
- **`trip_hotels`**: `city` (`makkah`|`madinah`|`jeddah`), `name`, `address`,
  `distance_to_haram_m`, `check_in_on`, `check_out_on`, `nights`,
  `booking_reference`, `contact_name`, `contact_phone`, `room_types` (JSON),
  `notes`. The Madinah stay dates are this row's check-in and check-out.
- **`trip_stops`**: the route, ordered. `sort_order`, `city`, `arrives_on`,
  `departs_on`, `trip_hotel_id`, `transport` (`flight`|`bus`|`train`), `notes`.
- **`trip_excursions`**: the additional trips inside the trip. `title`
  (`Makkah Ziyarah`, `Taif Ziyarah`, `Jeddah trip`), `city`, `scheduled_on`,
  `is_included` (free with the package), `price_minor`, `currency` (SAR),
  `capacity`, `provider`, `cost_minor` (what Hijaz pays the bus company),
  `notes`.
- **`trip_checklist_items`**, **`trip_costs`**: ported.

### Bookings and money

- **`bookings`**: ported. `reference` (`HJZ-B-2027-0001`), `trip_id`,
  `customer_id`, `status` (`draft`|`confirmed`|`cancelled`|`completed`),
  `seats`, `totals` (JSON per currency: `{"MVR": 2850000, "USD": 30000}`),
  `paid` (JSON per currency, re-derived from allocations), `trip_snapshot`
  (JSON), `notes`. The old single `currency` column is gone: a booking is
  mostly MVR with USD lines when a private room is sold.
- **`booking_pilgrims`**: ported from `booking_travellers`. `pilgrim_id`,
  `occupancy`, `pax_type`, `price_tier_id`, `amount_minor`, `currency`,
  `is_lead`, `bus_group_id`, `ticket_number`, `visa_status`, `visa_number`.
- **`booking_lines`**: ported. `type` (`seat`|`room_upgrade`|`extra`|`discount`|`fee`),
  signed `amount_minor`, **`currency`** (MVR for seats, USD for a private
  room), `booking_pilgrim_id`, `trip_excursion_id`, `discount_id`.
- **`discounts`**: `booking_id`, `kind` (`fixed`|`percent`), `value`,
  `currency`, `reason`, `approved_by`, `approved_at`, `line_id`. A discount
  is a negative booking line in one currency with a reason and an approver.
- **`excursion_bookings`**: a separate SAR payable for paid extras so they
  have their own invoice and payment trail. `reference` (`HJZ-X-2027-0001`),
  `trip_excursion_id`, `customer_id`, `currency` (SAR), `total_minor`,
  `paid_minor`, `status`, `collected_by` (the leader's user id); with
  **`excursion_participants`** (`booking_pilgrim_id`, `amount_minor`).
- **`payments`**, **`payment_transactions`**: ported (polymorphic over
  `bookings` and `excursion_bookings`). A payment has one `currency` and
  `amount_minor`, a `method` (`bank_transfer`|`cash`|`card`), an `account_id`
  (which Hijaz account the money went into), a `slip_extraction_id`, and a
  `channel` (`portal`|`telegram`|`leader`|`import`).
- **`payment_allocations`**: `payment_id`, `currency`, `amount_minor`,
  `rate_snapshot` (null when the allocation is in the payment's own
  currency), `allocated_by`. The sum of a payment's allocations, converted at
  their snapshots, equals the payment. A booking's `paid` per currency is the
  sum of allocations in that currency.
- **`exchange_rates`**: `from`, `to`, `rate` (decimal, 8 places), `as_of`,
  `source`, `set_by`. Staff-entered, for reporting and cross-currency
  allocation only.

### Finance

- **`accounts`**: every place money sits. `name`, `kind`
  (`bank`|`cash`|`leader_cash`), `currency`, `bank`, `account_name`,
  `account_number` (encrypted), `holder_user_id` (for a leader's SAR float),
  `is_active`. Seeded: Bank MVR, Bank USD, Office cash MVR, Office cash USD;
  a leader SAR account is created per trip and leader.
- **`ledger_entries`**: every rupee, dollar and riyal in or out.
  `kind` (`income`|`expense`|`transfer`|`exchange`), `category_id`, `trip_id`
  (nullable: overheads), `happened_on`, `description`, `counterparty`,
  `account_id`, `currency`, `amount_minor` (signed: in is positive),
  `mvr_minor` (at the dated table rate, for reporting), `rate_snapshot`,
  `reference` (bank or TT reference), `payment_id` (when the income is a
  verified pilgrim payment, posted automatically), `document_id` (receipt,
  TT advice or exchange slip scan), `recorded_by`.
  - A **currency purchase** is two entries sharing an `exchange_group`: MVR
    out of Bank MVR and USD into Bank USD, with the realised rate stored on
    both and the bank's charges as a third, expense entry.
  - A **TT** is an expense entry out of a bank account with the beneficiary,
    their bank, the reference and the charges, and a scan of the TT advice.
  - A **cash handover** (a leader returning SAR after a trip, or the office
    moving MVR cash to the bank) is a `transfer`: out of one account, into
    another, same currency.
- **`ledger_categories`**: `kind`, `name`, `sort_order`. Seeded: package
  sales, room upgrades, excursion sales, other income; airfare, Makkah hotel,
  Madinah hotel, transport, excursion costs, visa fees, Nusuk, meals, staff,
  bank charges and TT fees, exchange loss, office, other.
- **`budgets`** (phase 6): per trip and category, planned `amount_minor` and
  `currency`.

### Operations

- **`bus_groups`**: `trip_id`, `name` ("Bus 1"), `capacity`, `leader_user_id`,
  `leader_phone`, `colour`, `notes`. Members via `booking_pilgrims.bus_group_id`.
- **`rooms`**, **`room_assignments`**: ported. A room belongs to a `trip_hotel`.
- **`roll_calls`**, **`roll_call_marks`**: ported (phase 7).
- **`cards`**: issued customer cards and luggage tags. `booking_pilgrim_id`,
  `kind` (`customer`|`luggage`), `serial`, `qr_token`, `issued_at`,
  `printed_count`.

### Platform

- **`audit_logs`**: ported, with `channel`.
- **`telegram_links`** (`user_id`, `chat_id`, `linked_at`, `revoked_at`) and
  **`telegram_updates`** (`update_id` unique, `received_at`, `handled_at`) so a
  retried webhook delivery never runs twice.
- **`settings`**: company details for documents, photo spec, passport window.

---

## 4. The requested features, one by one

Numbers match the owner's list.

### 1. A staff portal for trips, pilgrims, money and reports

Filament 5 panel at `/portal`, phone first (D-2, D-5). Navigation: **Today**
(what needs doing: slips to verify, extractions to accept, passports expiring,
unpaid balances due, checklist items overdue), **Trips**, **Pilgrims**,
**Money**, **Reports**, **Settings**. Login with email and password plus
two-factor for Admin and Finance (ported).

Roles, built by inclusion like Rihla's matrix:

| Role | Can |
| --- | --- |
| Admin | Everything, including users and settings. |
| Operations | Trips, pilgrims, documents and verification, buses, rooms, lists, cards, excursions. Sees money but cannot verify payments or apply discounts. |
| Finance | Verify and refuse payments, refunds, discounts, ledger, rates, bank accounts, all money reports. |
| Registration | Register pilgrims, upload documents, accept extractions. No money. |
| Group leader | Their bus only: list, roll call, incidents, from a phone, offline. |
| Read only | Reports and lists, no changes. |

### 2. Create an upcoming Umrah trip

"New trip" is a short form (name, dates, price currency, seats), then a trip
page with sections the staff fill in any order, each a card on a phone:

- **Flights**: outbound and return legs with airline, flight number, times, PNR.
- **Makkah hotel** and **Madinah hotel**: name, address, distance to the Haram,
  check-in and check-out, nights, booking reference, contact. Jeddah hotel
  optional.
- **Route**: the ordered stops, generated from the flights and hotels and
  editable. The Madinah dates are the Madinah stop.
- **Prices**: a tier per occupancy and pax type (double adult, triple adult,
  quad adult, child, infant) in MVR, plus the private-room upgrade in USD.
  Entered in whole units, stored in minor units.
- **Additional trips**: Makkah Ziyarah (included), Taif Ziyarah (priced),
  Jeddah trip (priced), each with date, price in SAR, capacity.
- **Checklist**, **Costs**, **Buses**, **Rooms** (items 11 to 13).
- **Copy this trip**: duplicates everything but bookings, shifting dates.

Trip status moves `draft` → `open` (taking registrations) → `closed` →
`travelling` → `completed`, with `cancelled` from any open state. Seats are
protected by Rihla's row lock and database constraint, so two staff members
cannot sell the last seat twice.

### 3. Register a pilgrim from an ID card

Flow on a phone:

1. **Scan**: tap "New pilgrim", point the camera at the ID card (or pick a
   file). The image is encrypted and stored as a `document` of type `id_card`.
2. **Read**: the on-server reader (D-6) returns `name_en`, `name_dv`, `id_number`,
   `gender`, `date_of_birth`, `address`, `issued_on`, `expires_on`, each with
   a confidence. The address is matched against the `islands` list to propose
   the island and atoll.
3. **Check**: the ID number must match `^A\d{6}$`; the date of birth must be a
   real date in the past; a pilgrim with the same ID number already on file is
   shown ("Is this the same person?") so duplicates are merged, not created.
4. **Confirm**: staff see the card image above the fields, fix anything, and
   tap "Verify". The pilgrim becomes `verified`, the document `verified`, and
   the extraction `accepted` with what was actually saved. Low-confidence
   fields are highlighted first.

Nothing is saved to the pilgrim from an unaccepted reading (D-6). If the
reader is unavailable the fields are empty and staff type them; the reading
can be retried later from the document. Scanning is a shortcut, not a
requirement: a pilgrim can be registered with no documents at all and the
copies attached later (item 23).

### 4. Individuals and families

A `customer` is either an individual (one pilgrim, relationship `self`) or a
family (a head plus members with relationships). "Add family member" scans the
next ID card into the same family. A booking is made for a customer, so a
family books together, pays together and gets one invoice, while every pilgrim
still has their own documents, room, bus and card. A member can later be moved
to another family (a married child) without losing history.

### 5. Payments from a slip, read automatically, verified by Finance

1. Staff (or later the pilgrim) upload the bank transfer slip or a screenshot
   of the banking app, against a booking. Stored in Rihla's encrypted slip
   vault. Office cash is recorded without a slip, against the cash account.
2. The on-server reader (D-6) reads `amount`, `currency`, `paid_on`,
   `reference`, `payer_name`, `payer_account`, `payee_account`, `remarks`.
3. The portal proposes a match: the payee account must be one of Hijaz's
   accounts; the remark or reference is searched for a booking reference or a
   pilgrim ID number; the amount is compared with the balance in that
   currency. The payment is created `awaiting_review` with the proposal.
4. **Finance verifies** ("The money is in" / "It is not there", ported). The
   payment is allocated to the booking's balance in its own currency; when a
   pilgrim paid MVR for the USD room, Finance allocates across currencies at
   the rate in force and the rate is snapshotted (D-4). The booking's paid
   totals are re-derived under a row lock. A verified payment posts an income
   ledger entry into the account it was paid into.
5. **Payment slip**: a receipt PDF (ported) with Hijaz's details, the amount
   in the paid currency, how it was allocated, and the remaining balance per
   currency. Shareable as a signed link, sent by Telegram, or printed.

Duplicate detection: the same slip bytes are refused; the same bank reference
already verified is flagged.

### 6. Discounts

On a booking, Finance (or Admin) adds a discount: fixed amount or percentage,
reason required, approver recorded. It becomes a negative booking line, shows
on the invoice as its own row, and appears in the discount report. Discounts
cannot exceed the booking total, and a confirmed booking's discount can only
be added or reversed, never silently changed.

### 7. Passport reading and the six-month rule

Same scan-read-check-confirm flow as the ID card. The reader reads the visual
zone and the two MRZ lines; the MRZ **check digits are recomputed locally** for
the passport number, date of birth, expiry and the composite digit. A failed
check digit is a red flag on the screen before anyone verifies. Fields:
`surname`, `given_names`, `passport_number`, `nationality`, `date_of_birth`,
`gender`, `issued_on`, `expires_on`, `issuing_country`.

Validity rule: the passport must expire at least **six months after the
trip's return date** (configurable, default 6, measured from `returns_on`).
It is checked when the passport is verified against every trip the pilgrim is
booked on, and again daily by the sweep, so a passport that was fine for a
March trip is flagged when the pilgrim is added to a December one. Rihla
measures from departure; this plan measures from return, which is what the
Saudi rule actually asks.

### 8. Passport-size photo

Upload or camera capture per pilgrim. Checks on upload: file type, minimum
resolution, aspect ratio within tolerance of the configured spec, file size,
and a plain-background estimate (border pixels mostly light). The spec
defaults to the Saudi e-visa photo requirement and is editable in Settings
(**Q-6**: the owner confirms the exact spec their visa agent needs). The photo
is a `document` of type `photo`, versioned, and is what the customer card
prints.

### 9. ID card versus passport cross-check

After both documents are verified, the portal compares:

- Date of birth: must be identical.
- Gender: must be identical.
- Name: the ID card's English name against the passport's surname and given
  names, after uppercasing, removing punctuation and comparing as sets of
  words, so "MOHAMED AHMED" and "AHMED, MOHAMED" agree. A similarity score
  below the threshold is a mismatch.
- Nationality: Maldivian ID with a non-MV passport is a note, not a block.

Mismatches are stored on the pilgrim (`identity_mismatches`) and shown as a
red concern on the pilgrim, on the trip's readiness board, in the Today list
and in a Telegram alert to Operations. Staff resolve by correcting a document
reading or marking the mismatch "explained" with a note (name changes are
common).

### 10. Luggage tags and customer cards

Both are PDFs from one template layout (Rihla's dompdf shell with the Hijaz
brand), with the logo, trip name, pilgrim name in English and Dhivehi, bus
group, room (if assigned), Hijaz contact numbers, and a QR code holding a
signed token. Customer cards print the photo. Luggage tags print large, with
two per sheet and a cut line. A sheet can be generated for one pilgrim, a bus,
or the whole trip, and sent to Telegram as a PDF. Scanning the QR with the
portal's **Scan** tab opens the pilgrim: bus check-in, room, balance,
documents.

### 11. Bus groups

A trip has bus groups with a capacity and a leader. Pilgrims are added to a
bus from the trip page or in bulk ("select pilgrims, assign to Bus 2").
Families are kept together by default and a warning shows when they are
split. The checker reports over-capacity, unassigned pilgrims and split
families, the same way Rihla's rooming checker does.

### 12. Rooms in Makkah and Madinah

Rihla's rooming is ported: rooms per hotel stay with capacity and
male/female/family designation, manual assignment, and a checker for
over-capacity, mixed gender, unaccompanied children, double booking and
unroomed pilgrims. Added: room type and the pilgrim's booked occupancy are
compared (a quad-paying pilgrim in a double is a concern), and "Room the
family" puts a family into one room in one tap.

### 13. Bus list, trip list, room list

Each is a printable A4 PDF and a phone page, generated on demand:

- **Trip list (manifest)**: every pilgrim with ID number, passport number and
  expiry, date of birth, gender, phone, family, bus, rooms, payment status.
  Columns are chosen per use (airline, hotel, ministry) with presets.
- **Bus list**: per bus, with leader, phone, and a tick column for check-in.
- **Room list**: per hotel, room by room, with a copy for the hotel without
  phone numbers.
- Also: excursion participant lists, a payment status list, and a document
  status list (who still owes a passport or photo).

### 14. Extra trips, paid in Saudi riyal on the ground

From the trip's excursions, "Add participants" picks pilgrims (a family at
once). For included excursions it is just a list. For priced ones it creates
an `excursion_booking` in SAR for the customer with the participants as lines
and an invoice PDF with its own reference.

Payment happens in Saudi Arabia, in cash, to the group leader: the leader's
phone page (offline-capable, synced when there is signal) lists participants
with "Paid SAR 150" buttons; each tap records a cash payment into that
leader's SAR account, already verified because the leader is the one holding
the money, and the pilgrim gets a receipt by Telegram or WhatsApp link, or a
printed one later. After the trip the leader hands the SAR over to Finance,
recorded as a transfer, and the excursion report shows collected, handed over
and any difference. Invoices and receipts are shared as a signed link
(WhatsApp paste), a Telegram message, or print.

### 15. Bank USD list

A report of pilgrims on a trip with the columns the bank requires for its USD
allocation to Umrah pilgrims (name, ID number, passport number, amount in
USD, and whatever else the owner supplies, **Q-5**), as PDF and CSV, with a
cover letter on Hijaz letterhead. Which pilgrims are included, and the
amount per pilgrim, are set per trip. Rows are deduplicated by ID number.

### 16. Income, expenses and yearly reports

The `ledger_entries` table (section 3) is the single record of money, across
the accounts in section 3. Verified pilgrim payments post into it
automatically; staff record everything else with a scan of the evidence:

- **Buying USD**: amount of MVR paid, USD received, the bank's charges; the
  realised rate is computed and shown, and the exchange slip is attached.
- **Sending a TT**: account, currency, amount, beneficiary and their bank,
  reference, charges, which trip and category it is for, the TT advice scan.
- **Ground expenses in SAR**: recorded by the leader on the phone page against
  their SAR float, with a receipt photo, synced later.
- **Cash handovers** between leaders, the office and the bank.

Screens:

- **Trip profit and loss**: income by category, expenses by category, margin,
  per pilgrim, in each currency and in MVR at the dated rates (Rihla's
  `JourneyProfit` logic, extended to the ledger).
- **Yearly report**: calendar or fiscal year, income and expenses by month and
  category, all trips plus overheads, MVR total, CSV export for the
  accountant.
- **Cash book** per account and currency with running balance, including
  each leader's SAR float during a trip.
- **Currency purchases**: every USD purchase with its realised rate against
  the table rate, and the exchange cost per trip.
- **Outstanding balances**: who owes what, by trip, with ageing.

### 17. Three currencies

MVR, USD and SAR everywhere money appears, each used where Hijaz uses it
(D-4): MVR for packages, USD for private rooms and TTs, SAR for excursions and
ground expenses. The rate table is a Finance screen; the current rate shows on
every conversion; every stored conversion remembers its rate; the company's
own USD purchases record the rate the bank really gave. Nothing is ever
re-converted when a rate changes.

### 18. Trip reports

The first set, each a PDF and a CSV, with a date range and a trip filter:

- Trip summary (seats sold, income, outstanding, documents complete, buses
  and rooms settled)
- Payment collections by day and method
- Discounts given, with reasons and approvers
- Documents status and expiring passports
- Excursion participation and income
- Pilgrim demographics (island, age bands, gender) for planning
- Verification audit (who verified which document and payment, when)

The owner's detailed report list (**Q-4**) is added to this section when it
arrives; the report framework (filters, PDF, CSV, scheduling a report to
Telegram) is built once in phase 6.

### 19. Everything useful from Rihla

Section 1 is the inventory. The port happens phase by phase (section 6), with
Rihla's tests coming along and being adapted, which is how we know the port is
right.

### 20. Other important features

Section 5.

### 21. Telegram bot for admins

Webhook-driven (D-7). Linked staff can:

- **Get told**: a new slip is waiting for Finance; an extraction is waiting
  for acceptance; an identity mismatch was found; a passport will expire
  within the window for a booked trip; a booking balance is due; a checklist
  item is overdue; a daily 08:00 summary of the Today list.
- **Ask**: `/find A123456` or `/find Ahmed` shows a pilgrim card with status
  and balance; `/trip` lists open trips; `/trip UMR-2027-07` shows seats,
  money and readiness; `/bus 2` or `/room makkah` sends the list as PDF;
  `/balance HJZ-B-2027-0012`; `/rates`.
- **Act**, with a confirmation tap: verify or refuse a payment (Finance),
  accept an extraction (Registration), tick a checklist item, send a receipt
  PDF to a given number as a file to forward.
- **Upload**: send a slip photo to the bot with a booking reference in the
  caption; it is stored, read and queued for verification exactly as if
  uploaded in the portal.

Everything the bot does is audited as that user with `channel = telegram`.
Unknown chats get one message explaining how to link, and nothing else.

The owner wants Telegram connected early, so the bot goes live in **phase 0**
with linking, `/start`, `/help` and a daily summary, and grows a command or an
alert with every phase that follows rather than waiting for phase 5. It needs
a bot token from @BotFather in the server `.env` (Q-9).

### 22. Mobile first

D-5, applied to every item above.

### 23. Register first, attach copies later

Many pilgrims register by phone or at the counter with nothing but a name and
a number, and bring the ID card and passport weeks later. So:

- "New pilgrim" needs only a name and a phone. Everything else, including the
  ID number and date of birth, is optional at that moment. The pilgrim is
  `registered` and can be put on a trip and take a booking straight away.
- The pilgrim's page shows a **document checklist**: ID card, passport,
  photo, visa, each either missing, uploaded, or verified. Any copy can be
  added at any time, from the pilgrim's page, from the trip's document list,
  from a bulk upload (item 24), or by sending it to the Telegram bot with the
  pilgrim's ID number in the caption.
- When a copy arrives, the reader proposes the fields and staff accept them
  into the record exactly as in item 3, with one addition: if the pilgrim
  already has a typed ID number or date of birth that disagrees with the
  scan, both values are shown and staff choose.
- The trip's readiness board and the Today list count who is still missing
  what, by trip, ordered by departure date. A trip cannot move to
  `travelling` with unverified passports unless an Admin overrides it, with
  the override recorded.
- Nothing is blocked by a missing document except what truly needs it: a
  manifest for the airline prints a blank passport column rather than
  refusing to print.

### 24. Visa copies and bulk actions

**One at a time.** A visa is a document of type `visa` on the pilgrim,
linked to the trip it is for. Uploading one reads the visa number, the
passport number, the name and the validity dates; staff confirm. The
pilgrim's visa status on that trip becomes `issued`, the dates are checked
against the trip (a visa that expires before the return is flagged), and the
copy prints with the pilgrim's documents when needed.

**In bulk.** Visas usually arrive from the agent all at once, as a folder of
PDFs or one multi-page PDF. "Upload visas" on the trip takes many files or a
multi-page PDF, splits it into one document per page, reads each, and
**matches it to a pilgrim by passport number** (then by name if the number
did not read). The result is a review screen: matched rows with the pilgrim's
name beside the visa image, and an unmatched list that staff assign by
picking the pilgrim. Nothing is attached until staff confirm the batch;
confirmed rows become verified visa documents, and the batch is kept so a
mistaken match can be undone.

**Bulk actions everywhere lists appear.** Select pilgrims on a trip, a bus,
or a search result, then:

- attach a document type to each from a batch (visas, photos, vaccination
  certificates);
- assign to a bus group or a room;
- mark visa, ticket or vaccination status;
- generate customer cards or luggage tags for the selection;
- add the selection to an excursion;
- export the selection to CSV or a manifest preset;
- send a Telegram or WhatsApp message to each (a prepared link per person
  until a sending channel exists).

Every bulk action reports what it did per row and what it skipped and why,
and writes one audit entry per affected pilgrim.

---

## 5. Other important features

Ranked by how much trouble they prevent. The first group belongs in the first
release; the second in the months after.

**Should be in the first release**

1. **Visa tracking per pilgrim** beyond the copy itself (item 24): status
   (`not_started` → `submitted` → `issued` | `rejected`), the agent used,
   submission date, and an SLA alert when a submitted visa is late. A trip
   cannot move to `travelling` with unissued visas unless overridden. Ported
   from Rihla's visa desk in a lighter form.
2. **Ticket numbers per pilgrim.** Airline ticket number and seat on the
   booking pilgrim; the manifest prints them; the airline list preset uses
   them.
3. **Readiness board per trip.** One screen (Rihla's `DepartureReadiness`,
   ported) that lists what blocks departure: unpaid balances, unverified
   documents, expiring passports, identity mismatches, unroomed or unbussed
   pilgrims, missing visas, overdue checklist items. This is the screen the
   owner opens every morning.
4. **Instalment plans and reminders.** A booking can carry a payment schedule
   (deposit by date, balance by date). Overdue instalments show on Today and
   go to Telegram. Rihla has the `deposit_minor` column but no schedule.
5. **Cancellations and refunds with policy.** A cancellation records the
   reason, the refundable amount by the trip's policy, and the refund as a
   negative payment (ported). Seats return to the pool.
6. **Emergency contact and medical needs** on every pilgrim, printed on the
   leader's bus list but not on the hotel copy.
7. **Duplicate and merge.** Match on ID number and passport number at
   registration; a merge tool for the duplicates that still happen.
8. **Import existing pilgrims** from the spreadsheet Hijaz uses today
   (Rihla's `CustomerImport` pattern: dry run, match on ID number, never
   auto-merge).
9. **Audit of every verification and every rupee** (ported), viewable by
   Admin, with the redaction rules for ID and passport numbers.
10. **Backups** before every deploy and nightly (ported `rihla:backup` and
    verify), kept off the web root, pruned.
11. **Privacy basics**: a short data notice pilgrims sign at registration
    (stored with date), encrypted ID and passport numbers, encrypted
    documents, signed short-lived download links, `data:forget` for a pilgrim
    who asks, a retention rule (**Q-8**).

**Soon after**

12. **Pilgrim self-service at `/my`** by one-time link (Rihla's portal
    pattern): see the trip, upload a passport or slip, see the balance,
    download a receipt. No passwords.
13. **WhatsApp and SMS sending.** Today the only channel is a pasted link.
    A WhatsApp Business API or an SMS gateway (Dhiraagu, Ooredoo) turns
    reminders and receipts into sent messages. Needs an account the owner opens.
14. **Roll calls, incidents and the offline leader page** on the ground
    (ported), so the bus leader marks attendance at every move and the office
    sees who is unaccounted for.
15. **Supplier records**: hotels, bus companies, the visa agent, with
    contacts, contracts, deposits paid and balances due, feeding trip costs.
16. **Mahram and relationship rules** as warnings: a woman under the
    configured age without a mahram on the same booking is flagged, never
    blocked.
17. **Vaccination certificates** (meningitis, polio) as a document type with
    expiry, required or not per trip.
18. **Waitlist** when a trip is full (ported), with an offer that holds seats
    for a day.
19. **Budget versus actual** per trip, set when the trip is created.
20. **Nusuk permits** (ported) if Hijaz handles permits itself rather than
    through an agent (**Q-7**).
21. **Dhivehi interface** for staff screens and pilgrim documents, using
    Rihla's translation tooling; English first.

---

## 6. Build order, questions, rules

### Phases

Each phase ends deployed to hijaz.rihla.mv behind the staff login, with tests,
so the owner can use it on a phone and redirect the next phase. Sizes are
relative: S is a day or two of focused work, M a few days, L a week or more.

| # | Phase | Delivers | Size |
| --- | --- | --- | --- |
| 0 ✓ | Foundation | Filament at `/portal`, roles and permissions, TOTP, audit log, settings, islands list, exchange rates, accounts, phone-first shell and bottom nav, backup and preflight commands, **Telegram bot live**: webhook, linking, `/help`, daily summary | L |
| 1 ✓ | Trips | Trip create and copy, flights, hotels, route, prices, excursions, checklist, costs, capacity lock, trip status | M |
| 2 ✓ | Pilgrims and documents | **Tesseract feasibility check on the live server first**, then customers and families, pilgrims registered with just a name and phone, encrypted document wallet, document checklist per pilgrim, ID card, passport and visa reading with accept screens, bulk visa upload with passport-number matching, MRZ check, photo checks, six-month rule, identity cross-check, duplicates, import | L |
| 3 ✓ | Bookings and money | Staff-created bookings for individuals and families, MVR lines and USD room upgrades, discounts, slip upload and reading, Finance verification, per-currency balances and allocations, invoices and receipts, SAR excursion bookings and invoices, instalments, cancellations and refunds | L |
| 4 ✓ | Operations | Bus groups, rooms, checkers, readiness board, manifests, bus and room lists, luggage tags and customer cards with QR, the Scan tab, visa tracker, ticket numbers, bulk actions on every pilgrim list | L |
| 5 | Telegram, full | Alerts for every queue, queries, confirmed actions, slip upload by chat, PDFs by chat | M |
| 6 | Finance and reports | Accounts and ledger, currency purchases, TTs, leader SAR floats and handovers, trip P&L, yearly report, cash book, outstanding balances, USD bank list, the report framework and the first report set, CSV | L |
| 7 | On the ground | Offline leader page, roll calls, incidents, pilgrim self-service link | M |

Phase 0 and 1 together give the owner a trip they can look at within the
first stretch of work; phase 2 is where the portal starts replacing paper.
A tick marks a phase that is built, tested and deployed; docs/PORTAL.md says
how to use what each one delivered.

### Questions for the owner

| # | Question | Needed by |
| --- | --- | --- |
| Q-1 | Who are the staff and which role does each get? (Names and emails; MFA for Admin and Finance.) | Phase 0 |
| Q-2 | ~~Trip prices and currencies~~ **Answered**: packages in MVR; private room in USD; excursions in SAR paid in Saudi; the company buys USD with MVR and sends TTs in USD and MVR. Still open: whether child and infant prices exist. | Phase 1 |
| Q-3 | ~~Cloud reading of documents~~ **Answered**: the owner wants OCR on the server. Default is on-server Tesseract (D-6); the cloud reader stays off unless the owner turns it on later. | Phase 2 |
| Q-4 | The detailed list of trip reports (item 18). | Phase 6 |
| Q-5 | The bank USD list format and the bank's rules (item 15). | Phase 6 |
| Q-6 | The exact passport-photo spec the visa agent requires. | Phase 2 |
| Q-7 | Does Hijaz obtain visas and Nusuk permits itself, or through a Saudi agent? | Phase 4 |
| Q-8 | How long to keep ID and passport scans after a trip (retention), and whether the accountant needs a specific export format. | Phase 2 |
| Q-9 | **Telegram approved.** Still needed: a bot token created with @BotFather by the owner and put in the server `.env` as `TELEGRAM_BOT_TOKEN`, never in the repo or in chat. | Phase 0 |
| Q-10 | Hijaz's bank accounts (bank, account name and number, currency) for payment instructions and slip matching. | Phase 3 |
| Q-11 | ~~Hostname~~ **Answered**: hijaz.rihla.mv, a subdomain of rihla.mv, with everything else separate from Rihla. | Phase 0 |

### Engineering rules carried over from Rihla

Written into this repo's `AGENTS.md` as each becomes relevant.

- Integer minor units and a currency column, always; `Money` is the only
  converter; no floats.
- Status changes go through `transitionTo()` and write an append-only event
  row; exceptions name the illegal move.
- Readiness and checkers are computed on read, never stored.
- Rules with numbers (passport months, photo spec, instalment days) live in
  config and Settings, not in code.
- Nothing allocates people automatically; the code reports problems and a
  person decides.
- Every personal-data table is classified for anonymise and forget before it
  is merged; every secret stays in the server `.env`.
- Migrations write literals, never constants; drop a foreign key, then its
  index, then the column, in separate calls; test on MySQL when touching keys.
- Factories run unguarded, forms do not: every new column gets a test through
  the guarded path.
- Name the files for Pint; run static analysis before every push; there are
  no expected test failures.
- Phone-first CSS with coarse-pointer rules, and the test that enforces it.
- PDFs use the shared shell, brand colours by constant only, Thaana declared
  at both weights.

### Owner's answers (2026-10-21)

1. Hijaz is a separate company, not related to Rihla; only the hosting
   subdomain is shared. (D-1)
2. Trip payments are in MVR. A private room may be paid in USD. All additional
   trips from Makkah or Madinah are paid there, in Saudi riyal. The company
   buys USD with MVR and sends TTs in USD and MVR. (D-4, section 3, items 5,
   14, 16, 17)
3. OCR should run on the server. (D-6: on-server Tesseract by default, cloud
   reader off, feasibility check first in phase 2)
4. Telegram: yes, connect it. (D-7, item 21, phase 0)
5. A pilgrim can be registered without an ID card or passport; the copies are
   attached later. (item 23)
6. A visa copy can be attached to each pilgrim, and in bulk. (item 24)
