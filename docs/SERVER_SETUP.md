# Server setup for hijaz.rihla.mv (one time)

Everything here happens once. After it, every merge to `main` deploys itself
([AUTO_DEPLOY.md](AUTO_DEPLOY.md)).

The site runs on the same cPanel account as `rihla.mv` and `test.rihla.mv`
(account `rihla`, home `/home/rihla`, PHP 8.4, MySQL). The checkout lives at
`/home/rihla/hijaz` (the folder cPanel made for the subdomain) and the
subdomain's document root points at its `public/` folder, exactly like
`test.rihla.mv`.

Already done: the `hijaz.rihla.mv` subdomain exists in cPanel, and the GitHub
repository `ampilarey/hijaz` exists.

## 1. PHP version (cPanel → MultiPHP Manager)

Tick `hijaz.rihla.mv`, choose **PHP 8.4** (ea-php84), Apply. Laravel 13 needs
8.3 or newer and the deploy scripts put ea-php84 on their PATH.

## 2. Database (cPanel → MySQL® Databases)

1. **Create New Database**: name `hijaz` → cPanel makes it `rihla_hijaz`.
2. **MySQL Users → Add New User**: username `hijaz` → becomes `rihla_hijaz`.
   Use the password generator and **copy the password somewhere safe now**.
3. **Add User To Database**: user `rihla_hijaz` → database `rihla_hijaz` →
   **ALL PRIVILEGES** → Make Changes.

You now have three values the next step asks for:

| | |
|---|---|
| Database | `rihla_hijaz` |
| User | `rihla_hijaz` |
| Password | the one you copied |

(If the cPanel account prefix is not `rihla_`, use whatever cPanel shows; the
setup script takes the names as given.)

## 3. First install (cPanel → Terminal)

One command. It clones the repository, installs dependencies, writes `.env`,
creates the tables, links storage, caches config, and installs both crons.

```bash
DB_DATABASE=rihla_hijaz DB_USERNAME=rihla_hijaz DB_PASSWORD='PASTE-PASSWORD-HERE' \
  bash <(curl -fsSL https://raw.githubusercontent.com/ampilarey/hijaz/main/scripts/server-setup.sh)
```

Single quotes around the password matter if it contains `$`, `!` or spaces.
Leave the three variables off and the script asks for them instead.

It finishes by printing a block that starts `Server setup complete`, followed by
a 64-character **webhook secret**. Keep that terminal open for step 5.

If it stops with `ABORT:` it says why and what to do; it changes nothing it
has not reported. It is safe to run again.

## 4. Document root (cPanel → Domains)

Find `hijaz.rihla.mv` → **Manage** → **Document Root** → set to

```
hijaz/public
```

(cPanel shows it relative to `/home/rihla`; it currently says `/hijaz`, and
the full new path is `/home/rihla/hijaz/public`.) Save.

cPanel created `/home/rihla/hijaz` as an empty folder when you made
the subdomain. The setup script moved that empty folder aside
(`hijaz.cpanel-default.<timestamp>`) and cloned the repository in its
place, so the path is the same but now holds the application.

## 5. SSL (cPanel → SSL/TLS Status)

Tick `hijaz.rihla.mv` → **Run AutoSSL**. Wait for the green padlock. The GitHub
webhook refuses to talk to a site without a valid certificate.

If DNS for `hijaz.rihla.mv` is on Cloudflare, the record is an A record to the
server IP (`103.159.65.148`, the same as `test.rihla.mv`), proxied or not.

Check from any browser or terminal:

```bash
curl -s https://hijaz.rihla.mv/api/health
# {"ok":true,"app":"Hijaz","env":"production"}
```

## 6. GitHub environment and secret

GitHub → `ampilarey/hijaz` → **Settings → Environments → New environment**:

- Name: `hijaz`
- **Add environment secret**: name `DEPLOY_WEBHOOK_SECRET`, value = the secret
  the setup script printed in step 3.

Lost it? On the server: `grep DEPLOY_WEBHOOK_SECRET /home/rihla/hijaz/.env`

Optionally, on the same page, add yourself under **Required reviewers** if you
want to approve each deploy by hand. Leave it empty for fully automatic.

## 7. Branch protection (recommended)

Settings → Branches → Add rule for `main`: tick **Require status checks to pass
before merging** and select the CI jobs. Then a pull request cannot merge red,
and `main` is always something the site may run.

## 8. First deploy

Merge anything to `main` (or push to it). Watch **Actions**:

1. **CI** runs (about two minutes).
2. **Deploy hijaz.rihla.mv** runs after it, calls the webhook, and polls the
   site until it reports the new commit.

Both green, and https://hijaz.rihla.mv serves that commit. On the server,
`tail -f ~/hijaz-deploy.log` shows the pull.

## What got installed where

| Thing | Where |
|---|---|
| Application | `/home/rihla/hijaz` |
| Document root | `/home/rihla/hijaz/public` |
| Environment file | `/home/rihla/hijaz/.env` (never in git) |
| Deploy log | `/home/rihla/hijaz-deploy.log` |
| Scheduler log | `/home/rihla/schedule-hijaz.log` |
| Cron: scheduler | `* * * * * cd /home/rihla/hijaz && php artisan schedule:run` |
| Cron: deploy fallback | `* * * * * /home/rihla/hijaz/scripts/self-update.sh` |
| Uploaded files | `/home/rihla/hijaz/storage/app/public` (served via `public/storage`) |

`crontab -l` lists both lines; the existing `rihla.mv` and `test.rihla.mv`
lines are left untouched.

## Later: email, backups

Not needed for the deploy to work. When the site sends mail, put an SMTP
provider's details into `.env` (`MAIL_*`) and run `php artisan config:cache`.
cPanel → Backup covers the database and files on the host's schedule; a
`backup` artisan command can be added when the site holds data worth it.
