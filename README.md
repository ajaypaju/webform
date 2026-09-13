# webform

A multi-tenant form builder and submission platform. Tenants define forms in a session dashboard or through an API-key control plane (both `api`),
the public data plane (`ingest`) renders forms and accepts submissions into a Redpanda topic, and a consumer writes
them to PostgreSQL exactly once. The claim the design is built around: **a submission that received a 202 is never
lost** — proven by reconciliation under a chaos run that stops the consumer, PostgreSQL and the broker in turn.

Everything runs in Docker; nothing is installed on the host. [ARCHITECTURE.md](ARCHITECTURE.md) is the design and
stands on its own; [DESIGN-NOTES.md](DESIGN-NOTES.md) is its evidence companion (which test proves what, break-tests,
code paths); [TRADEOFFS.md](TRADEOFFS.md) the three decisions that matter most.

## Quickstart (Docker Desktop only)

```
make up            # build, generate keys into .env, start api/ingest/consumer/postgres/redis/redpanda, wait until healthy
./scripts/demo.sh  # tenant + a demo form with every field type, published; prints the URL and API key
                   # then http://localhost:8000/login — paste the key, or sign up at /signup for your own workspace
make test          # PHP suite against real Postgres/Redpanda + JS conformance suite
make load          # load overlay, tenant, arrival-rate burst, reconciliation
make chaos         # burst while stopping consumer, postgres, redpanda, and crashing the consumer; then reconcile
make reset         # stop and delete volumes
```

What you will see:

`make up` ends with every service healthy; the one-shot `migrate` runs the migrations and creates the monthly partitions.
```
 Container webform-postgres-1  Healthy       Container webform-redis-1     Healthy
 Container webform-redpanda-1  Healthy       Container webform-migrate-1   Exited
 Container webform-ingest-1    Healthy       Container webform-api-1       Healthy
 Container webform-consumer-1  Healthy
```

`./scripts/demo.sh` — open the page in a browser; the API key is the bearer token for the examples below.
```
api key:   wf_<43 base62 characters, shown once>
form id:   01a09629-3b28-70f6-b820-79ec7187fa0a
form page: http://localhost:8080/f/01a09629-3b28-70f6-b820-79ec7187fa0a
version:   http://localhost:8080/v1/forms/01a09629-3b28-70f6-b820-79ec7187fa0a
```

`make test` — the Pest suite against real PostgreSQL under its four roles, Redis and Redpanda, then the JS suite
(`node --test`, no npm dependencies).
```
  Tests:    441 passed (1760 assertions)
# tests 13
# pass 13
```
Occasionally the PHP run ends with `The process has been signaled with signal "11"` and a non-zero exit *after* the
results line: the `pest` child process crashes on shutdown, once every results are printed. No test fails; the cause
is not diagnosed (it only reproduces on a full run, intermittently, and looks like extension teardown at process exit).

`make load ARGS="--spike 200 --spike-secs 120 --ramp 5 --post 10"` — the generator report, then reconciliation:
```
sent 25500  acked 25500  refused 0 (429: 0 responses, 503: 0, network: 0, 422: 0, other: 0; retries 0, gave up 0)
ack latency ms (incl. retries)  p50 9  p95 15  p99 21  max 105
spike window (by dispatch time)  target 200/s  dispatched 200/s  acked 200/s
generator queueing delay ms  p50 1  p95 2  p99 2  max 64  (max queue 10)

sent (unique ids)                     25500
acked (202)                           25500
stored rows for this form             25500
acked but missing                         0  OK
stored but never sent (phantoms)          0  OK
ids stored more than once                 0  OK
```

`make chaos` — a timeline of what was broken when, then the same reconciliation:
```
22:49:08  burst: pre 5s@50, ramp 5s, spike 150s@300/s, ramp 5s, post 30s@50 (bypass-ip-limit)
22:49:28  stop consumer (30s): lag must grow, nothing else changes
22:49:59  start consumer
22:50:10  stop postgres (30s): ingest keeps acking from the version store, consumer retries
22:50:41  start postgres
22:50:56  stop redpanda (15s): ingest answers 503, clients retry with the same id
22:51:11  start redpanda
22:51:32  kill -9 the consumer process mid-batch: offsets for the batch in flight are not committed; replay must dedupe
22:51:40  consumer after kill: running, restarts=1
22:52:30  burst finished
sent (unique ids)                     48500
acked (202)                           33318
sent but not acked (refused)          15182      <- 429/503/network: refused, never acked, not lost
acked but missing                         0  OK
stored but never sent (phantoms)          0  OK
ids stored more than once                 0  OK
refused but stored anyway              1689      <- the ambiguous ack: a 503 after the broker had stored it; stored once
```

## Repo map

