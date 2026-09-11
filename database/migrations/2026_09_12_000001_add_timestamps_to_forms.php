<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE forms
                ADD COLUMN created_at timestamptz NOT NULL DEFAULT now(),
                ADD COLUMN updated_at timestamptz NOT NULL DEFAULT now();

            -- Keyset order for GET /v1/forms; replaces the plain tenant_id index.
            DROP INDEX forms_tenant_id_idx;
            CREATE INDEX forms_tenant_created_idx ON forms (tenant_id, created_at DESC, id DESC);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX forms_tenant_created_idx;
            CREATE INDEX forms_tenant_id_idx ON forms (tenant_id);
            ALTER TABLE forms DROP COLUMN created_at, DROP COLUMN updated_at;
        SQL);
    }
};
