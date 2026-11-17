# Plan: the Rihla platform for Umrah companies

> **Superseded.** The owner decided to build this inside Rihla, not as a
> separate project. The current plan is `docs/UMRAH_COMPANIES_PLAN.md` in
> the `ampilarey/rihla` repository. This file is kept for its reasoning.

**Status: plan only. Nothing here is built.** The Hijaz app keeps running
exactly as it does today.

The idea: Hajj and Umrah companies run their whole business on one platform
(trips, pilgrims, bookings, money, customer portal), each as if it were
their own system. The platform is built first, as its own product. Hijaz
moves onto it later, as one of its companies.

## Decided by the owner

| # | Decision | Answer |
|---|---|---|
| 1 | Whose platform | Rihla's. The platform carries the Rihla name |
| 2 | How companies join | The owner adds them. No public sign-up |
| 3 | Telegram | Each company has its own bot |
| 4 | Pilot | Any company may be the first |
| 5 | Order | The platform is built first. Hijaz joins later |
| 6 | Hosting | Start on cPanel shared hosting. Move to a VPS when it grows |

## Words used here

- **Platform**: the Rihla app as a whole, run by the owner.
- **Company** (a "tenant"): one Umrah business on the platform, with its own
  staff, data, brand and customers.
- **Owner panel**: the owner's own screens, for adding companies, plans and
  billing. It never shows a company's pilgrims, passports or money.

## What "platform first, Hijaz later" means for the build

**The platform is a new project, started from a copy of the Hijaz code.**
It gets its own repository, its own cPanel account (or its own folder and
database on the existing one) and its own database, which starts empty.

- The live Hijaz app is not touched. It stays on its current hosting, from
  its current repository, until the day it moves.
- No live data has to be converted while the walls between companies are
  being built, which removes the riskiest step of the earlier plan.
- The cost: until Hijaz moves, a fix or feature wanted in both places is
  made twice. So new Hijaz features are kept to what is needed, and Hijaz's
  move is planned for the first quiet period after the platform is proven.
- Hijaz's address already fits the platform's pattern (`hijaz.rihla.mv`),
  so its staff and customers keep the same address when it moves.

**The brand rule turns around.** Today "Rihla" must never appear in
anything a Hijaz visitor sees. On the platform:

- The owner panel and the platform's own site are Rihla-branded.
- Everything a company's staff and customers see carries that company's
  brand only. Whether a small "Powered by Rihla" line appears is a setting
  the owner decides (see open questions).

## Still to settle

| # | Question | Recommendation |
|---|---|---|
| 1 | Addresses | `company.rihla.mv` for each company; the owner panel at `app.rihla.mv`; a company's own domain later, as an extra |
| 2 | "Powered by Rihla" on company pages | Off on customer-facing pages, on in the staff portal footer |
| 3 | Pricing | Monthly plan by active pilgrims per season; invoiced by hand until Phase D |
| 4 | Is there demand? | Ask two or three companies what they would pay before Phase B is finished; the pilot answers it for certain |
| 5 | One login across companies | No: separate logins per company. Can be relaxed later |
| 6 | Who may be added | Licensed Hajj/Umrah operators only, checked by the owner when adding |

## How the separation works

**One database, every record tagged with its company.** Each of the ~50
business tables has a `company_id`; every screen, list, search, PDF,
download, report, Telegram reply and scheduled job is filtered by it
automatically.

- **Filament's built-in tenancy** does most of the portal work: the panel
  knows the current company, and resources only see its records.
- **A global scope on every model** covers everything outside Filament:
  controllers, PDFs, the customer portal, Telegram, console commands.
- **Roles per company**: spatie/permission's "teams" mode.
- **Files apart**: scans and slips stored under `documents/{company}/`.
- **Identifiers**: passport and ID numbers stay encrypted; the blind index
  that finds an ID number has the company mixed into its key, so the same
  person registered with two companies gives two unrelated records.
- **Cache apart**: cached values are keyed by company. (Settings are cached
  under one shared key in the Hijaz code; that is changed first.)
- **Background jobs apart**: every queued job and scheduled command carries
  its company and runs inside it; a job without one refuses to run.
