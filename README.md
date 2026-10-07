# Dealer SaaS

Dealer management system for Swiss car dealers, built as a multi-tenant SaaS
(Laravel 13, Filament 5, PostgreSQL). Every dealer is a **tenant**; their data is
isolated by the application **and** by PostgreSQL Row-Level Security.

Technical concept: see the "Dealer Management SaaS – Technical Concept" document.

## Status: Phase 0 (foundation)

| Area | What exists |
| --- | --- |
| Tenancy | Tenants, memberships, `BelongsToTenant` trait, `TenantContext`, RLS on every tenant table, tenant carried into queued jobs |
| Access | Dealer app at `/app/{dealer}`, platform admin at `/platform`, roles per dealer (Administrator, Verkauf, Buchhaltung, Nur-Lesen), 2FA (app or email) required for administrators and accounting, idle sign-out |
| Audit | Append-only change log (DB trigger), status history, change log screen |
| Languages | UI in DE / FR / IT / EN (`lang/*.json`), language per user, CI test fails on missing translations |
| Settings | Company profile, bank accounts with IBAN / QR-IBAN validation, numbering (prefix, pattern, start number, yearly reset, gap-free issuing) |
| Ops | Docker (local + single-VPS production), CI (Pint, Larastan level 6, Pest), backup and restore-drill scripts |

## Local setup

Requirements: PHP 8.3+ with `pdo_pgsql`, `intl`, `bcmath`; Composer; PostgreSQL 16+ (or Docker).

```bash
composer install
cp .env.example .env
php artisan key:generate

# Database: either start everything with Docker ...
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
