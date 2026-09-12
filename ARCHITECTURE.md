# Architecture

Version 1. Every capacity figure is an assumption; nothing here is measured. Values that need a load test are written "TBD (load test)".

## 1. Overview

A multi-tenant webform service. Tenants author form definitions through an API-key-authenticated control plane (`api` role: forms, drafts, publish, submission list, CSV export). The public data plane (`ingest` role) serves the form page and versioned definition JSON, validates submissions, and writes each accepted one to a Redpanda topic before returning 202. A consumer process drains the topic into PostgreSQL in batches, deduplicating on the client-generated id. Both HTTP roles run the same image with a different `APP_ROLE` on different origins, so a public burst cannot starve the dashboard and the control plane is unreachable from the public origin.

## 2. Assumptions and capacity

| Quantity | Value | Label |
|---|---|---|
| Tenants | 500 | assumption |
| Forms per tenant | 20 (10,000 total) | assumption |
| Steady submission rate | 20/s | assumption |
| Burst | 2,000/s for 10 minutes, one or a few forms, once per day | assumption |
| Payload | 2 KB validated `data` + 0.5 KB `meta` = 2.5 KB stored | assumption |
| Definition size | up to 100 fields, ~30 KB JSON | assumption |
| Retention | 13 months online, then archive | assumption |

Derived, line by line:

- Steady rows/day = 20/s × 86,400 s = 1,728,000.
- Burst rows/day = 2,000/s × 600 s × 1 burst = 1,200,000.
- Rows/day = 1,728,000 + 1,200,000 = 2,928,000 ≈ 2.9 M.
- Heap/day = 2,928,000 × 2.5 KB = 7,320,000 KB ≈ 7.3 GB.
- Indexes (primary key, tenant/form/time, GIN) at an assumed 50% overhead: 7.3 × 1.5 ≈ 11 GB/day.
- Storage/year = 11 GB × 365 ≈ 4.0 TB; rows/year = 2,928,000 × 365 ≈ 1.07 B. Monthly partitions of ≈ 330 GB are the unit of archival.
- Steady broker ingress = 20/s × 2.5 KB = 50 KB/s; daily-burst ingress = 2,000/s × 2.5 KB = 5 MB/s, 15 MB/s of cluster write traffic at RF=3 (designed). Bandwidth is not the constraint; fsync latency at `acks=all` is. Ack latency: TBD (load test).
- Definition reads are served from the CDN (designed) with `immutable`, so `ingest` read load is bounded by cache misses.

### Burst envelope

Ingest and the broker scale horizontally and are sized for the spike; PostgreSQL is sized for the consumer drain rate, and the topic is the buffer between the two. With ingress rate `R` for duration `T`, steady rate `S`, and consumer drain rate `D` (TBD (load test); the consumer batches inserts, so `D` is bounded by PostgreSQL write throughput, not by broker reads):

- ingest instances = ⌈R / per-instance rate⌉, per-instance rate TBD (load test)
- backlog = (R − D) × T
- drain time = backlog / (D − S)
- requirement: topic retention ≫ drain time, and `D > S` with margin or the backlog never clears

Worked example (assumption: R = 10,000/s for T = 600 s, S = 20/s; illustrative D = 3,000/s, not measured):

- backlog = (10,000 − 3,000) × 600 = 4,200,000 rows
- drain time = 4,200,000 / (3,000 − 20) ≈ 1,409 s ≈ 23.5 minutes
- topic retention 7 days = 604,800 s, about 430× the drain time: requirement met
- broker ingress during the spike = 10,000/s × 2.5 KB = 25 MB/s; at RF=3 that is 75 MB/s of cluster write traffic (25 leader + 50 replication) and 15 GB of spike data per replica, 45 GB across the cluster
- the dashboard lags by up to the drain time; nothing is lost, because every 202 was preceded by a broker fsync

## 3. Component diagram

Solid edges exist in the repository. Dashed edges and dashed nodes are designed only.