- **Telegram apart**: each company's bot token is stored encrypted on the
  company, each bot has its own webhook address and secret, and an update
  arriving on one bot can only ever reach that company's data.
- **Proved by tests**: a suite that signs in as company A and tries to
  reach company B's every page, file, PDF, portal link and Telegram query,
  and must be refused each time. No phase ships without it passing.

**What "the owner never sees company data" means.** The owner panel shows
no pilgrims, passports or money, and impersonation needs consent and is
recorded. But whoever runs the server can read the database; that is true
of every hosted system. The data agreement says so plainly and binds Rihla
not to, rather than promising something technical that is not so.

## What changes from the Hijaz code, by area

| Area | In the Hijaz app | On the platform |
|---|---|---|
| Brand | Hijaz logo, colours and name built in | Each company's logo, colours, name and contacts from its profile |
| Settings | One set | One set per company |
| Reference numbers | `HJZ-B-2027-0001`, `UMR-2027-07`, `HJZ-L-…` | Each company's own prefix, numbered per company |
| Unique columns | Unique across the app | Unique within a company |
| Staff accounts | One list | Per company; the owner's account sits outside all companies |
| Customer portal | `/my` | `company.rihla.mv/my`, in the company's brand |
| Installable app | "Hijaz Portal" and "Hijaz" | Each company's name and icon, from per-company manifests |
| Telegram | One bot from the server's settings file | A bot per company, set up from the company's settings page |
| Islands | Shared | Shared (the same for everyone) |
| Exchange rates, accounts, ledger categories | One set | Per company; seeded when a company is added |
| Backups | Whole database | Whole database, plus a per-company export so one company can be restored alone |
| Scheduled jobs | Run once | Run for every company, each in its own context |
| Audit trail | One trail | Per company; the owner panel has its own |
| Deploy | cPanel, pull from `main` | The same on cPanel to start; a VPS when it grows (see Hosting) |

## Phases

Each phase ends with something that runs. Hijaz is untouched until its move.

### Phase 0: the new project and its hosting
- New repository copied from the Hijaz code; Hijaz names taken out of code,
  comments and commands (`hijaz:backup` and the like).
- cPanel hosting, set up the way Hijaz's is: deploy by pull from `main`,
  jobs from cron, backup before every migration. CI and deploy set up afresh.
- Company addresses: a wildcard subdomain (`*.rihla.mv`) if the host
  allows it with SSL; otherwise each company's subdomain is added by hand
  in cPanel when the company is added (a few minutes each).
- A copy of each backup sent off the server daily, since the platform
  holds other companies' records.
- **Done when**: the copied app runs on the hosting with an empty
  database, at a test address.

### Phase 1: companies and the walls (largest)
- `companies` table; `company_id` on all business tables (new migrations on
  an empty database: nothing to convert).
- Global scopes, Filament tenancy, roles in teams mode.
- Cache keys, queued jobs and scheduled commands made company-aware.
- Files under the company folder; references, unique columns and the ID
  blind index made per company.
- The cross-company test suite, as a release gate from here on.
- **Done when**: two test companies, added by hand, are fully walled off.

### Phase 2: each company its own brand, settings and bot
- Company profile: name, logo, colours, contacts, registration, address,
  bank accounts, agreement wording, reference prefix.
- PDFs, portal, sign-in page and installable apps read it.
- Subdomain routing: `company.rihla.mv` selects the company.
- Telegram bot per company: token entered on the company's settings page,
  webhook registered automatically.
- **Done when**: two test companies look entirely their own, each with its
  own working bot, and neither shows a trace of the other.

### Phase 3: the owner panel
- At `app.rihla.mv`, Rihla-branded, for the owner alone.
- Add a company: details, licence check, first Admin invited; accounts and
  ledger categories seeded; a short guided setup for the new Admin.
- Company list with status and usage (counts, not contents); suspend and
  reactivate; impersonation only with the company's consent, recorded.
- Per-company export, and closing a company's account.
- **Done when**: the owner can add a company in minutes, and that company
  can take its first booking without help.

### Pilot: the first real company
- Added by the owner. Terms of service, privacy policy and the data
  agreement signed on paper; invoiced by hand.
