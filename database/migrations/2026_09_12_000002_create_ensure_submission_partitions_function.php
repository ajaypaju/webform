<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// WHY: only the parent's owner may attach partitions, but the consumer (webform_writer) must be able to keep the
// months ahead of time. A definer function, like resolve_api_key, lends exactly that one capability.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ensure_submission_partitions(months integer) RETURNS SETOF text
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE
                first date := date_trunc('month', now() AT TIME ZONE 'UTC')::date;
                lo date;
                name text;
            BEGIN
                FOR i IN 0 .. months - 1 LOOP
                    lo := first + make_interval(months => i);
                    name := 'submissions_' || to_char(lo, 'YYYY_MM');

                    IF to_regclass('public.' || name) IS NULL THEN
                        EXECUTE format('CREATE TABLE public.%I PARTITION OF public.submissions FOR VALUES FROM (%L) TO (%L)',
                                       name, lo, lo + interval '1 month');
                    END IF;

                    RETURN NEXT name;
                END LOOP;
            END
            $$;

            REVOKE EXECUTE ON FUNCTION ensure_submission_partitions(integer) FROM PUBLIC;
            GRANT  EXECUTE ON FUNCTION ensure_submission_partitions(integer) TO webform_writer;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS ensure_submission_partitions(integer)');
    }
};