```mermaid
flowchart LR
    B[Browser: form page + form.js]
    CDN[CDN]:::designed
    ING[ingest role<br/>Octane / FrankenPHP]
    API[api role<br/>Octane / FrankenPHP]
    RP[(Redpanda<br/>submissions, 12 partitions)]
    CONS[consumer<br/>artisan submissions:consume]:::designed
    PG[(PostgreSQL 16<br/>partitioned submissions, RLS)]
    RD[(Redis 7<br/>rate limits)]
    OS[(Object storage<br/>archived partitions, exports)]:::designed
    CH[(ClickHouse<br/>analytics via CDC)]:::designed
    T[Tenant dashboard / API client]

    B -.->|GET page, definition JSON| CDN
    CDN -.->|cache miss| ING
    B -->|POST submission| ING
    ING -->|produce, flush, delivery report| RP
    ING -.->|rate limit| RD
    ING -.->|version lookup, cached in worker| PG
    RP -.->|batch consume| CONS
    CONS -.->|dedupe + insert, then commit offset| PG
    T -->|API key| API
    API -.->|forms, versions, submissions, export| PG
    PG -.->|CDC| CH
    PG -.->|detach + archive| OS
    API -.->|stream export| OS

    classDef designed stroke-dasharray: 5 5
```

## 4. Submission lifecycle

```mermaid
sequenceDiagram
    participant C as Browser
    participant I as ingest (Octane worker)
    participant R as Redpanda
    participant K as consumer
    participant P as PostgreSQL

    C->>I: GET /f/{form} (page + HMAC render token, definition as a JSON data block)
    I->>I: VersionStore: worker LRU -> Redis -> PostgreSQL (I12)
    I-->>C: 200, CSP, nosniff, no-store
    C->>C: client validation (same conformance rules); id = UUIDv7, kept in localStorage until acked
    C->>I: POST /v1/forms/{form}/submissions {submission_id, form_version_id, render_token, data, honeypot}
    I->>I: 1 rate limits (Redis buckets, per-worker fallback) -> 429
    I->>I: 2 honeypot / token HMAC, age 2s..24h -> decoy 202, logged, never produced (I13)
    I->>I: 3 version belongs to form and is current or superseded < 24h -> 404 / 409 (I3)
    I->>I: 4 visibility -> strip hidden -> strict checks against that version (I6, I7)
    alt validation fails
        I-->>C: 422 {field: [codes]}
    end
    I->>I: received_at = now (fixed here); envelope {v, ids, received_at, data, meta{ip_hash, ua, referer host}}
    I->>R: produce(key=submission_id, envelope) — local queue only (I14)
    I->>R: flush(bounded timeout)
    R-->>I: delivery report, err = 0
    Note over R: durability begins here: record fsync'd by the leader with acks=all (RF=3 designed: on 2 of 3 replicas)
    I-->>C: 202 {submission_id, received_at}
    C->>C: clear localStorage, show thanks
    alt broker slow or down
        R--xI: no report within message.timeout.ms (after N failures the breaker answers without trying)
        I-->>C: 503 Retry-After
        C->>I: retry with the same id (backoff + jitter, Retry-After honoured, survives a reload)
        I->>R: produce, flush, report
        I-->>C: 202
        Note over R,P: if the first produce did land, the topic now holds the id twice
    end
    R->>K: batch of records (enable.auto.commit=false)
    K->>P: BEGIN; INSERT submission_ids ON CONFLICT DO NOTHING RETURNING id
    K->>P: INSERT submissions only for returned ids; COMMIT
    Note over K,P: duplicate ids collapse here (I2)
    K->>R: commit offsets (only after COMMIT)
```

The ambiguous ack is the case that matters: the client cannot tell "not produced" from "produced, report lost". Both resolve the same way because the retry reuses the id and `submission_ids` is a global unique key.

Reads before the submit: `GET /v1/forms/{form}/versions/{version}` is `Cache-Control: public, max-age=31536000, immutable` (versions never change), `GET /v1/forms/{form}` is `max-age=30, stale-while-revalidate=60`, and `GET /f/{form}` is `no-store` because it embeds a per-render token. Designed: a CDN-cacheable page shell plus a tiny token endpoint, so the HTML itself becomes cacheable and only the token round-trips to `ingest`.

The order of checks is by cost: rate limits touch only Redis (or worker memory), the token check is a local HMAC, version resolution reads the form state the page load already cached, and only then does validation and the broker round-trip happen. The consumer is the next slice: today submissions are durable on the topic but not yet in PostgreSQL.

## 5. Requirements

### 5.1 Bursty load

