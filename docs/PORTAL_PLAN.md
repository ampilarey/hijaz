# Hijaz Umrah Portal: the plan

**Status:** proposal for the owner to approve. Nothing in it is built yet.
**Date:** 2026-10-21
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

Hijaz is a different company with its own data, staff and bank accounts. It
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

### D-4. Money: integer minor units, three currencies, MVR as the reporting base

Every amount is an integer in minor units (laari, cents, halalas) beside a
3-letter currency column, as in Rihla. Three currencies are first-class:
**MVR, USD, SAR**. Each trip has a price currency. A booking inherits it.

Rihla refuses a payment in another currency. Hijaz cannot: pilgrims pay MVR
for USD-priced trips and hand over USD cash. So:

- `exchange_rates` holds dated rates (`from`, `to`, `rate`, `as_of`, `source`,
  `set_by`). Staff enter them; nothing is fetched automatically.
- A payment keeps its own currency and amount forever. When Finance verifies
  it, the rate in force is copied onto the payment (`rate_snapshot`) and a
  `settled_minor` in the booking's currency is stored. The booking's paid total
  is the sum of `settled_minor`.
- Reports show each currency separately **and** an MVR total using the rate on
  the transaction date. The rate used is always printed.

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

### D-6. OCR by a vision model, never trusted

ID cards, passports, bank slips and photos are read by **Claude's vision API**
(Rihla already has an `AnthropicProvider` with key, model, daily cap and
timeout config; it is extended to send images). Reading is a proposal:

- The result is stored in `document_extractions` with the raw response, the
  model, per-field confidence, and `status = proposed`.
- Staff see the image and the proposed fields side by side, correct them, and
  accept. Only then do the fields reach the pilgrim's record, and only then is
  the document `verified`. Nothing is written from an unaccepted extraction.
- Passports are additionally checked by the **MRZ check digits**, computed
  locally. A passport whose MRZ fails its own checksum is flagged before a
  human looks at it.
- If the API is down or no key is set, the same screen works with empty
  fields: manual entry is the fallback, not a separate path.

Sending an ID card image to a cloud model means personal data leaves the
server. **This needs the owner's explicit decision (Q-3)**, a line in the
privacy notice pilgrims sign, and the API's no-training terms. The
alternative, local OCR, is not realistic on cPanel (no Tesseract, no Python).

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
running late. Vision API calls run synchronously inside the upload action
(3 to 10 seconds) with a "Read again" button, and fall back to the queue when
the API is slow.

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
  (`draft`|`awaiting_verification`|`verified`), `verified_by`, `verified_at`,
  `identity_mismatches` (JSON, see item 9).
- **`islands`**: master list, seeded. `atoll_code` (`K`, `GDh`…), `atoll_name`,
  `name`, `name_dv`. Used to resolve the island from an ID card address.
- **`users`**: staff. Ported from Rihla with TOTP columns and `telegram_chat_id`.

### Documents and reading

- **`documents`** and **`document_versions`**: ported. `type` is one of
  `id_card`, `passport`, `photo`, `vaccination`, `visa`, `bank_slip`, `other`.
  `documentable` is polymorphic (pilgrim or payment).
- **`document_extractions`**: `document_version_id`, `reader` (`anthropic`|`manual`),
  `model`, `fields` (JSON of proposed values), `confidence` (JSON per field),
  `raw_response` (JSON), `mrz_valid` (nullable bool), `status`
  (`proposed`|`accepted`|`rejected`), `reviewed_by`, `reviewed_at`,
  `accepted_fields` (JSON of what staff actually saved). Append-only.

### Trips

