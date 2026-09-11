<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- received_at is set by ingest at acceptance and travels in the message; never defaulted here.
            CREATE TABLE submissions (
                id              uuid NOT NULL,
                tenant_id       uuid NOT NULL,
                form_id         uuid NOT NULL,
                form_version_id uuid NOT NULL,
                data            jsonb NOT NULL,
                meta            jsonb NOT NULL DEFAULT '{}',
                received_at     timestamptz NOT NULL,
                PRIMARY KEY (id, received_at),
                -- I3: a submission can only claim a version that belongs to its own form and tenant.
                FOREIGN KEY (form_version_id, form_id, tenant_id)
                    REFERENCES form_versions (id, form_id, tenant_id)
            ) PARTITION BY RANGE (received_at);

            -- I15: keyset pagination and export order.
            CREATE INDEX submissions_tenant_form_received_idx
                ON submissions (tenant_id, form_id, received_at DESC, id DESC);
            CREATE INDEX submissions_data_idx ON submissions USING gin (data jsonb_path_ops);

            -- WHY: a row outside every monthly partition must still insert; failing it would lose a submission
            -- that the broker already acked. submissions:ensure-partitions keeps the months ahead of time.
            CREATE TABLE submissions_default PARTITION OF submissions DEFAULT;

            -- I2: the partitioned table's PK must include received_at, so global uniqueness of id lives here.
            CREATE TABLE submission_ids (
                id          uuid PRIMARY KEY,
                received_at timestamptz NOT NULL
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS submission_ids, submissions CASCADE');
    }
};
