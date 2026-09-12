# CLAUDE.md

Take-home assessment: multi-tenant webform builder + submission platform.
Graders weight ARCHITECTURE.md most; code is a proof-of-execution slice.
The author must be able to explain every line in a follow-up interview.
Optimize for: the invariants below holding, clarity, small surface area. Not feature count.

## Working rules

- Never run `git commit` or `git push` (also denied in .claude/settings.json). When a task is done: stop, summarize, propose one Conventional Commit message. I review the diff and commit.
- One task = one commit. Don't edit files outside the task's scope. Don't refactor unrelated code.
- Ask before adding any Composer or npm dependency: say what it's for and what we'd do without it.
- Don't build beyond the task. Put ideas under "Follow-ups" in your summary.
- Never put numbers (latency, RPS, sizes, test counts) in docs unless produced by a command run in this repo. Cite the command.
- Comments: only `// WHY:` for non-obvious decisions, and `// I<n>` where code enforces an invariant.
- Idiomatic, boring Laravel. No repository-pattern layers, no packages for things Laravel already does.
- Eloquent is fine for the control plane. Hot paths (ingest, consumer, export) use the query builder or raw SQL, never per-row Eloquent models.
- Tests run against real PostgreSQL, never SQLite (SQLite has no JSONB, RLS, or partitions, so tests would pass for the wrong reason). Integration tests for I1, I2, I3, I11 hit real Postgres/Redpanda from docker compose; don't mock the component under test.
- Tests that involve the consumer process use `DatabaseTruncation`, not `RefreshDatabase` (a wrapping transaction is invisible to other processes).
- If a diff would exceed ~400 lines, stop and propose a split first.
- Every task summary ends with:
  1. What changed (one line per file)
  2. Decisions made + alternative rejected (one line each)
  3. What you're unsure about or didn't test

## Stack (decided; don't change without asking)

