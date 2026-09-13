<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Self-service accounts and API key lifecycle. Users are not tenant data (one user may belong to several tenants
// later), so `users` has no RLS; `tenant_users` and `api_keys` are tenant rows and get the api policy.
// WHY: webform_api's new grants are the minimum for signup, login, membership checks and key management —
// it can read every api_keys column except key_hash, and can never read another tenant's membership.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS citext;

            CREATE TABLE users (
                id                uuid PRIMARY KEY,
                email             citext NOT NULL UNIQUE,
                password_hash     text NOT NULL,
                email_verified_at timestamptz,
                created_at        timestamptz NOT NULL DEFAULT now(),
                updated_at        timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE tenant_users (
                tenant_id  uuid NOT NULL REFERENCES tenants (id),
                user_id    uuid NOT NULL REFERENCES users (id),
                role       text NOT NULL CHECK (role IN ('owner', 'member')),
                created_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (tenant_id, user_id)
            );
            CREATE INDEX tenant_users_user_id_idx ON tenant_users (user_id);

            ALTER TABLE api_keys
                ADD COLUMN prefix       text NOT NULL DEFAULT '',
                ADD COLUMN last_used_at timestamptz,
                ADD COLUMN revoked_at   timestamptz;

            ALTER TABLE tenant_users ENABLE ROW LEVEL SECURITY;

            -- users: no tenant column, no policy; the api role reads them to log people in and verify addresses.
            GRANT SELECT, INSERT, UPDATE ON users TO webform_api;

            -- tenants: signup inserts the tenant it is about to own, inside a transaction whose app.tenant_id is
            -- already that new id, so the same predicate that guards reads guards the insert.
            GRANT SELECT, INSERT ON tenants TO webform_api;
            CREATE POLICY api_tenant ON tenants TO webform_api
                USING      (id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

            -- tenant_users: a membership list is visible only inside its tenant. No UPDATE/DELETE yet: nothing
            -- in the slice removes or promotes members.
            GRANT SELECT, INSERT ON tenant_users TO webform_api;
            CREATE POLICY api_tenant ON tenant_users TO webform_api
                USING      (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

            -- api_keys: list, create and revoke within the tenant; key_hash stays unreadable — only
            -- resolve_api_key() ever compares it. last_used_at is written by that function, not by the role.
            GRANT SELECT (id, tenant_id, prefix, created_at, last_used_at, revoked_at), INSERT, UPDATE (revoked_at)
                ON api_keys TO webform_api;
            CREATE POLICY api_tenant ON api_keys TO webform_api
                USING      (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

            -- A revoked key resolves to nothing. Use is recorded here because this is the only code that sees the
            -- hash; at most once a minute per key so a busy key is not a write per request.
            CREATE OR REPLACE FUNCTION resolve_api_key(p_key_hash text) RETURNS uuid
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE
                v_tenant uuid;
            BEGIN
                SELECT tenant_id INTO v_tenant FROM api_keys WHERE key_hash = p_key_hash AND revoked_at IS NULL;

                IF v_tenant IS NOT NULL THEN
                    UPDATE api_keys SET last_used_at = now()
                    WHERE key_hash = p_key_hash AND (last_used_at IS NULL OR last_used_at < now() - interval '1 minute');
                END IF;

                RETURN v_tenant;
            END
            $$;

            -- Login happens before any tenant is set, so the api role cannot see tenant_users then; a definer
            -- function answers the one question login needs, like resolve_api_key does for keys.
            CREATE OR REPLACE FUNCTION resolve_user_tenants(p_user_id uuid) RETURNS SETOF uuid
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
                SELECT tenant_id FROM tenant_users WHERE user_id = p_user_id ORDER BY created_at, tenant_id
            $$;
            REVOKE EXECUTE ON FUNCTION resolve_user_tenants(uuid) FROM PUBLIC;
            GRANT  EXECUTE ON FUNCTION resolve_user_tenants(uuid) TO webform_api;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS resolve_user_tenants(uuid);
            CREATE OR REPLACE FUNCTION resolve_api_key(p_key_hash text) RETURNS uuid
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
                SELECT tenant_id FROM api_keys WHERE key_hash = p_key_hash
            $$;
            DROP POLICY IF EXISTS api_tenant ON api_keys;
            DROP POLICY IF EXISTS api_tenant ON tenants;
            REVOKE ALL ON api_keys, tenants FROM webform_api;
            ALTER TABLE api_keys DROP COLUMN prefix, DROP COLUMN last_used_at, DROP COLUMN revoked_at;
            DROP TABLE IF EXISTS tenant_users, users CASCADE;
        SQL);
    }
};