- Runs for at least one trip, from booking to return.
- **Go on to Phase 4 only if**: the pilot company stays and more are ready
  to pay. Otherwise the platform still runs as it is, with billing by hand.

### Phase 4: plans and billing
- Plans and limits (pilgrims per season, staff seats, storage).
- Monthly invoices to companies, payment by bank transfer with slip upload,
  reminders, grace period, suspension that keeps the data and blocks new
  bookings.
- **Done when**: billing runs without the owner chasing anyone by hand.

### Hijaz moves in
- Hijaz added as a company at `hijaz.rihla.mv`.
- An import tool brings its data across: staff, trips, pilgrims, bookings,
  payments, books, scanned files, audit trail. ID numbers are re-indexed on
  the way in. Rehearsed on a copy until the totals match to the laari.
- A short planned stop: old app made read-only, final import, address
  switched, old app kept for a month as a fallback, then retired.
- **Done when**: Hijaz staff and customers work on the platform at the
  same address, and the old app is off.

## Hosting: cPanel now, a VPS when it grows

The platform is written so the move needs no code change: jobs run from
cron or from a worker alike, and nothing depends on the host's layout.

**What cPanel means while it lasts**
- Background work (alerts, slip reading, PDFs) runs once a minute from
  cron, for all companies in turn. Fine for a few companies.
- Memory and time limits are shared, so heavy work is kept in small jobs.
- If the host has no wildcard SSL, each company's address is added by hand.

**Move to a VPS when any one of these happens**
- A third company is added, or about 300 pilgrims are active in a season.
- Alerts or slip readings regularly arrive more than a few minutes late.
- The host warns about, or cuts off for, memory or processing limits.
- A company asks for its own domain, or adding addresses by hand becomes
  a chore.

**The move itself**: a VPS with a queue worker, wildcard SSL, daily
off-site backups and monitoring; database and files copied across in a
short planned stop; addresses pointed at the new server. Roughly $10 to
$25 a month, plus someone to look after the server.

## Risks and how to meet them

| Risk | Answer |
|---|---|
| One company sees another's data | Scopes on every model, files per company, company-keyed cache and jobs, the cross-company test suite as a release gate, an external security review before the pilot |
| Two codebases drift apart before Hijaz moves | Keep new Hijaz features to the necessary; port fixes both ways; move Hijaz at the first quiet period after the pilot |
| Hijaz's import loses or changes something | Import rehearsed on copies; money totals, counts and file checksums compared before the switch; old app kept as fallback |
| Building what nobody buys | Ask companies early; the hand-run pilot comes before billing is built |
| Competitors distrust a platform whose owner also runs Hijaz | Rihla as the neutral name; written data agreement; owner panel shows no company data; export at any time |
| One company's data must be restored alone | Per-company exports alongside the whole-database backup |
| A company's Telegram bot token leaks | Stored encrypted, never shown again after entry, replaceable from settings |
| Shared hosting slows or limits the platform | Small jobs, daily off-site backups, and the written triggers above for moving to a VPS |
| Support load | Guided setup, a help section, a support channel; priced into the plans |

## Rough size

Relative only. A time estimate for Phases 0 to 2 is made before a start is
approved.

| Step | Relative effort |
|---|---|
| 0: new project and hosting | Small; the cPanel setup already exists for Hijaz |
| 1: companies and the walls | Largest: touches nearly every query, PDF, job and test |
| 2: brand, settings, bots | Medium to large |
| 3: owner panel | Medium |
| Pilot | No code; the owner's time |
| 4: billing | Small to medium |
| Hijaz moves in | Medium: the import tool and its rehearsals |
| Move to a VPS, when triggered | Small in code; setup and a rehearsed switch |

## Open questions for the owner

1. Is the plan right that the platform is a separate new project, and the
   live Hijaz app stays as it is until it moves?
2. Addresses: `company.rihla.mv` and `app.rihla.mv`?
3. Should company pages say "Powered by Rihla" anywhere?
4. Does the current host allow a wildcard subdomain with SSL for
   `*.rihla.mv`, and will the platform share Hijaz's cPanel account or
   have its own?
5. Roughly when should Hijaz move: before or after its next season?