```
app/Forms/            DefinitionRules, SubmissionValidator (pure PHP), Visibility, SafePattern (ReDoS), PublishCompat
app/Ingest/           VersionStore (worker LRU → Redis → Postgres), RenderToken, RateLimiter, SubmissionProducer (breaker)
app/Consumer/         Envelope, BatchWriter (one transaction, dedupe), Consumer (offsets after commit, DLQ, backoff)
app/Submissions/      SubmissionQuery (keyset + filters), CsvExport (streamed, version-union columns, injection-safe)
app/Http/             AuthenticateApiKey + DashboardTenant (session) -> the same tenant transaction; PublicHeaders (CSP);
                      controllers: /v1 (FormController, SubmissionController), Public (ingest), Dashboard (signup, login, verification, builder, submissions, API keys)
app/Accounts/         Signup (one transaction: user, tenant, owner, first key), Verification (signed link, logged in place of mail)
app/Models/           Form, FormVersion (tenant-scoped), User (not tenant data)
app/Tenancy/          TenantContext (scoped per request), TenantTransaction, ApiKey
app/Database/         RuntimeRole: refuses to run as the wrong Postgres role
app/Kafka/            Producer: produce + flush + per-message delivery report
resources/js/form/    validate.js (port of the PHP validator), render.js, patterns.js (Web Worker), submit.js, messages.js
resources/js/dashboard/ builder.js: field editor, visibility editor, live preview through render.js; no framework
resources/views/      form/ the public page; dashboard/ login, forms list, builder (Blade + JSON data block), submissions viewer (Blade only)
routes/               api.php (/v1, stateless), dashboard.php (web group: session + CSRF), public.php (ingest)
conformance/          fixtures run by BOTH the PHP and the JS suites (submissions, definitions, patterns, publish)
database/migrations/  raw SQL: partitions, composite FKs, immutability trigger, roles, RLS policies
docker/postgres/      init.sh: four least-privilege roles
loadtest/             burst.mjs, reconcile.mjs, chaos.sh, drain.sh (README inside)
tests/Unit            conformance + token;  tests/Feature  real Postgres/Redpanda per role;  tests/js  node:test
```

## API walkthrough

Two origins: control plane on `:8000` (bearer key), public data plane on `:8080` (no auth). Errors are codes, not
messages: `422 {"errors": [{"code": "...", "field": "..."}]}` on the control plane, `422 {"errors": {"field": ["code"]}}`
for submission data, `401 {"error": "unauthenticated"}`, `429 {"error": "rate_limited"}` + `Retry-After`,
`503 {"error": "unavailable"}` + `Retry-After` when the broker did not confirm.

```sh
make tenant name="Acme"                      # prints wf_... once; export KEY=wf_...
API=http://localhost:8000; PUB=http://localhost:8080

# create a form with a draft definition (advisory validation errors come back as definition_errors)
FORM=$(curl -s "$API/v1/forms" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -d '{
  "name": "Signup",
  "definition": {"fields": [
    {"id": "email", "type": "email", "label": "Email", "required": true},
    {"id": "plan",  "type": "select", "label": "Plan", "required": true,
     "options": [{"value": "free", "label": "Free"}, {"value": "pro", "label": "Pro"}]},
    {"id": "seats", "type": "number", "label": "Seats", "required": false, "rules": {"integer": true, "min": 1},
     "visible_if": {"field": "plan", "op": "eq", "value": "pro"}}
  ]}}' | python3 -c 'import json,sys; print(json.load(sys.stdin)["id"])')

# publish: 201 {"version_id": "...", "version_no": 1}. A later publish that changes a field's type is refused:
# 422 {"errors": [{"field": "email", "code": "type_changed"}]}
curl -s -X POST "$API/v1/forms/$FORM/publish" -H "Authorization: Bearer $KEY"

# public side: current version pointer (cacheable 30s) and the immutable definition (cacheable a year)
curl -s "$PUB/v1/forms/$FORM"                                   # {"current_version_id": "..."}
VER=$(curl -s "$PUB/v1/forms/$FORM" | python3 -c 'import json,sys; print(json.load(sys.stdin)["current_version_id"])')
curl -s "$PUB/v1/forms/$FORM/versions/$VER"                     # {"id", "form_id", "version_no", "published_at", "definition"}

# the page embeds a render token; a submission must carry it, and not earlier than 2s after render (spam decoy otherwise)
TOKEN=$(curl -s "$PUB/f/$FORM" | grep -oE 'data-render-token="[^"]+"' | cut -d'"' -f2); sleep 2.5

# submission_id must be a UUIDv7 (the page's JS generates one; a v4 is refused with code "regex")
ID=$(python3 -c "import uuid,time,os; b=bytearray(int(time.time()*1000).to_bytes(6,'big')+os.urandom(10)); b[6]=(b[6]&0x0f)|0x70; b[8]=(b[8]&0x3f)|0x80; print(uuid.UUID(bytes=bytes(b)))")

# submit: 202 {"submission_id": "<yours>", "received_at": "..."}; hidden fields (seats when plan=free) are dropped from what is stored
curl -s -X POST "$PUB/v1/forms/$FORM/submissions" -H 'Content-Type: application/json' -d "{
  \"submission_id\": \"$ID\", \"form_version_id\": \"$VER\", \"render_token\": \"$TOKEN\",
  \"data\": {\"email\": \"ada@example.com\", \"plan\": \"free\", \"seats\": 9}, \"honeypot\": \"\"}"
# posting the same body again (a client retry) is another 202 and still exactly one row

# a superseded version is accepted for 24h, then: 409 {"code": "version_retired", "current_version_id": "..."}

# list (keyset, newest first) and filter by an exact field value; export the union of all versions' columns as CSV
curl -s "$API/v1/forms/$FORM/submissions?limit=2&field=plan&value=free" -H "Authorization: Bearer $KEY"
curl -s "$API/v1/forms/$FORM/submissions/export.csv" -H "Authorization: Bearer $KEY" | head -3
# submission_id,received_at,form_version_id,Email,Plan,Seats
```

