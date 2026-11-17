# Plan: one app, many Umrah companies

**Status: plan only. Nothing here is built.** Hijaz keeps running exactly as
it does today until a phase below is approved and started.

The idea: other Hajj and Umrah companies register and run their whole
business on this app (trips, pilgrims, bookings, money, customer portal),
each as if it were their own system, under one platform the owner runs.

## Words used here

- **Platform**: the app as a whole, run by the owner. Needs its own name
  (written `[Platform]` below); it should be neither "Hijaz" nor "Rihla".
- **Company** (a "tenant"): one Umrah business on the platform, with its own
  staff, data, brand and customers. Hijaz becomes company number 1.
- **Owner panel**: the platform owner's own screens, for companies, plans
  and billing. It never shows a company's pilgrims, passports or money.

## Decisions to make before any work

| # | Decision | Recommendation |
|---|---|---|
| 1 | Platform name and legal entity | A new name, under a separate company, so competitors are not handing data to Hijaz |
| 2 | Who may join | Licensed Hajj/Umrah operators only (Ministry of Islamic Affairs list), approved by the owner before going live |
| 3 | Address for each company | `company.[platform].mv`, with an optional own domain later |
| 4 | Telegram | One shared platform bot to start; a company's own bot as a paid extra |
| 5 | Pricing | Monthly plan by active pilgrims per season; a free trial; bank transfer first |
| 6 | Hosting | Move from shared cPanel to a VPS before Phase B: company subdomains need a wildcard SSL certificate |
| 7 | When to start | After Hijaz has run one full season on the app |
| 8 | Is there demand? | Before building anything, ask two or three Umrah companies whether they would use it and what they would pay |
| 9 | One login across companies? | No to start: separate logins per company. Simpler and safer; can be relaxed later |

Until a start is approved, one free habit helps: new features avoid
Hijaz-specific wording, so there is less to undo later.

## How the separation works

**One database, every record tagged with its company.** Each of the ~50
business tables gets a `company_id`; every screen, list, search, PDF,
download, report, Telegram reply and scheduled job is filtered by it
automatically. This is far simpler to run than a database per company,
and it is what shared or small hosting can carry.

- **Filament's built-in tenancy** does most of the portal work: the panel
  knows the current company, and resources only see its records.
- **A global scope on every model** covers everything outside Filament:
  controllers, PDFs, the customer portal, Telegram, console commands.
- **Roles per company**: spatie/permission's "teams" mode, so a user can be
  Finance in one company and nothing in another.
- **Files apart**: scans and slips stored under `documents/{company}/`.
- **Identifiers**: passport and ID numbers stay encrypted; the blind index
  that finds an ID number gets the company mixed into its key, so the same
  person registered with two companies gives two unrelated records. Every
  stored ID number is re-indexed once for this: a data migration of its
  own, rehearsed on a copy of the live data.

**What "the owner never sees company data" means.** The owner panel shows
no pilgrims, passports or money, and impersonation needs consent and is
recorded. But whoever runs the server can read the database; that is true
of every hosted system. The data agreement says so plainly and binds the
platform not to, rather than promising something technical that is not so.
- **Cache apart**: cached values are keyed by company. Settings are cached
  under one shared key today (`settings.all`); left alone, one company
  would read another's settings.
- **Background jobs apart**: every queued job and scheduled command carries
  its company and runs inside it; a job without one refuses to run.
- **Proved by tests**: a suite that signs in as company A and tries to
  reach company B's every page, file, PDF, portal link and Telegram query,
  and must be refused each time. No phase ships without it passing.

## What changes, by area

| Area | Today | On the platform |
|---|---|---|
| Brand | Hijaz logo, colours and name built in (`Brand`, `config/hijaz.php`, PDFs, icons, manifests) | Each company's logo, colours, name and contacts from its own settings |
| Settings | One set (company details, agreement, passport rule) | One set per company |
| Reference numbers | `HJZ-B-2027-0001`, `UMR-2027-07`, card serials `HJZ-L-…` | Each company's own prefix, numbered per company |
| Unique columns | Trip code, booking/payment references, user email unique across the app | Unique within a company; email unique per company or platform-wide (decide) |
| Staff accounts | One list | Per company; the owner's account sits outside all companies |
| Customer portal | `/my` | `company.[platform].mv/my`, in the company's brand |
| Installable app | "Hijaz Portal" and "Hijaz" | Each company's name and icon, from per-company manifests |
| Telegram | One bot, staff linked to it | Linked per company; replies only ever about that company |
| Islands, exchange rates | Shared tables | Islands shared (same for everyone); exchange rates per company |
| Ledger categories, accounts | Seeded once | Seeded for each new company |
| Backups | Whole database | Whole database, plus an export of one company's data on request |
| Scheduled jobs | Run once for the app | Run for every company, each in its own context |
| Audit trail | One trail | Per company; the owner panel has its own |

