# Dealer SaaS

Dealer management system for Swiss car dealers, built as a multi-tenant SaaS
(Laravel 13, Filament 5, PostgreSQL). Every dealer is a **tenant**; their data is
isolated by the application **and** by PostgreSQL Row-Level Security.

Technical concept: see the "Dealer Management SaaS – Technical Concept" document.

## Status: Phases 1 and 2 done; Phase 3 in progress (listings, website API, portals, exports and e-mail done; calendar feed next)

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
| Invoicing | Deposit, final and standard invoices, credit notes; gap-free numbers on issue; prices incl. VAT with dated rates (8.1 % since 2024); Swiss QR bill (QR reference with QR-IBAN, RF creditor reference otherwise) built in, validated against the SIX guidelines; locked PDF in the vehicle file; send by email on click |
| Payments | Payments in/out with allocations to invoices and purchases (trade-in offsets and financing payouts as methods); camt.053/054 import with automatic matching by reference, proposals by amount, assign/ignore/undo |
| VAT (MWST) | Dated VAT settings (method, agreed/received, period, approved net tax rates); tax entries from issued invoices and payments by versioned rules (auto / confirm / blocked, with explanation); half-year preview with ESTV form fields and net tax per rate; close (`vat.close`) freezes the figures, files report PDF + CSV; eCH-0217 v2 XML export (structure checked against the official eCH example; schema check when `resources/schemas/eCH-0217-2-0-0.xsd` is present); submitted and paid steps; corrections for closed periods; margin after net tax |
| Leasing | Leasing / credit per sale (bank = invoice recipient, collected first instalment credited, expected payout), status flow with 14-day revocation period, partner checklist per bank, payout from the bank import, buy-back obligations with reminder and exercise into a new vehicle file, code 178 blocks resale |
| Warranty | Warranty products per dealer (own or provider), sold with the car (price on the sale, premium as cost), policy with versioned certificate, active from handover, claims with dealer share booked on the original file, expiry |
| Preparation | Condition reports with damages and photos, repair orders (estimate → approved → done with the actual cost), target date, release for sale blocked by open repair orders |
| Handover | Checklist from versioned templates with automatic rules (promises, paid or paid out, warranty registered, documents); required items block the handover |
| Listings & API | Own listings per vehicle file (texts in 4 languages, price, photos, cover); availability follows the file (reserved, sold for 7 days); public REST API v1 (JSON:API, dealer tokens, rate limits, signed photo URLs), website enquiries with contact matching, signed webhooks with delivery log; WordPress plugin (`integrations/wordpress`), see `docs/api.md` |
| Portals | AutoScout24 connector with the dealer's own credentials (Settings → Integrations): published listings are created, updated (only when something changed), marked reserved and removed when sold or withdrawn, in the queue with retries and a nightly catch-up (`listings:sync`); errors on the vehicle file and in the sync log; first import of the portal stock (VIN match, idempotent). Field names and addresses are an assumption until checked against the AutoScout24 CH DMS API documentation (`config/integrations.php`, `docs/integrations.md`) |
| Accounting export | Journal for the accountant (Finance → Accounting export): issued invoices and credit notes, payments in/out, vehicle purchases and confirmed costs as double-entry bookings (CSV with BOM, semicolons, Swiss date), Swiss SME default accounts (1000, 1020, 1100, 1170, 2000, 2030, 2200, 3200, 3400, 4200, 4400) changeable per booking type, bank account and cost category; VAT split with the effective method, gross with the net tax rate method; every record exported once (unique key), late records come with the next export, the latest export can be undone |
| E-mail inbox | Dealer mailboxes (Settings → Mailboxes, IMAP in / SMTP out, passwords encrypted) fetched every 5 minutes (`mail:fetch`, own IMAP client, read-only on the server, idempotent by Message-ID and UID); e-mails matched to contact (sender) and vehicle file (VIN, Stammnummer, file number, plate, thread, the contact's one open sale); attachments into the file (category Korrespondenz), programs and macro files quarantined, optional ClamAV scan (fail closed); replies as drafts with file documents, sent only on click via the mailbox's SMTP in the same thread; e-mails tab on the vehicle file |
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