- **`trips`**: `code` (`UMR-2027-07`), `name` ("July 2027 Umrah"),
  `departs_on`, `returns_on`, `price_currency`, `capacity_total`,
  `capacity_held`, `capacity_confirmed` (with Rihla's database constraint),
  `status` (`draft`|`open`|`closed`|`travelling`|`completed`|`cancelled`),
  `route_summary` (derived, e.g. "MLE → JED → Makkah → Madinah → MED → MLE"),
  `leader_user_id`, `notes`.
- **`price_tiers`**: ported. `trip_id`, `occupancy` (`double`|`triple`|`quad`|`quint`),
  `pax_type` (`adult`|`child`|`infant`), `amount_minor`, `currency`.
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
  `is_included` (free with the package), `price_minor`, `currency`,
  `capacity`, `provider`, `notes`.
- **`trip_checklist_items`**, **`trip_costs`**: ported.

### Bookings and money

- **`bookings`**: ported. `reference` (`HJZ-B-2027-0001`), `trip_id`,
  `customer_id`, `currency`, `status` (`draft`|`confirmed`|`cancelled`|`completed`),
  `seats`, `total_minor`, `paid_minor`, `trip_snapshot` (JSON), `notes`.
- **`booking_pilgrims`**: ported from `booking_travellers`. `pilgrim_id`,
  `occupancy`, `pax_type`, `price_tier_id`, `amount_minor`, `is_lead`,
  `bus_group_id`, `ticket_number`, `visa_status`, `visa_number`.
- **`booking_lines`**: ported. `type` (`seat`|`extra`|`discount`|`fee`),
  signed `amount_minor`, `booking_pilgrim_id`, `trip_excursion_id` (for extras),
  `discount_id`.
- **`discounts`**: `booking_id`, `kind` (`fixed`|`percent`), `value`,
  `reason`, `approved_by`, `approved_at`, `line_id`. A discount is a negative
  booking line with a reason and an approver.
- **`excursion_bookings`**: a separate payable for paid extras so they have
  their own invoice and payment trail. `reference` (`HJZ-X-2027-0001`),
  `trip_excursion_id`, `customer_id`, `currency`, `total_minor`, `paid_minor`,
  `status`; with **`excursion_participants`** (`booking_pilgrim_id`,
  `amount_minor`).
- **`payments`**, **`payment_transactions`**: ported (polymorphic over
  `bookings` and `excursion_bookings`). Added: `settled_minor`,
  `settled_currency`, `rate_snapshot`, `slip_extraction_id`, `channel`
  (`portal`|`telegram`|`import`).
- **`exchange_rates`**: see D-4.
- **`bank_accounts`**: Hijaz's own accounts for instructions and matching.
  `bank`, `account_name`, `account_number` (encrypted), `currency`, `is_active`.

### Finance

- **`ledger_entries`**: every rupee, dollar and riyal in or out.
  `kind` (`income`|`expense`), `category_id`, `trip_id` (nullable: overheads),
  `happened_on`, `description`, `counterparty`, `currency`, `amount_minor`,
  `mvr_minor` (converted at the rate on that date), `rate_snapshot`, `method`
  (`bank`|`cash`|`card`), `bank_account_id`, `payment_id` (when the income is a
  verified pilgrim payment, created automatically), `document_id` (receipt
  scan), `recorded_by`. Verified pilgrim payments and recorded trip costs post
  here automatically; staff add everything else by hand.
- **`ledger_categories`**: `kind`, `name`, `sort_order`. Seeded: package
  sales, excursion sales, other income; airfare, Makkah hotel, Madinah hotel,
  transport, visa fees, Nusuk, meals, staff, bank charges, office, other.
- **`budgets`** (phase 6): per trip and category, planned `amount_minor`.

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
  quad adult, child, infant). Entered in whole units, stored in minor units.
- **Additional trips**: Makkah Ziyarah (included), Taif Ziyarah (priced),
  Jeddah trip (priced), each with date, price, capacity.
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
2. **Read**: the vision model returns `name_en`, `name_dv`, `id_number`,
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

Nothing is saved to the pilgrim from an unaccepted reading (D-6). If the API
is unavailable the fields are empty and staff type them; the reading can be
retried later from the document.

### 4. Individuals and families

A `customer` is either an individual (one pilgrim, relationship `self`) or a
family (a head plus members with relationships). "Add family member" scans the
next ID card into the same family. A booking is made for a customer, so a
family books together, pays together and gets one invoice, while every pilgrim
still has their own documents, room, bus and card. A member can later be moved
to another family (a married child) without losing history.

### 5. Payments from a slip, read automatically, verified by Finance

1. Staff (or later the pilgrim) upload the bank transfer slip or a screenshot
   of the banking app, against a booking or an excursion booking. Stored in
   Rihla's encrypted slip vault.
2. The vision model reads `amount`, `currency`, `paid_on`, `reference`,
   `payer_name`, `payer_account`, `payee_account`, `remarks`.
3. The portal proposes a match: payee account must be one of Hijaz's
   `bank_accounts`; the remark or reference is searched for a booking reference
   or a pilgrim ID number; the amount is compared with the balance. The payment
   is created `awaiting_review` with the proposal attached.
4. **Finance verifies** ("The money is in" / "It is not there", ported). If
   the payment currency differs from the booking's, the rate in force is
   snapshotted and `settled_minor` computed (D-4). The booking's paid total is
   re-derived under a row lock. A verified payment posts an income ledger
   entry automatically.
5. **Payment slip**: a receipt PDF (ported) with Hijaz's details, the amount
   in the paid currency and the settled amount, and the remaining balance.
   Shareable as a signed link, sent by Telegram, or printed.

Duplicate detection: the same slip bytes are refused; the same reference
number already verified is flagged.

### 6. Discounts

On a booking, Finance (or Admin) adds a discount: fixed amount or percentage,
reason required, approver recorded. It becomes a negative booking line, shows
on the invoice as its own row, and appears in the discount report. Discounts
cannot exceed the booking total, and a confirmed booking's discount can only
be added or reversed, never silently changed.

### 7. Passport reading and the six-month rule

Same scan-read-check-confirm flow as the ID card. The model reads the visual
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

### 14. Extra trips, payment and invoices

