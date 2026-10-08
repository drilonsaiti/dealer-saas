# Dealer SaaS

Dealer management system for Swiss car dealers, built as a multi-tenant SaaS
(Laravel 13, Filament 5, PostgreSQL). Every dealer is a **tenant**; their data is
isolated by the application **and** by PostgreSQL Row-Level Security.

Technical concept: see the "Dealer Management SaaS – Technical Concept" document.

## Status: Phase 1 done, Phase 2 in progress (contracts, signatures, invoices, VAT, leasing)

| Area | What exists |
| --- | --- |
| Vehicles | Vehicle master data and vehicle files (stock cycles) with a guarded status flow, buy-backs as new files on the same vehicle, tyre sets, nightly archiving |
| Purchase & costs | Contacts (with duplicate check), purchases, cost categories, costs (draft → confirmed, split), open promises (commitments) that block handover |
| Sales | Reservation, contract, trade-ins, cancellation, handover; gross margin per file; dashboard (stock, ageing, margin per month) |
| Documents | Versions (never overwritten), exact and near duplicates, OCR (Tesseract, DE/FR/IT/EN) with full-text search, required-documents checklist, vehicle file export (ZIP + PDF overview via Gotenberg) |
| Import | Vehicles, costs (Excel/CSV) and document folders (ZIP): column mapping with presets, check first (dry run), per-row report, re-runnable, roll back; `php artisan import:run` for large files; stock list export |
| Isolation | Acceptance test 15 (`tests/Feature/Tenancy/IsolationSuiteTest.php`): dealer B has one of everything, dealer A sees none of it in SQL, Eloquent, any list or global search |
| Tenancy | Tenants, memberships, `BelongsToTenant` trait, `TenantContext`, RLS on every tenant table, tenant carried into queued jobs |
| Access | Dealer app at `/app/{dealer}`, platform admin at `/platform`, roles per dealer (Administrator, Verkauf, Buchhaltung, Nur-Lesen), 2FA (app or email) required for administrators and accounting, idle sign-out |
| Audit | Append-only change log (DB trigger), status history, change log screen |
| Languages | UI in DE / FR / IT / EN (`lang/*.json`), language per user, CI test fails on missing translations |
| Settings | Company profile, bank accounts with IBAN / QR-IBAN validation, numbering (prefix, pattern, start number, yearly reset, gap-free issuing) |
| Contracts | Sales and purchase contracts in DE/FR/IT/EN from the vehicle file (wizard: prepare → check → finalise); numbered, data snapshot + SHA-256, locked versions; versioned clause templates per dealer (Settings → Templates); logo on documents |
| Signatures | Own simple e-signature: on the iPad (with ID check) or by customer link with one-time code; signers in order (customer, then dealer); evidence page in the document language; signed PDF sealed (PAdES via pyHanko) and locked; withdraw, resend, expiry, signed on paper |
| Ops | Docker (local + single-VPS production), CI (Pint, Larastan level 6, Pest), backup and restore-drill scripts; drill results under Platform → Restore drills, with a warning when none succeeded in 35 days |

## Local setup

### With Docker (recommended, also on Windows)

```bash
cp .env.example .env          # then: php artisan key:generate, or set APP_KEY yourself
docker compose up -d          # first start: installs composer packages and migrates (1–2 min)
docker compose exec app php artisan db:seed     # demo data, once
```

Open http://localhost:8000/app. After every `git pull`: `docker compose restart app` (installs new
packages, runs new migrations, refreshes the Filament cache). Code changes show up without a restart.

The app container runs FrankenPHP, which serves several requests at once; `vendor/` lives in a
Docker volume because reading it through a Windows bind mount is slow. On Windows the project is
fastest inside WSL 2 (e.g. `\\wsl$\Ubuntu\home\you\dealer-saas`) rather than under `C:\Users`.
Run artisan commands in the container: `docker compose exec app php artisan ...`.

### Without Docker for the app

Requirements: PHP 8.3+ with `pdo_pgsql`, `intl`, `bcmath`; Composer; PostgreSQL 16+.

