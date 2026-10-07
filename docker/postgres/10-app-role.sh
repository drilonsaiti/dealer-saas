#!/bin/bash
# Creates the application role. It must NOT be a superuser and must NOT have BYPASSRLS,
# otherwise PostgreSQL Row-Level Security (tenant isolation) is skipped.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<-EOSQL
    CREATE ROLE "${APP_DB_USER}" LOGIN PASSWORD '${APP_DB_PASSWORD}' NOSUPERUSER NOBYPASSRLS NOCREATEROLE CREATEDB;
    CREATE DATABASE "${APP_DB_NAME}" OWNER "${APP_DB_USER}";
    CREATE DATABASE "${APP_DB_NAME}_test" OWNER "${APP_DB_USER}";
EOSQL