Mechanism: no database write on the request path; the only synchronous dependency is the broker ack. Octane keeps the framework booted, a per-worker `Producer` singleton keeps broker connections open, and the message key is the submission id so a hot form spreads across all 12 partitions (I14). Per-worker singletons are resolved at worker boot through Octane's `warm` list: a singleton first resolved inside a request belongs to that request's sandbox container and does not survive it — found on the running stack when the breaker failed to open, not by the test suite. Backpressure is explicit: no ack within `message.timeout.ms` means 503 + `Retry-After`, never a queued-in-memory 202.
A per-worker circuit breaker ([app/Ingest/SubmissionProducer.php](app/Ingest/SubmissionProducer.php)) opens after N consecutive delivery failures and answers 503 without a broker round-trip for a cooldown, then lets one request probe — otherwise a burst during a broker outage pins every worker on `message.timeout.ms`. A fatal librdkafka error recreates the producer; an idempotent producer stays broken otherwise.
Evidence: [tests/Feature/Kafka/ProducerTest.php](tests/Feature/Kafka/ProducerTest.php); breaker open/probe/close, re-open on a failed probe, fatal recreate, and the HTTP path returning 503 never 202 while the broker is down — [tests/Feature/Ingest/SubmissionProducerTest.php](tests/Feature/Ingest/SubmissionProducerTest.php), [SubmissionsTest.php](tests/Feature/Ingest/SubmissionsTest.php); burst throughput and latency: TBD (load test), `loadtest/burst.mjs` planned.

### 5.2 No lost submissions

Mechanism (I1, I2): `produce()` only enqueues locally, so `send()` calls `flush()` with a bounded timeout and then reads this message's own delivery report by opaque token; any error, timeout or missing report throws `DeliveryFailed` → 503. No code path returns 202 without a clean report. Producer: `acks=all`, `enable.idempotence=true`, bounded `message.timeout.ms`. `redpanda-init` forces `write_caching_default=false` and refuses to start the stack otherwise, so an ack means fsync. The consumer commits offsets only after its transaction commits; a crash in between replays the batch and `submission_ids` collapses it.
Spam (honeypot, bad or too-fresh or expired render token) receives a decoy 202 with a fresh id and is never produced (I13). That is a deliberate drop, and it is recorded: the `submission dropped` log line (info level, so no default filter hides it) with the reason is the audit trail behind this claim; nothing is dropped silently.
Evidence: producer — [tests/Feature/Kafka/ProducerTest.php](tests/Feature/Kafka/ProducerTest.php); endpoint: 202 only with the exact envelope on the topic, 503 with nothing acked while the broker is down, decoys produce nothing — [tests/Feature/Ingest/SubmissionsTest.php](tests/Feature/Ingest/SubmissionsTest.php); client retry with the same id — [tests/js/submit.test.mjs](tests/js/submit.test.mjs); broker config — [compose.yaml](compose.yaml); consumer and reconciliation: pending.

### 5.3 Tenant isolation

Mechanism (I11), three layers: tenant resolved from the API key hash in middleware and bound with `app()->scoped()`, which Octane resets per request (a singleton would leak the previous tenant into the next request on the same worker); a tenant global scope on every Eloquent query; PostgreSQL RLS `ENABLED` on every table holding tenant data, with the tenant set by `set_config('app.tenant_id', ?, true)` per transaction (a session-level `SET` would outlive the request on a reused connection). Each process connects as its own non-superuser role and gets only its policies; nothing uses `BYPASSRLS`:

| Role | Used by | Grants | Policy |
|---|---|---|---|
| `webform_owner` | migrations, tests | owns everything | none — never a runtime role, enforced by `RuntimeRole` |
| `webform_api` | `api` | S/I/U `forms`, S/I `form_versions`, S `submissions`, EXECUTE `resolve_api_key` | `tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid`, `USING` and `WITH CHECK`; unset → zero rows |
| `webform_ingest` | `ingest` | S `forms (id, tenant_id, status, current_version_id)`, S `form_versions` | form `status = 'published'`, any tenant; the draft column is not granted |
| `webform_writer` | consumer | I `submissions`, I + S(id) `submission_ids` | `WITH CHECK (true)` on insert; cannot read submissions |

