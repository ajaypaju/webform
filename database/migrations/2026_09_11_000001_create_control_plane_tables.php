<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// WHY: raw SQL throughout the schema — the schema builder can't express partitioning, composite FKs, RLS or
// triggers, and one dialect is easier to review than a mix.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE tenants (
                id   uuid PRIMARY KEY,
                name text NOT NULL
            );

            CREATE TABLE api_keys (
                id         uuid PRIMARY KEY,
                tenant_id  uuid NOT NULL REFERENCES tenants (id),
                key_hash   text NOT NULL UNIQUE,
                created_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE forms (
                id                 uuid PRIMARY KEY,
                tenant_id          uuid NOT NULL REFERENCES tenants (id),
                name               text NOT NULL,
                draft              jsonb NOT NULL,
                current_version_id uuid,
                status             text NOT NULL CHECK (status IN ('draft', 'published', 'archived'))
            );

            CREATE TABLE form_versions (
                id           uuid PRIMARY KEY,
                form_id      uuid NOT NULL REFERENCES forms (id),
                tenant_id    uuid NOT NULL REFERENCES tenants (id),
                version_no   integer NOT NULL,
                definition   jsonb NOT NULL,
                published_at timestamptz NOT NULL,
                UNIQUE (form_id, version_no),
                -- I3: lets submissions reference (version, form, tenant) as one unit.
                UNIQUE (id, form_id, tenant_id)
            );

            -- Circular reference: forms.current_version_id is nullable, so a form is inserted first, then its
            -- version, then the pointer is set.
            ALTER TABLE forms
                ADD CONSTRAINT forms_current_version_id_fkey
                FOREIGN KEY (current_version_id) REFERENCES form_versions (id);

            CREATE INDEX forms_tenant_id_idx ON forms (tenant_id);
            CREATE INDEX form_versions_form_id_idx ON form_versions (form_id);

            -- I4
            CREATE OR REPLACE FUNCTION form_versions_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'form_versions is immutable (%)', TG_OP USING ERRCODE = 'restrict_violation';
            END
            $$;

            CREATE TRIGGER form_versions_immutable
                BEFORE UPDATE OR DELETE ON form_versions
                FOR EACH ROW EXECUTE FUNCTION form_versions_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS form_versions, forms, api_keys, tenants CASCADE;
            DROP FUNCTION IF EXISTS form_versions_immutable();
        SQL);
    }
};