## Dashboard

`http://localhost:8000/signup` — email, password (12+ characters), company name. One transaction creates the user,
the tenant, the owner membership and the first API key, and logs you in; if any step fails nothing is created. The
response is the same whether or not the address already had an account (the existing owner is told by email instead).
There is no mail transport in this build, so the verification "email" is a log line:
```
docker compose logs api | grep 'verification link'     # copy the URL; it is signed and expires after 24 h
```
Until it is followed you can build forms but not publish them or create API keys; the dashboard says so and can write
a fresh link to the log. `http://localhost:8000/login` takes email + password, or an API key for tenants provisioned
with `make tenant` (no user). Either way the session holds only ids — tenant and user — in a server-side Redis session
behind an `httpOnly`, `SameSite=Lax` cookie; neither the key nor the password reaches the browser or the session
store. Signup, login and verification resends are rate-limited per IP, the session id is regenerated on login and
signup, logout destroys the session, every mutating route needs a CSRF token, and membership is re-checked on every
request — a removed member is out on their next click. A session cookie is never a credential for `/v1`, and a bearer
key is never one for `/dashboard`; both are tested.

**API keys** (`/dashboard/api-keys`): each key's prefix, creation time and last use; create shows the plaintext once;
revoke takes effect on the next `/v1` request and ends any dashboard session that was started with that key (a leaked key is dead in one click). The last live key cannot be revoked. The builder edits a draft (add, move, remove
fields; per-type rules; visibility conditions limited to fields above), shows advisory errors per field, publishes
through the same code the API uses, and previews the form with the public page's own `render.js`. Field ids are
minted on the server from the label and never change afterwards. The session model is in
[ARCHITECTURE.md §5.3](ARCHITECTURE.md#53-tenant-isolation).

`/dashboard/forms/{form}/submissions` is the submissions viewer: one column per field across every version (the
same column list as the CSV export, so screen and file agree), a field the row's version never had shown as `n/a`
against a blank for an unanswered one, keyset paging with linkable cursors and no total (counting a partitioned
table per page view is the load the design avoids — it says "showing N of many"), filters for date range, version
and field = value that follow you across pages and onto the Export CSV button, and a detail view that labels each
answer with the version the submission was validated against — a row from a retired version keeps its own labels.
Values are visitor input and are rendered by Blade only; these pages ship no JavaScript.

## Built vs designed

Built: the whole path from API key to CSV export — durable ingest with a delivery-confirmed ack, the exactly-once
consumer, four Postgres roles with row-level security, the server-rendered page with a strict CSP, a validator that
exists twice and is proven identical by shared fixtures, the dashboard with self-service signup, password and
key login, the form builder, the submissions viewer and API key management, and the load/chaos tooling that produced
the numbers below.
Designed, not built: CDN, ClickHouse via CDC, object storage, multi-region, per-tenant sharding, webhooks,
transactional email (verification links are logged), team management, GDPR deletion, async S3 exports, browser
automation. The full table and the known limits (including one
found by the query planner: the api role cannot use the GIN index under RLS) are in
[ARCHITECTURE.md §11](ARCHITECTURE.md#11-built-vs-designed).

## Measured results

One MacBook (Apple M4, 16 GB), Docker Desktop with 10 CPUs / 8 GB, every service on that node, Redpanda RF=1 — local
numbers, not a benchmark (`ARCHITECTURE.md` §2 has the full context; [DESIGN-NOTES.md §1](DESIGN-NOTES.md#1-measured-results-what-the-rows-mean) explains each row).

| Run (command) | Sent | Acked | Refused | Ack p50 / p95 / p99 / max | Reconcile |
|---|---|---|---|---|---|
| `make load ARGS="--spike 200 --spike-secs 120 --ramp 5 --post 10"` | 25,500 | 25,500 | 0 | 9 / 15 / 21 / 105 ms | 25,500 stored, 0 missing, 0 phantoms, 0 duplicates |
| `make load` (2,000/s × 60 s, one form; per-form bucket was 2,000 / 200/s at the time) | 140,691 | 82,215 | 58,476 (429) | 2,278 / 8,954 / 9,278 / 10,325 ms | 82,215 stored, 0 / 0 / 0 |
| `make chaos` (consumer, Postgres, Redpanda stopped in turn; consumer process `kill -9`) | 48,500 | 33,318 | 15,182 | 1,238 / 14,665 / 30,133 / 38,411 ms | 35,007 stored, 0 missing, 0 phantoms, 0 duplicates; 1,689 refused-but-stored |
| `make drain N=60000` | 60,000 produced at 7,170/s | — | — | — | drained in 2.5 s → consumer ≥ 24,000 rows/s |

The per-form rate-limit default has since been raised to 5,000 burst / 2,000/s (an abuse ceiling, not a traffic
shaper; the topic absorbs a legitimate burst); the second row was not re-run.

## How I used AI

Claude Code throughout. [CLAUDE.md](CLAUDE.md) is the standing contract: the working rules, the fixed stack, and the
fifteen invariants (I1–I15) that served as the spec — each has a test that fails if it is broken. Every diff was
reviewed and every commit was made by me; `git commit`, `git push`, `git reset --hard`, `git rebase` and reading
`.env` are denied to the tool in `.claude/settings.json`. Tasks were sized to one commit, and the tool had to
propose a split whenever a diff went past ~400 lines.

Things I corrected or decided against its first answer, all visible in the history:

1. **Row-level security.** Its first schema used `FORCE ROW LEVEL SECURITY` plus `USING (true)` policies for the owner
   role — FORCE binds only the owner, and an allow-all policy restricts nothing, so the pair only looked strict. I had
   it remove both, keep the owner out of the runtime by credentials, and add `App\Database\RuntimeRole`, which refuses
   to serve as the wrong role, a superuser, `BYPASSRLS`, or a table owner (`372af2c` → `7c7435a`). Its first version
   of that check ran at boot for every process, which would have stopped `ingest` from starting during a Postgres
   outage (I12); for ingest it now runs on the first database connection instead.
2. **ReDoS in the browser.** It documented a ~20 s tab freeze on a pathological pattern as a "known semantic gap"
   (V8 has no backtrack limit in `u` mode). That is a product bug that lands on the customer's visitors, not a doc
   note: pattern checks moved into a Web Worker with a 50 ms budget and fall back to the server (`bfcfa86`).
3. **Rate limiting behind a proxy.** Its per-IP limiter keyed on the socket address; behind a CDN or load balancer
   every visitor would share one bucket and the whole internet would look like a single abusive client on the day of
   deploy. `TRUSTED_PROXIES` with tests for the socket-IP, trusted-proxy and spoofed-header cases (`3cab663`). Later,
   the per-form limit it had chosen (2,000 burst / 200/s) refused the brief's own burst scenario in `make load`; it is
   now an abuse ceiling well above that.
4. **The audit line.** Spam (honeypot, bad token) gets a decoy 202 and is dropped on purpose; it logged that at debug
   level while ARCHITECTURE cited the line as the audit trail behind the no-loss claim. Raised to info so no default
   log filter can hide the one deliberate drop in the system (`49f32a5`).

Two things no test suite caught and only running the stack did, which is why every task ended with a real run: a
singleton first resolved inside a request lives in Octane's per-request sandbox and dies with it, so the circuit
breaker never opened until the per-worker services were listed in `config/octane.php` `warm` (`f09e4d6`); and
`docker kill` counts as a manual stop, so the consumer's restart policy never fired until it ran under tini and the
chaos script killed the PHP process instead (`1a5e883`).

## More

- [ARCHITECTURE.md](ARCHITECTURE.md) — the design: components, requirements, data model, technology choices, failure modes, measured results, built vs designed
- [DESIGN-NOTES.md](DESIGN-NOTES.md) — the evidence behind it: per-requirement test files and break-tests, the built table with code paths, dashboard auth, validation detail
- [TRADEOFFS.md](TRADEOFFS.md) — three decisions, what they cost, what would change them
- [conformance/README.md](conformance/README.md) — the fixture format and the exact validation semantics
- [loadtest/README.md](loadtest/README.md) — the generator, reconciliation, chaos and drain runs