RLS is deliberately not `FORCED`. `FORCE` binds only the table owner; with the owner running migrations and tests it would need a `USING (true)` policy, and `FORCE` plus an allow-all policy restricts nothing while suggesting it does. The owner is kept out of the runtime by credentials instead, and [app/Database/RuntimeRole.php](app/Database/RuntimeRole.php) verifies them: the connection's `current_user` must be the role expected for `APP_ROLE`, and must not be superuser, `BYPASSRLS`, or the owner of any table in `public`. `api` and the consumer check at boot and refuse to start; `ingest` checks on `ConnectionEstablished` — dispatched inside `DatabaseManager::connection()` before the connection is returned, so the first query cannot run unchecked — because the data plane must start while PostgreSQL is down (I12). A failed check purges the connection (otherwise the worker would keep an unchecked one) and writes the reason to the process's stderr.

`api_keys` is readable by no app role; `resolve_api_key(key_hash)` is `SECURITY DEFINER` with a pinned `search_path`, executable by `webform_api` only. Cross-tenant ids return 404.
The control plane's `AuthenticateApiKey` middleware hashes the bearer token, resolves it with `resolve_api_key`, binds `TenantContext` with `app()->scoped()`, and runs the rest of the request inside one transaction that starts with `set_config`; route-model binding is ordered after it so lookups already see the tenant. A 401 has one body for missing and wrong keys. Eloquent models carry a `TenantScope` that throws when no context is bound (fail closed at the app layer too). Streamed responses outlive that transaction and must open their own (CLAUDE.md I11).
Evidence: [tests/Feature/Schema/ApiRoleTest.php](tests/Feature/Schema/ApiRoleTest.php), [IngestRoleTest.php](tests/Feature/Schema/IngestRoleTest.php), [WriterRoleTest.php](tests/Feature/Schema/WriterRoleTest.php), [RuntimeRoleTest.php](tests/Feature/Schema/RuntimeRoleTest.php) (each role on its own connection against real PostgreSQL); middleware, scoped rebinding across consecutive requests with different keys, fail-closed outside the transaction, and 404 for another tenant's form on every endpoint — [tests/Feature/Api/AuthTest.php](tests/Feature/Api/AuthTest.php), [FormsTest.php](tests/Feature/Api/FormsTest.php), run with the api role as the request connection. Break-tested: `WITH CHECK (true)` on the api policy fails `rejects an api insert that names another tenant`; granting ingest `forms.draft` fails `IngestRoleTest`; dropping the trigger fails `ImmutabilityTest`; pointing the api container at `webform_owner` makes it exit with `REFUSING TO SERVE`.

### 5.4 Correctness under failure

Mechanism (I12): every dependency has a defined degraded mode (section 9). Ingest may degrade to "accept and buffer", never to "accept and drop": Redis down → the rate limiter falls back to per-worker buckets and logs once a minute, the version store falls through to PostgreSQL; PostgreSQL down → ingest keeps serving from its version store; broker down → 503 with nothing acked, breaker open; consumer down → the topic absorbs the backlog. The version store ([app/Ingest/VersionStore.php](app/Ingest/VersionStore.php)) reads versions through a bounded per-worker LRU (workers are recycled by `--max-requests`, so memory alone is not enough), then Redis (no expiry; versions are immutable), then PostgreSQL, filling Redis on the way back. Form state (`status`, `current_version_id`, the versions list I3 needs) is cached 10 s in the worker and 60 s in Redis, and served stale from the worker when PostgreSQL errors. Publish writes both keys after its commit, so a fresh version is servable at once and the store is already warm if the database goes away before anyone reads it; a Redis failure there is logged, not fatal. Only a cold key with both PostgreSQL and Redis unreachable yields 503 + `Retry-After`.
Evidence: broker-down → 503 within the timeout — [tests/Feature/Kafka/ProducerTest.php](tests/Feature/Kafka/ProducerTest.php); page and version endpoints keep serving after PostgreSQL becomes unreachable, cold key with both down → 503, stale form state from worker memory, publish writes Redis and survives Redis being down — [tests/Feature/Ingest/VersionEndpointsTest.php](tests/Feature/Ingest/VersionEndpointsTest.php), [PageTest.php](tests/Feature/Ingest/PageTest.php), [tests/Feature/Api/FormsTest.php](tests/Feature/Api/FormsTest.php); rate limiter fallback — [tests/Feature/Ingest/RateLimiterTest.php](tests/Feature/Ingest/RateLimiterTest.php); consumer: pending.

### 5.5 Version integrity

