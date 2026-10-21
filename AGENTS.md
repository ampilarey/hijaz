# Working in this repository

Hijaz is a Laravel 13 (PHP 8.3+) application served from cPanel shared hosting
at https://hijaz.rihla.mv. It deploys itself from `main` (docs/AUTO_DEPLOY.md).

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

## Before pushing

```bash
vendor/bin/pint --test && php artisan test && npm run build && git status --porcelain public/build
```

The last command must print nothing.
