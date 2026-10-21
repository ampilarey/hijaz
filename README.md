# Hijaz

The website of **Hijaz Hajj & Umrah**, a Laravel application served at
https://hijaz.rihla.mv. Hijaz is its own company; the site is only hosted on a
rihla.mv subdomain. Brand and contact details are in
[docs/BRAND.md](docs/BRAND.md).

The public website is a placeholder. The staff portal at `/portal` is being
built phase by phase (docs/PORTAL_PLAN.md); CI and the auto-deploy pipeline
are in place.

| Doc | What it covers |
|---|---|
| [docs/SERVER_SETUP.md](docs/SERVER_SETUP.md) | One-time cPanel setup: database, document root, SSL, first install, GitHub secret |
| [docs/AUTO_DEPLOY.md](docs/AUTO_DEPLOY.md) | How a merge to `main` reaches the site, and what to check when it does not |
| [docs/BRAND.md](docs/BRAND.md) | Logo files, colours, type, and the business contact details |
| [docs/PORTAL.md](docs/PORTAL.md) | The staff portal: first account, roles, Telegram, backups, conventions |
| [docs/PORTAL_PLAN.md](docs/PORTAL_PLAN.md) | The plan for the staff portal: what is reused from Rihla, decisions, domain model, every feature, build order |

## Local development

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
npm run dev        # or: npm run build
php artisan serve
```

## Before you push

```bash
vendor/bin/pint          # formatting
php artisan test         # tests
npm run build            # public/build is committed; CI fails if it is stale
```

## How it deploys

Merge to `main` → CI runs → if green, GitHub Actions calls the site's deploy
webhook → the server pulls `main`, installs, migrates, caches → the workflow
verifies the site reports the new commit. Nothing is deployed from a red CI
run. Details in [docs/AUTO_DEPLOY.md](docs/AUTO_DEPLOY.md).