Mechanism (I3, I4, I5): publish creates an immutable `form_versions` row (trigger raises on UPDATE/DELETE); a submission names the version it was rendered against and is validated against it, accepted while current or superseded < 24 h ago, else 409 with the current version id; field ids are server-generated `^[a-z0-9_]{1,40}$`, and a publish that changes an existing id's type is rejected (`PublishCompat`). `Cache-Control: immutable` on definition JSON is safe only because of this.
Publish runs in one transaction with the form row locked `FOR UPDATE`, requires `DefinitionRules` to pass, then `PublishCompat` against every prior version: an id that ever existed keeps its type, including one deleted in v2 and re-added in v3.
Evidence: id regex — [tests/Unit/Conformance/DefinitionsTest.php](tests/Unit/Conformance/DefinitionsTest.php); immutability trigger — [tests/Feature/Schema/ImmutabilityTest.php](tests/Feature/Schema/ImmutabilityTest.php); composite version FK — [tests/Feature/Schema/SubmissionsTableTest.php](tests/Feature/Schema/SubmissionsTableTest.php); `PublishCompat` fixtures — [tests/Unit/Conformance/PublishTest.php](tests/Unit/Conformance/PublishTest.php) (`conformance/publish/compat.json`); publish v1/v2 unchanged after draft edits and type-change rejection over HTTP — [tests/Feature/Api/FormsTest.php](tests/Feature/Api/FormsTest.php); pinning window: pending.

### 5.6 XSS and injection

Mechanism: definitions validated at save (`DefinitionRules`: id shape, types, options, rule values, regex portability); the page renders with `{{ }}` only and embeds the definition as a `<script type="application/json">` data block — never executed, so the CSP allows it, and `@json` hex-escapes `< > & ' "` so no value can close it; `render.js` uses `textContent`/`setAttribute`, never `innerHTML`, and never builds markup from strings; every public response carries `default-src 'none'; script-src 'self'; style-src 'self'; …; worker-src 'self'; base-uri 'none'; frame-ancestors *` (embeddable by design, so no `X-Frame-Options`), `nosniff` and `Referrer-Policy: no-referrer`, and JS/CSS are same-origin Vite builds with nothing inline (I9); the public origin carries no credential for the control plane. CSV export prefixes cells starting with `= + - @`, tab or CR with `'` (I10). Customer regex runs only through `SafePattern` (I8, section 7).
Evidence: [tests/Unit/Conformance/DefinitionsTest.php](tests/Unit/Conformance/DefinitionsTest.php); ReDoS — [tests/Unit/Conformance/SubmissionsTest.php](tests/Unit/Conformance/SubmissionsTest.php) (`redos_*`, 500 ms bound); hostile label and help text rendered inert, exact headers on every public response including 404s — [tests/Feature/Ingest/PageTest.php](tests/Feature/Ingest/PageTest.php); CSV: pending.

### 5.7 Dynamic-schema storage

Mechanism: one `submissions` table for all tenants and forms, answers in JSONB `data` keyed by stable field id, `form_version_id` pointing at the definition that gives the keys meaning (section 6).
Evidence: partitions and DEFAULT fallback — [tests/Feature/Schema/SubmissionsTableTest.php](tests/Feature/Schema/SubmissionsTableTest.php); the stored shape is fixed by `ValidationResult::$data` — [tests/Unit/Conformance/SubmissionsTest.php](tests/Unit/Conformance/SubmissionsTest.php).

## 6. Data model

```
tenants(id, name)
api_keys(id, tenant_id, key_hash, created_at)
forms(id, tenant_id, name, draft jsonb, current_version_id, status)
form_versions(id, form_id, tenant_id, version_no, definition jsonb, published_at)   -- immutable
submissions(id uuid, tenant_id, form_id, form_version_id, data jsonb, meta jsonb, received_at)
    PARTITION BY RANGE (received_at), PRIMARY KEY (id, received_at)
    FK (form_version_id, form_id, tenant_id) -> form_versions (id, form_id, tenant_id)
submission_ids(id uuid PRIMARY KEY, received_at)
```

Built as raw SQL in three migrations (`database/migrations/2026_09_11_*`). UUIDs are generated by the application. `form_versions` has `UNIQUE (id, form_id, tenant_id)` so a submission's composite FK ties it to a version of its own form and tenant (I3); a `BEFORE UPDATE OR DELETE` trigger makes versions immutable (I4). `forms.current_version_id` is a nullable FK back to `form_versions`, which resolves the circular reference: form, then version, then pointer.