```bash
composer install
cp .env.example .env
php artisan key:generate

# Database: either start only the services with Docker ...
docker compose up -d postgres redis mailpit gotenberg
# ... or create the role yourself (it must NOT be superuser and must NOT have BYPASSRLS):
#   CREATE ROLE dealer LOGIN PASSWORD 'secret' NOSUPERUSER NOBYPASSRLS CREATEDB;
#   CREATE DATABASE dealer OWNER dealer; CREATE DATABASE dealer_test OWNER dealer;

php artisan migrate --seed
php artisan serve
```

Demo logins (password `password`):

| URL | User | Notes |
| --- | --- | --- |
| http://localhost:8000/app | `admin@demo-bern.example.ch` | Demo Garage Bern (German). Asks to set up 2FA on first login |
| http://localhost:8000/app | `admin@demo-vevey.example.ch` | Garage Démo Vevey (French) |
| http://localhost:8000/platform | `platform@example.ch` | Platform admin, create dealers here |

Outgoing emails (invitations, password resets, 2FA codes) land in Mailpit: http://localhost:8025

OCR needs `tesseract-ocr` (with `deu`, `fra`, `ita` language data) and `poppler-utils`
(`pdftotext`, `pdftoppm`, `pdfinfo`); the Docker image has them. Imports and OCR run in the queue,
so locally also start a worker (`php artisan queue:work`, or `docker compose --profile queue up -d`)
unless `QUEUE_CONNECTION=sync` (the default in `.env.example`).

### Importing a dealer's existing data

In the app: Settings → Imports → New import. Order: vehicles first, then costs, then the
document folder ZIP (it links files to vehicles by Stammnummer). Every import is checked first
and can be rolled back. Large files from the server's disk:

```bash
php artisan import:run aziri vehicles /path/Fahrzeuge.xlsx --sheet=Fahrzeuge          # check only
php artisan import:run aziri vehicles /path/Fahrzeuge.xlsx --sheet=Fahrzeuge --commit # import
php artisan import:run aziri documents /path/Ordner.zip --commit
```

## Checks

```bash
composer lint      # Pint (code style)
composer analyse   # Larastan level 6
php artisan test   # Pest (needs the PostgreSQL test database, see phpunit.xml)
```

CI runs all three on every push. The test database role is created without
superuser/BYPASSRLS on purpose: one test fails if RLS could be bypassed.

## Rules for writing code

1. **Every table with business data has `tenant_id`**, uses the `BelongsToTenant` trait and gets
   `RowLevelSecurity::enable('table')` in its migration. No exceptions.
2. Never query tenant data outside a tenant context. In jobs, commands and seeders use
   `app(TenantContext::class)->run($tenant, fn () => ...)`. Only platform code may use `bypass()`.
3. Business logic goes into **Action** classes in `app/Domain/<Module>/Actions`; Filament
   resources only call them.
4. Money is stored in **Rappen** (`bigint`), never floats.
5. Every text shown to users goes through `__('English text')` and is added to
   `lang/de.json`, `fr.json`, `it.json`, `en.json` (Swiss German spelling: "ss", never "ß").
6. New models must be added to `App\Domain\Audit\MorphMap` and usually use `Auditable`.
7. Issued numbers come only from `IssueNumber`, inside the transaction that saves the document.

## Deployment (one Swiss VPS)

```bash
# on the server
git clone … /opt/dealer-saas && cd /opt/dealer-saas
cp .env.example .env   # set APP_ENV=production, APP_KEY, DOMAIN, DB_*, POSTGRES_SUPERUSER_PASSWORD, S3, mail
docker compose -f deploy/docker-compose.prod.yml up -d --build
docker compose -f deploy/docker-compose.prod.yml exec web php artisan migrate --force
```

Caddy (inside FrankenPHP) gets the HTTPS certificate automatically for `DOMAIN`.

Backups (cron on the host):

```
15 2 * * *  /opt/dealer-saas/deploy/backup.sh        # nightly dump to off-site storage
30 3 1 * *  /opt/dealer-saas/deploy/restore-test.sh  # monthly restore drill
```

The restore drill records its result with `php artisan backup:record-drill` (shown under
Platform → Restore drills). Set `IMPORT_QUEUE_CONNECTION=redis-long` in production so long imports
run in the `queue-long` service.
