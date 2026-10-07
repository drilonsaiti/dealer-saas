# Dealer SaaS – notes for AI assistants

Multi-tenant dealer management SaaS for Swiss car dealers. Laravel 13, Filament 5, PostgreSQL, Pest 4.
Read README.md "Rules for writing code" before changing anything; they are mandatory.

- Domain code: `app/Domain/<Module>/{Models,Actions,Enums,...}`. Filament UI: `app/Filament/App` (dealer panel, tenant-scoped) and `app/Filament/Platform` (platform admin).
- Tenant isolation is enforced twice: `TenantScope` (app) and PostgreSQL RLS (`app.tenant_id` / `app.bypass_rls` session settings set by `TenantContext`). Tests must run against PostgreSQL with a non-superuser role.
- UI strings: English keys in `__()`, translations in `lang/{de,fr,it,en}.json`. `tests/Feature/TranslationsTest.php` fails if one is missing.
- Run before committing: `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `php artisan test`.