**JSONB with stable ids, not EAV or table-per-form.** EAV multiplies rows by field count and turns "show one submission" into a pivot. Table-per-form means DDL on publish, ten thousand tables with their own RLS and indexes, and a migration per version. JSONB keeps one row per submission and one schema; `form_versions.definition` is the schema for reading each row. The cost: per-field range queries are not indexable in general (see the limit below).

**Partitioning.** `submissions` is range-partitioned by `received_at` (monthly, `submissions_YYYY_MM`): retention is `DETACH PARTITION` + archive, not a bloating `DELETE`. `submissions:ensure-partitions` (idempotent, run by the `migrate` service and later by a scheduler) keeps the current and next two months created; a `DEFAULT` partition catches anything outside them, because a failed insert of an already-acked submission would be data loss — a row landing there is an alert, not an error. `received_at` comes from the message (set by ingest at acceptance), never `now()` in the database, so a replayed batch lands in the same partition. A partitioned table's unique constraints must include the partition key, so `id` alone cannot be unique there; `submission_ids` is a small unpartitioned table whose primary key is the global dedupe point (I2), carrying `received_at` so it is pruned in step. It holds no tenant data and has no RLS.

**Indexes and the query each serves.**

| Index | Query |
|---|---|
| `submissions PK (id, received_at)` | fetch one submission; partition-local uniqueness |
| `submission_ids PK (id)` | consumer `INSERT ... ON CONFLICT DO NOTHING RETURNING id` |
| `submissions (tenant_id, form_id, received_at DESC, id DESC)` | dashboard list and CSV export, keyset paginated on `(received_at, id)`; `received_at` is server-set, so ordering by it is monotonic where the client-generated id is not |
| `GIN (data jsonb_path_ops)` | exact-match filter `data @> '{"field": "value"}'` |
| `api_keys (key_hash)` | auth lookup |

**The honest limit.** `jsonb_path_ops` serves containment only; "age > 30" over `data` is a partition scan narrowed by tenant and form. Fine for one form's recent partition, not an analytics product. Designed answer: CDC into ClickHouse with `data` exploded into typed columns per version; PostgreSQL stays the system of record.

## 7. Validation design

A definition is validated twice: `resources/js/form/validate.js` in the browser for feedback, `App\Forms\SubmissionValidator` on ingest as the authority. Both run against `conformance/`: each case states definition, input, expected error codes per field, and the exact object to store. Codes, not messages, so the two implementations are compared mechanically. Both pass every case: `tests/js/validate.test.mjs` runs all submission cases through `validate.js` and asserts `valid`, `errors` and `output` exactly — that is the parity proof.

**Why not Laravel's Validator.** It is loosely typed by design: `integer` accepts `"42"`, `date` uses `strtotime`, `email` is RFC-ish, `.`/`*` in a rule key means nesting — each a place where browser and server would disagree. The validator is pure PHP: `Normalizer` (JS `trim` set, code-point lengths, safe-integer float folding), `Visibility` (backward-only conditions, hidden values dropped before rules), `FieldChecks` (strict JSON types, one method per type), `SafePattern`, and `DefinitionRules` at save time.

**Regex safety.** PHP has no RE2, so backtracking is contained, not avoided: `SafePattern` wraps the pattern in a control-character delimiter (no escaping, meaning preserved), adds `Du`, lowers `pcre.backtrack_limit`/`pcre.recursion_limit` around the call (restored in `finally`), caps pattern and subject length, and maps `preg_match === false` to the `pattern` code, never an exception. `DefinitionRules` admits only the subset PCRE and JS (`u`) read identically: no backreferences, lookaround, atomic/possessive constructs, inline flags, PCRE-only escapes, POSIX classes, octal or lenient `{ } ]`; `\p{..}` limited to short General_Category names. `tests/js/patterns.test.mjs` compiles every accepted pattern with `new RegExp(p, 'u')`.