From the trip's excursions, "Add participants" picks pilgrims (a family at
once). For included excursions it is just a list. For priced ones it creates
an `excursion_booking` for the customer with the participants as lines, an
invoice PDF with its own reference, and the same slip-upload-read-verify
payment flow as the main booking. Invoices and receipts are shared as a
signed link (WhatsApp paste), a Telegram message, or print.

### 15. Bank USD list

A report of pilgrims on a trip with the columns the bank requires for its USD
allocation to Umrah pilgrims (name, ID number, passport number, amount in
USD, and whatever else the owner supplies, **Q-5**), as PDF and CSV, with a
cover letter on Hijaz letterhead. Which pilgrims are included, and the
amount per pilgrim, are set per trip. Rows are deduplicated by ID number.

### 16. Income, expenses and yearly reports

The `ledger_entries` table (section 3) is the single record of money. Verified
pilgrim payments and recorded trip costs post into it automatically; staff add
the rest with a receipt photo. Screens:

- **Trip profit and loss**: income by category, expenses by category, margin,
  per pilgrim, in each currency and in MVR at the dated rates (Rihla's
  `JourneyProfit` logic, extended to the ledger).
- **Yearly report**: calendar or fiscal year, income and expenses by month and
  category, all trips plus overheads, MVR total, CSV export for the
  accountant.
- **Cash book** per bank account and currency with running balance.
- **Outstanding balances**: who owes what, by trip, with ageing.

### 17. Three currencies

MVR, USD and SAR everywhere money appears (D-4). The rate table is a Finance
screen; the current rate shows on every conversion; every stored conversion
remembers its rate. Nothing is ever re-converted when the rate changes.

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

### 22. Mobile first

D-5, applied to every item above.

---

## 5. Other important features

Ranked by how much trouble they prevent. The first group belongs in the first
release; the second in the months after.

**Should be in the first release**

1. **Visa tracking per pilgrim.** Status (`not_started` → `submitted` → `issued`
   | `rejected`), visa number, issue and expiry dates, the agent used, a scan
   of the visa. A trip cannot move to `travelling` with unissued visas unless
   overridden. Ported from Rihla's visa desk in a lighter form.
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
| 0 | Foundation | Filament at `/portal`, roles and permissions, TOTP, audit log, settings, islands list, exchange rates, bank accounts, phone-first shell and bottom nav, backup and preflight commands, Telegram link (no commands yet) | L |
| 1 | Trips | Trip create and copy, flights, hotels, route, prices, excursions, checklist, costs, capacity lock, trip status | M |
| 2 | Pilgrims and documents | Customers and families, pilgrims, encrypted document wallet, ID card and passport reading with accept screens, MRZ check, photo checks, six-month rule, identity cross-check, duplicates, import | L |
| 3 | Bookings and money | Staff-created bookings for individuals and families, lines, discounts, slip upload and reading, Finance verification, cross-currency settlement, invoices and receipts, excursion bookings and invoices, instalments, cancellations and refunds | L |
| 4 | Operations | Bus groups, rooms, checkers, readiness board, manifests, bus and room lists, luggage tags and customer cards with QR, the Scan tab, visa tracker, ticket numbers | L |
| 5 | Telegram | Alerts, queries, confirmed actions, slip upload by chat, daily summary | M |
| 6 | Finance and reports | Ledger, categories, trip P&L, yearly report, cash book, outstanding balances, USD bank list, the report framework and the first report set, CSV | L |
| 7 | On the ground | Offline leader page, roll calls, incidents, pilgrim self-service link | M |

Phase 0 and 1 together give the owner a trip they can look at within the
first stretch of work; phase 2 is where the portal starts replacing paper.

### Questions for the owner

| # | Question | Needed by |
| --- | --- | --- |
| Q-1 | Who are the staff and which role does each get? (Names and emails; MFA for Admin and Finance.) | Phase 0 |
| Q-2 | Trip prices: are they per occupancy (double, triple, quad) and per adult/child/infant, and in which currency are they usually quoted? | Phase 1 |
| Q-3 | **Cloud reading of ID cards, passports and slips** (D-6): approve sending images to Anthropic's API under its no-training terms, and the wording of the pilgrim data notice. Also an Anthropic API key in the server `.env`. | Phase 2 |
| Q-4 | The detailed list of trip reports (item 18). | Phase 6 |
| Q-5 | The bank USD list format and the bank's rules (item 15). | Phase 6 |
| Q-6 | The exact passport-photo spec the visa agent requires. | Phase 2 |
| Q-7 | Does Hijaz obtain visas and Nusuk permits itself, or through a Saudi agent? | Phase 4 |
| Q-8 | How long to keep ID and passport scans after a trip (retention), and whether the accountant needs a specific export format. | Phase 2 |
| Q-9 | A Telegram bot token (created with @BotFather by the owner, pasted into the server `.env`, never into the repo). | Phase 5 |
| Q-10 | Hijaz's bank accounts (bank, account name and number, currency) for payment instructions and slip matching. | Phase 3 |
| Q-11 | Should the portal be reachable at hijaz.rihla.mv/portal, or at a separate hostname? | Phase 0 |

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
