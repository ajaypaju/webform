<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// I11: four non-superuser roles (created cluster-wide by docker/postgres/init.sh), each with only the grants and
// the row-level policies its process needs. Nothing here uses BYPASSRLS.
// WHY: RLS is ENABLED, not FORCED. FORCE only binds the table owner, and the owner (migrations, tests) would then
// need a USING (true) policy that restricts nothing. Instead the owner is kept out of the runtime by credentials,
// and App\Database\RuntimeRole refuses to boot a web/consumer process whose connection owns tables.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            REVOKE ALL ON SCHEMA public FROM PUBLIC;
            GRANT USAGE ON SCHEMA public TO webform_api, webform_ingest, webform_writer;

            ALTER TABLE tenants       ENABLE ROW LEVEL SECURITY;
            ALTER TABLE api_keys      ENABLE ROW LEVEL SECURITY;
            ALTER TABLE forms         ENABLE ROW LEVEL SECURITY;
            ALTER TABLE form_versions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE submissions   ENABLE ROW LEVEL SECURITY;

            -- webform_api: control plane, scoped to set_config('app.tenant_id', ?, true) per transaction.
            -- WHY: NULLIF — after a transaction-local set_config the setting reads '' (not NULL) for the rest of the
            -- session, and ''::uuid would raise instead of matching nothing.
            GRANT SELECT, INSERT, UPDATE ON forms         TO webform_api;
            GRANT SELECT, INSERT         ON form_versions TO webform_api;
            GRANT SELECT                 ON submissions   TO webform_api;

            CREATE POLICY api_tenant ON forms TO webform_api
                USING      (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
            CREATE POLICY api_tenant ON form_versions TO webform_api
                USING      (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
            CREATE POLICY api_tenant ON submissions FOR SELECT TO webform_api
                USING      (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

            -- API keys are never readable by the app; a definer function resolves a hash to a tenant.
            CREATE OR REPLACE FUNCTION resolve_api_key(p_key_hash text) RETURNS uuid
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
                SELECT tenant_id FROM api_keys WHERE key_hash = p_key_hash
            $$;
            REVOKE EXECUTE ON FUNCTION resolve_api_key(text) FROM PUBLIC;
            GRANT  EXECUTE ON FUNCTION resolve_api_key(text) TO webform_api;

            -- webform_ingest: public read path, published forms and their versions of any tenant. On forms only the
            -- columns needed to find the current version; never the draft.
            GRANT SELECT (id, tenant_id, status, current_version_id) ON forms TO webform_ingest;
            GRANT SELECT ON form_versions TO webform_ingest;

            CREATE POLICY ingest_published ON forms FOR SELECT TO webform_ingest
                USING (status = 'published');
            CREATE POLICY ingest_published ON form_versions FOR SELECT TO webform_ingest
                USING (EXISTS (SELECT 1 FROM forms f WHERE f.id = form_versions.form_id AND f.status = 'published'));

            -- webform_writer: the consumer. Inserts for every tenant, reads nothing but the ids it just wrote.
            GRANT INSERT             ON submissions    TO webform_writer;
            GRANT INSERT, SELECT (id) ON submission_ids TO webform_writer;

            CREATE POLICY writer_insert ON submissions FOR INSERT TO webform_writer WITH CHECK (true);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS resolve_api_key(text);
            REVOKE ALL ON ALL TABLES IN SCHEMA public FROM webform_api, webform_ingest, webform_writer;
            REVOKE USAGE ON SCHEMA public FROM webform_api, webform_ingest, webform_writer;
        SQL);
    }
};