**Known semantic gaps** (`conformance/README.md`, documented, not fixed): JS `\s` includes Unicode spaces, PCRE without UCP is ASCII-only; `.` excludes `\r`, U+2028, U+2029 in JS but not PCRE; and JS has no backtrack limit (V8's linear fallback engine cannot be used because it does not support the `u` flag), so a catastrophic pattern would run for seconds in the browser. `render.js` therefore evaluates patterns in a Web Worker with a 50 ms budget and skips the client-side check on timeout — the field submits and the server decides; the parity suite skips the `redos_*` case for the same reason, server-side protection being proven by the PHP test. The server is authoritative, so the effect of the remaining gaps is client/server disagreement on exotic input, never bad stored data.

## 8. Technology choices

| Component | Choice | Why | Rejected alternative |
|---|---|---|---|
| Runtime | PHP 8.4, Laravel, Octane on FrankenPHP | Persistent workers remove per-request boot; the ingest hot path is a few hundred lines of pure PHP plus one broker round trip, so it scales horizontally on cheap CPU; Laravel pays off in the control plane (auth, routing, Blade) | Go/Node ingest: faster per core, but a second codebase for the same rules; the conformance suite makes parity a test, not a language choice |
| Buffer | Redpanda (Kafka API), `write_caching=false` | Durable, replayable buffer; partitions give parallel consumers; Kafka API keeps a managed cluster possible. Write caching (on by default in the image's developer mode) acks before fsync and would silently break I1, so the init job forces it off and asserts it | Direct PostgreSQL insert: couples ingest to the database; Redis Streams: weaker replication |
| Producer | `rdkafka` + thin `App\Kafka\Producer` | Delivery reports and flush are the whole of I1; a wrapper would hide them | `mateusjunges/laravel-kafka` |
| Database | PostgreSQL 16 | JSONB, range partitioning, RLS, `ON CONFLICT` | MySQL: no RLS, weaker JSON indexing |
| Rate limiting | Redis 7 via `RateLimiter`, fail-open | Shared counters across instances; limiter failure must not fail ingest | Per-instance counters |
| Validation | Pure PHP + JS, shared fixtures | Exact parity, strict types | Laravel Validator (section 7) |
| Tests | Pest on real PostgreSQL and Redpanda | SQLite lacks JSONB, RLS, partitions; a mocked broker makes I1 untestable | SQLite in-memory |
| Form page | Blade + vanilla JS via Vite | One page; CSP-friendly | SPA framework |

## 9. Failure modes

| Failure | System behaviour | User-visible effect | Recovery |
|---|---|---|---|
| Redpanda down or slow | No clean report within `message.timeout.ms` → 503 + `Retry-After`; nothing acked. After N consecutive failures the per-worker breaker answers 503 immediately for a cooldown, then probes | The page keeps the submission in localStorage and retries with the same id (backoff, Retry-After); the user sees "kept on this device" | Broker returns; probe succeeds; duplicates collapse in `submission_ids` |
| PostgreSQL down | Ingest serves pages and definitions from the version store (worker LRU, then Redis; publish pre-warms both); form state is served stale; consumer stops committing, topic absorbs backlog; `api` 503. A cold `ingest` instance still starts (role check deferred to first connection) and serves anything Redis holds; only a key in neither cache returns 503 + `Retry-After` | Published forms keep working; dashboard down; a form never read since its publish *and* evicted from Redis is unavailable until the DB returns | Consumer drains; nothing was acked without a broker fsync. In production the version keys move to object storage behind the CDN, so Redis is not the only Postgres-independent copy |
| Redis down | Rate limiter switches to per-worker buckets and logs once a minute; version store falls through to PostgreSQL; publish still succeeds | None; spam limits weaken to per-worker; slightly more DB reads on ingest | Shared counters resume; read-through refills the store |
| Consumer down | Topic retains; lag grows | Submissions appear late | Restart from last committed offset; replay is idempotent |
| One ingest instance down | Removed from the balancer; in-flight requests without a report are unacked | A few network errors; clients retry with the same id | Autoscaling replaces it (designed) |
| CDN down (designed) | Page and definition requests fall through to `ingest` | Slower loads | `immutable` responses refill |
| Redpanda disk full | Produces fail → 503 | As broker down | Retention or capacity change |

## 10. Production deployment (designed)

- Redpanda: three brokers in three availability zones, `submissions` RF=3, `min.insync.replicas=2`, `acks=all`, write caching off. One broker loss keeps writes flowing; two stop ingest with 503s rather than silent loss.
- Ingest: stateless Octane pods behind a load balancer, autoscaled on CPU and p99 ack latency. Page and definition JSON via CDN: `immutable` on versioned URLs, short max-age on the current-version pointer. `TRUSTED_PROXIES` names the load balancer/CDN ranges so `X-Forwarded-For` yields the visitor IP for the per-IP bucket; unset, the socket IP is used and every visitor behind a proxy would share one bucket.
- Consumers: at most one per partition, so scaling stops at 12 without repartitioning; batch size and commit interval: TBD (load test).
- Noisy neighbours: per-tenant rate limits at ingest; Kafka quotas per producer principal; large tenants move to a dedicated topic and consumer group — a routing change, not a schema change.
- Retention: topic retention covers the longest tolerated consumer outage (assumption: 7 days). Monthly partitions older than 13 months are detached and archived to object storage as Parquet; `submission_ids` pruned in step.
- Analytics: CDC (logical replication) into ClickHouse for range and aggregate queries over `data`.

## 11. Built vs designed

| Area | Status | Where |
|---|---|---|
| Runtime: Octane/FrankenPHP, PostgreSQL, Redis, Redpanda, `APP_ROLE` split, healthchecks, `make up` | Built | `Dockerfile`, `compose.yaml`, `bootstrap/app.php` |
| Redpanda write caching forced off, topics via init job | Built | `compose.yaml` |
| Producer with ack-after-durability (I1) | Built | `app/Kafka/`, `tests/Feature/Kafka/` |
| Definition validation incl. regex portability (I5, I6, I8) | Built | `app/Forms/DefinitionRules.php`, `conformance/` |
| Submission validation, pure PHP (I6, I7, I8) | Built | `app/Forms/`, `conformance/submissions` |
| Cross-engine regex compile check | Built | `tests/js/` |
| Schema, monthly partitions + DEFAULT, immutability trigger, composite version FK | Built | `database/migrations/`, `tests/Feature/Schema/` |
| Four least-privilege roles, RLS policies, `resolve_api_key`, runtime role check, `migrate` service | Built | `database/migrations/…000003…`, `app/Database/RuntimeRole.php`, `docker/postgres/init.sh`, `compose.yaml` |
| Public definition JSON (`immutable`), current-version pointer, version store (I12), form page with CSP and JSON data block (I9), render token issue/verify (I13) | Built | `app/Ingest/`, `app/Http/Controllers/Public/`, `resources/views/form/`, `tests/Feature/Ingest/` |
| `POST /v1/forms/{form}/submissions`: rate limits (I13), token + honeypot decoys (I13), pinning window (I3), validation (I6, I7), envelope with `received_at` and pseudonymous meta, produce + flush + report (I1, I14), circuit breaker | Built | `app/Http/Controllers/Public/SubmissionController.php`, `app/Ingest/`, `tests/Feature/Ingest/SubmissionsTest.php` |
| Consumer: transactional dedupe, offset commit (I2) | Designed | section 4 |
| Control-plane API: API keys (`tenants:create`), scoped tenant binding + request transaction (I11), forms CRUD, drafts with advisory validation and size caps, publish with `PublishCompat` (I5), versions | Built | `app/Http/`, `app/Tenancy/`, `app/Forms/PublishCompat.php`, `conformance/publish/`, `tests/Feature/Api/` |
| `validate.js` (pure port) with the full conformance parity run; `render.js` (visibility, errors) | Built | `resources/js/form/`, `tests/js/validate.test.mjs` |
| Client-side submit: UUIDv7 reused on retry, backoff + jitter with Retry-After, pending submission in localStorage restored on load, 409/422 handling (I2) | Built | `resources/js/form/submit.js`, `render.js`, `tests/js/submit.test.mjs` |
| CSV export, streaming (I10, I15) | Designed | CLAUDE.md |
| Load generator, reconciliation, chaos script | Planned | `loadtest/` |
| CDN, archival, ClickHouse via CDC | Designed | sections 3, 10 |
| Degraded-dependency fallbacks (I12) | Designed | section 9 |

## 12. Open questions and next steps

1. Build order: schema, then ingest endpoint + consumer (closes the I1/I2 loop), then control plane, then form page. Reconciliation (`burst.mjs` acked ids vs rows) is the acceptance test.
2. Consumer batch shape: one transaction per poll batch vs per N records — replay size on crash vs transaction overhead. TBD (load test).
3. `Producer` after a fatal idempotent-producer error: recreate the client, or rely on Octane `--max-requests` recycling.
4. 24 h superseded-version grace is an assumption; it bounds how long a stale tab can still submit.
5. Replace `\s` in accepted patterns with an explicit class to close the JS/PCRE gap, or leave it documented.
6. Multi-region is not designed; single region is load-bearing for `min.insync.replicas` and RLS latency.
