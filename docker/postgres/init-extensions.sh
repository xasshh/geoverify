#!/bin/bash
# Enables the extensions on both the working and the test database, so a fresh
# container is immediately usable by the app and by the suite.
set -eu

for db in "${POSTGRES_DB:-geoverify}" "${POSTGRES_TEST_DB:-geoverify_testing}"; do
    createdb --username "$POSTGRES_USER" "$db" 2>/dev/null || true
    psql --username "$POSTGRES_USER" --dbname "$db" --set ON_ERROR_STOP=1 <<-SQL
        CREATE EXTENSION IF NOT EXISTS postgis;
        CREATE EXTENSION IF NOT EXISTS h3;
        CREATE EXTENSION IF NOT EXISTS h3_postgis CASCADE;
        CREATE EXTENSION IF NOT EXISTS pg_trgm;
        CREATE EXTENSION IF NOT EXISTS pgcrypto;
SQL
done