- PHP 8.4, Laravel (latest), Laravel Octane on FrankenPHP (persistent workers; no per-request framework boot)
- PostgreSQL 16. Partitioning, RLS, triggers via `DB::statement` in migrations (schema builder doesn't support them)
- Redpanda (Kafka API), single node locally; PHP `rdkafka` extension (+ `mateusjunges/laravel-kafka` only if it doesn't hide flush/delivery results)
- Redis 7: Laravel `RateLimiter` (cache-backed)
- Pest for tests
- Public form page: Blade server-render + small vanilla JS module (conditional logic, client validation, retries), built with Vite
- Load generator: Node + undici (needs per-request ID capture for reconciliation)

## Deployment shape (one codebase, three processes in docker compose)

```
api       Octane, APP_ROLE=api     control plane: API-key auth, forms, drafts, publish,
                                   submission list/filter, CSV export        (:8000)
ingest    Octane, APP_ROLE=ingest  public data plane: form page, definition JSON,
                                   POST submissions -> Redpanda              (:8080, separate origin)
consumer  php artisan submissions:consume   Redpanda -> Postgres batch writer
```

Routes are registered per APP_ROLE so a burst on ingest can't starve the dashboard.

## Code layout

```
app/Forms/DefinitionRules.php      validates a form definition at save
app/Forms/Visibility.php           evaluates visibleIf conditions
app/Forms/SubmissionValidator.php  visibility -> strip hidden -> strict type/rule checks -> ValidationResult
                                   pure PHP, no Laravel Validator (loose typing would break PHP/JS parity)
app/Forms/PublishCompat.php        I5: a field id keeps its type across every published version
app/Http/Middleware/AuthenticateApiKey.php  Bearer key -> resolve_api_key() -> scoped TenantContext, request transaction + set_config
app/Http/Controllers/FormController.php     /v1/forms: create, list (keyset), show, draft, publish, versions
app/Tenancy/                       TenantContext (scoped, I11), ApiKey (wf_ + 32 bytes base62; sha256 stored)
app/Ingest/VersionStore.php        I12: worker LRU -> Redis -> Postgres for versions (immutable) and form state (stale-ok)
app/Ingest/RenderToken.php         I13: HMAC(form|version|issued_at) with RENDER_TOKEN_KEY
app/Http/Controllers/Public/       ingest: definition JSON (immutable), current-version pointer, form page
resources/views/form/page.blade.php  server-rendered fields + JSON data block + render token; Cache-Control: no-store
resources/js/form/validate.js      pure port of the PHP validator; tests/js runs every conformance case through it
resources/js/form/render.js        live visibility, error display, typed value collection (textContent/setAttribute only)
resources/js/form/patterns.js      pattern checks in a Web Worker (pattern-worker.js), 50 ms budget, skip on timeout (I8)
app/Forms/SafePattern.php          customer regex evaluation (I8)
app/Ingest/SubmissionProducer.php  produce + flush + delivery check (I1)
app/Console/Commands/ConsumeSubmissions.php
conformance/*.json                 {definition, input, expectedErrors} fixtures, run by BOTH
                                   the Pest suite and a JS test, so client and server can't drift
loadtest/                          burst.mjs, reconcile.mjs, chaos.sh
scripts/demo.sh                    seeds a tenant + form, publishes, prints public URL and curl examples
```

## Data model

```
tenants(id, name)
api_keys(id, tenant_id, key_hash, created_at)                 -- sha256 of key, never plaintext
forms(id, tenant_id, name, draft jsonb, current_version_id, status)
form_versions(id, form_id, tenant_id, version_no, definition jsonb, published_at)  -- immutable
submissions(id uuid, tenant_id, form_id, form_version_id, data jsonb, meta jsonb, received_at)
    PARTITION BY RANGE (received_at), PRIMARY KEY (id, received_at)
    FK (form_version_id, form_id, tenant_id) -> form_versions(id, form_id, tenant_id)  -- I3
    monthly partitions submissions_YYYY_MM (artisan submissions:ensure-partitions, idempotent)
    + submissions_default so an out-of-range row is never a failed insert
submission_ids(id uuid PRIMARY KEY, received_at)
    -- global dedupe: a partitioned table's unique keys must include the partition key,
    -- so id alone can't be unique on `submissions`. No tenant data, no RLS.
```

UUIDs are app-generated (PG16 has no uuidv7()). `received_at` is set by ingest at acceptance and carried in the message, never defaulted in the DB. Schema is raw SQL in migrations (partitioning, composite FKs, RLS, triggers).

Indexes: `submissions (tenant_id, form_id, received_at DESC, id DESC)` for keyset pagination; `GIN (data jsonb_path_ops)` for exact-match filters. `received_at` is server-set; never order by the client-generated id.

Roles (all non-superuser, none BYPASSRLS; created by `docker/postgres/init.sh`, grants and policies in the migration; RLS ENABLED on tenants, api_keys, forms, form_versions, submissions — not FORCED, see I11):

```
webform_owner   owns tables, runs migrations, tests and seeds; never a runtime role (App\Database\RuntimeRole enforces it)
webform_api     api: SELECT/INSERT/UPDATE forms, SELECT/INSERT form_versions, SELECT submissions,
                policy tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid — unset => zero rows;
                api_keys only via SECURITY DEFINER resolve_api_key(key_hash)
webform_ingest  ingest: SELECT forms (id, tenant_id, status, current_version_id only) and form_versions where the
                form is published, any tenant; nothing else
webform_writer  consumer: INSERT submissions (any tenant), INSERT + SELECT(id) submission_ids; cannot read submissions
```

HTTP caching: `GET /v1/forms/{form}/versions/{version}` (definition JSON) -> `Cache-Control: public, max-age=31536000, immutable`. Current-version pointer -> short max-age.

## Invariants (each needs a test that fails if it's broken)

- **I1 Ack after durability.** rdkafka `produce()` only queues in local memory. Ingest must `flush()` with a bounded timeout, confirm the delivery report has no error, and only then return 202. Producer config: `acks=all`, `enable.idempotence=true`, bounded `message.timeout.ms`. Flush/delivery failure -> 503 + Retry-After. No code path returns 202 otherwise.
- **I2 Idempotency.** Submission id is a client-generated UUIDv7, reused on retry. Consumer, in one transaction: insert ids into `submission_ids ON CONFLICT DO NOTHING RETURNING id`, insert `submissions` only for returned ids. `enable.auto.commit=false`; commit offsets only after the DB transaction commits.
- **I3 Version pinning.** A submission carries `form_version_id` and is validated against that version, not the current one. Accept if it's current or was superseded < 24h ago; else 409 with the current version id. The version must belong to the form in the URL.
- **I4 Immutability.** `form_versions` rows are never updated or deleted (DB trigger raises).
- **I5 Stable field ids.** Answers keyed by field id, never label. Field ids are server-generated and must match `^[a-z0-9_]{1,40}$`: they become CSV headers and JSONB paths, so `.`, `*`, and case variants would need escaping everywhere they appear. Publish rejects a draft where an existing field id changed type relative to any prior version.
- **I6 Conditional logic.** A condition may only reference fields earlier in the form (no cycles by construction). Visibility is evaluated first; hidden fields are stripped from input and get no rules.
- **I7 Strict input.** Unknown field ids -> 422. Only validated, visible fields are stored (never `$request->all()`). Body size limit enforced.
- **I8 ReDoS.** PCRE backtracks. `SafePattern` lowers `pcre.backtrack_limit` around the call, treats `preg_match` returning `false` as a validation failure (not a 500), caps pattern and input length. Publish rejects patterns with backreferences or lookaround.
- **I9 XSS.** Blade uses `{{ }}` only, never `{!! !!}`. The definition is embedded as `<script type="application/json" id="form-definition">@json(...)</script>`: a data block is never executed, so the CSP allows it, and `@json` hex-escapes `< > & ' "` so nothing can close it (not `Js::from()`, which needs an inline script the CSP forbids). JS uses `textContent` / `setAttribute` only, never `innerHTML`. Form page CSP: `default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; worker-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors *` (embeddable by design, so no `X-Frame-Options`), plus `X-Content-Type-Options: nosniff` and `Referrer-Policy: no-referrer`; JS/CSS are same-origin Vite builds, nothing inline. Labels and help text are plain text.
- **I10 CSV injection.** Export prefixes cells beginning with `= + - @` tab or CR with `'`.
- **I11 Tenant isolation.** Tenant resolved from API key (via `resolve_api_key`) in middleware and bound with `app()->scoped()` (Octane resets scoped bindings per request; a plain singleton would leak tenant context to the next request). Every query filters by tenant_id (global scope). Also RLS (ENABLED, role-specific policies, never BYPASSRLS): each process connects as its own least-privilege role (`webform_api`, `webform_ingest`, `webform_writer`); `webform_owner` runs migrations/tests only. RLS is not FORCED: FORCE binds only the table owner, and the owner would then need a `USING (true)` policy that restricts nothing — so the owner is kept out of the runtime by credentials, and `App\Database\RuntimeRole` refuses any process whose connection is the wrong role, superuser, BYPASSRLS, or owns a table (api and consumer at boot; ingest on its first connection, so it can start while Postgres is down). Tenant set per transaction with `select set_config('app.tenant_id', ?, true)`: `AuthenticateApiKey` wraps the whole request in one transaction and sets it first, so any query outside that transaction sees zero rows. Streamed responses (CSV export) run after the middleware's transaction has ended, so they must open their own transaction and call `set_config` again. Never session-level `SET`: Octane reuses connections. Cross-tenant access -> 404, not 403.
- **I12 Degraded dependencies.** Redis down -> rate limiter fails open to an in-process limiter (catch, don't 500). Postgres down -> ingest keeps accepting for forms whose version is cached in worker memory. Consumer down -> submissions accumulate in the broker.
- **I13 Spam.** Honeypot field. Minimum fill time via an HMAC-signed render token embedded in the page (client can't forge the timestamp). Rate limits per IP+form, per form, per tenant.
- **I14 Partitioning.** Kafka message key = submission id, so one hot form spreads across partitions.
- **I15 Memory-safe export.** Export streams with `response()->streamDownload()` over keyset chunks of 1000 on `(received_at, id)`. Never `->get()` or `->cursor()` on the full set (PDO pgsql buffers entire result sets client-side).

## Commands

```
docker compose up --build              full stack
docker compose exec api php artisan test
cd loadtest && node burst.mjs          burst + acked-id capture
cd loadtest && node reconcile.mjs      reconciliation report
./loadtest/chaos.sh                    burst while stopping consumer/postgres/broker, then reconcile
./scripts/demo.sh                      tenant + demo form (every type, visibility chain), published; prints page URL and key
make tenant name="Acme"                tenant + API key, printed once (runs tenants:create as the owner role)
```