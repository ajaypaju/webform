<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A dashboard session started by pasting a key must die when that key is revoked. The session needs the key's id
// for the per-request check, and only a definer function can turn a hash into an id (key_hash is unreadable).
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION resolve_api_key_id(p_key_hash text) RETURNS uuid
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
                SELECT id FROM api_keys WHERE key_hash = p_key_hash AND revoked_at IS NULL
            $$;
            REVOKE EXECUTE ON FUNCTION resolve_api_key_id(text) FROM PUBLIC;
            GRANT  EXECUTE ON FUNCTION resolve_api_key_id(text) TO webform_api;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS resolve_api_key_id(text);');
    }
};