## Phases

Each phase ends deployable, with Hijaz working throughout. Sign-up and
billing come last, and only once companies are paying: until then the
owner onboards each company by hand.

### Phase A1: companies exist, data is tagged (invisible)
- `companies` table; Hijaz created as company 1.
- `company_id` on all business tables, every existing row backfilled in a
  reversible migration, rehearsed on MySQL with a copy of the live data.
- **Done when**: Hijaz sees no difference and every row has its company.

### Phase A2: the walls (invisible)
- Global scopes on every model, Filament tenancy, roles in teams mode.
- Cache keys, queued jobs and scheduled commands made company-aware.
- The cross-company test suite, as a release gate from here on.
- **Done when**: a second test company, created by hand, is fully walled off.

### Phase A3: files, references, identifiers (invisible)
- Files moved under the company folder.
- References and unique columns made per company.
- ID numbers re-indexed with the company in the key.
- **Done when**: nothing in storage or numbering is shared between companies.

### Hosting move (before Phase B)
- VPS with daily off-site backups, a real queue worker, wildcard SSL for
  company subdomains, monitoring and uptime alerts.
- A way to restore one company's data without rolling back the others:
  per-company exports taken alongside the whole-database backup.

### Phase B: each company its own brand and settings
- Company profile: name, logo, colours, contacts, registration, address,
  bank accounts, agreement wording, reference prefix.
- PDFs, portal, emails, installable apps and the sign-in page read it.
- Subdomain routing: `company.[platform].mv` selects the company.
- **Done when**: a second company looks entirely its own, with no trace of
  Hijaz anywhere it or its customers can see.

### Pilot: one friendly company, onboarded by hand
- The owner creates the company, its first Admin and its setup directly.
- Terms of service, privacy policy and the data agreement, signed on paper.
- Invoiced by hand. Runs for at least one trip from booking to return.
- **Go on only if**: the pilot company stays, and two or three more are
  ready to pay. Otherwise stop here; nothing built so far is wasted on
  Hijaz, and Phases C and D are never paid for.

### Phase C: sign-up, approval and the owner panel
- Public platform site with "Register your company".
- Sign-up form, licence upload, owner approval, then the company's first
  Admin is invited and a guided setup runs (accounts, first trip, staff).
- Owner panel: companies, status, plan, usage (counts, not contents),
  suspend and reactivate, impersonation only with the company's consent
  and recorded.
- **Done when**: a company can go from sign-up to taking its first booking
  without help.

### Phase D: plans and billing
- Plans and limits (pilgrims per season, staff seats, storage).
- Monthly invoices to companies, payment by bank transfer with slip upload
  (the same slip flow customers use), reminders, grace period, suspension
  that keeps the data and blocks new bookings.
- **Done when**: billing runs without the owner chasing anyone by hand.

### Launch
- Data export and account closing for companies.
- Public launch under the platform name.

## Risks and how to meet them

| Risk | Answer |
|---|---|
| One company sees another's data | Scopes on every model, files per company, the cross-company test suite as a release gate, an external security review before launch |
| Hijaz disrupted during the change | Phase A is invisible; every migration reversible and rehearsed on a copy of the live data |
| Competitors distrust a Hijaz-owned system | Separate brand and company, written data agreement, owner never sees company data, export at any time |
| Shared hosting cannot carry several companies | Move to a VPS before Phase B |
| Building what nobody buys | Demand check before starting; a hand-run pilot before sign-up and billing are built |
| One company's data must be restored alone | Per-company exports alongside the whole-database backup |
| A leak through cache or a background job | Company-keyed cache, company-carrying jobs, both covered by the cross-company tests |
| Support load | Guided setup, a help section, Telegram support group; price support into the plans |
| Licensing and data rules | Accept licensed operators only; follow Maldivian data protection rules for passports and IDs |

## Rough size

Relative only. A time estimate for A1 to A3 is made, from the code as it
then stands, before a start is approved.

| Step | Relative effort |
|---|---|
| A1: tag the data | Medium: wide but mechanical |
| A2: the walls | Largest: touches nearly every query, PDF, job and test |
| A3: files, references, identifiers | Medium |
| Hosting move | Small in code; setup and rehearsal |
| B: brand and settings | Medium |
| Pilot | No code; the owner's time |
| C: sign-up and owner panel | Medium |
| D: billing | Small to medium |
| Launch | Small in code; legal and paperwork |

## Open questions for the owner

1. The platform's name, and whether it sits under a separate company.
2. Self sign-up with approval, or companies added by the owner only?
3. One shared Telegram bot, or each company its own?
4. Which two or three companies will be asked first, and who is the pilot?
5. Start Phase A1 now, or after Hijaz's first full season?
