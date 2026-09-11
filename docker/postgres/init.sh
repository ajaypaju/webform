#!/bin/sh
# Runs once, as the postgres superuser, on first start of an empty data volume. The superuser is never used after this.
set -eu

psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" <<SQL
    CREATE ROLE webform_owner  LOGIN PASSWORD '$WEBFORM_OWNER_PASSWORD';
    CREATE ROLE webform_api    LOGIN PASSWORD '$WEBFORM_API_PASSWORD';
    CREATE ROLE webform_ingest LOGIN PASSWORD '$WEBFORM_INGEST_PASSWORD';
    CREATE ROLE webform_writer LOGIN PASSWORD '$WEBFORM_WRITER_PASSWORD';

    ALTER DATABASE webform OWNER TO webform_owner;
    CREATE DATABASE webform_test OWNER webform_owner;

    REVOKE CONNECT ON DATABASE webform, webform_test FROM PUBLIC;
    GRANT  CONNECT ON DATABASE webform, webform_test TO webform_owner, webform_api, webform_ingest, webform_writer;
SQL
