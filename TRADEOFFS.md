# Trade-offs

Three decisions that shape everything else. Each: what I decided, what I rejected and why, what it cost, and what
evidence would make me reverse it. Numbers cite `ARCHITECTURE.md` §2 ("Measured results") and §6.

## 1. Acknowledge after a confirmed broker delivery report, not after a PostgreSQL write

**Decision.** `POST /v1/forms/{form}/submissions` returns 202 only after librdkafka's delivery report for that exact
message comes back clean from a Redpanda broker running with `acks=all`, idempotence on, and write caching off. The
row reaches PostgreSQL later, through a consumer that commits its transaction before it commits its offsets (I1, I2).
The client generates the submission id (UUIDv7) and reuses it on every retry.

**Rejected: insert straight into PostgreSQL from the request.** It is simpler — one component fewer, rows visible
immediately — and it is the wrong shape for the brief. It couples the public data plane's availability and latency
to the database: a burst becomes a write burst on the primary, a failover becomes an outage for every embedded form,
and there is no buffer anywhere. The brief's core scenario is exactly the burst, and the requirement is that nothing
acked is lost. A queue with an fsync'd ack is the component that makes that claim cheap to keep.

**Rejected: produce and return without waiting for the report.** `produce()` only appends to a local queue; a worker
that dies, or a broker that is down, loses the message after the client has been told 202. That is the silent-loss
case the whole design exists to exclude. Waiting for the report costs one broker round trip per request and, on this
laptop, p50 9 ms end to end at 200/s.

**What it cost.** The ambiguous ack. When the report is lost — timeout, broker restart, connection reset — the client
cannot tell "not produced" from "produced, report lost". The design answers it rather than avoiding it: the client
retries with the same id, the consumer claims ids in `submission_ids` with `ON CONFLICT DO NOTHING RETURNING id`,
and a second copy on the topic becomes a no-op. The chaos run made this concrete: 1,689 submissions were refused
(503 or network error) *and* stored, each exactly once. Also paid: dashboard freshness equals consumer lag (bounded by
a measured drain of ≥ 24,000 rows/s, ≈ 45 s after a consumer crash while the group session times out); one more
stateful system to run (Redpanda, RF=3 in production); and a client that must persist a pending submission across
reloads, which `submit.js` and localStorage do.

**What would change my mind.** Evidence that sustained ingest never approaches what one PostgreSQL primary absorbs
with headroom *during* a failover — that is, that the buffer would never fill. At the assumed 20/s steady and
2,000/s spikes it clearly can fill during a database event, so the queue stays.

## 2. One `submissions` table with JSONB keyed by stable field ids, not EAV, not table-per-form

**Decision.** Every submission is one row: `data jsonb` keyed by field id, `form_version_id` pointing at the immutable
definition that gives the keys meaning. Field ids are server-generated, `^[a-z0-9_]{1,40}$`, and an id keeps its type
across every published version (I5); labels, rules, options and visibility may change freely.

**Rejected: EAV (one row per answer).** It multiplies rows by field count, turns "show me this submission" into a
pivot, and has no natural place for a per-row validation shape. The only thing it buys — indexing individual values —
is exactly what a GIN index on jsonb gives without the row explosion.

**Rejected: a table per form (or per version).** DDL on publish, ten thousand tables at the assumed scale, row-level
security and indexes maintained per table, and every version change becomes a schema migration. It also breaks the
one property the export depends on: reading rows from different versions side by side.

**What it cost.** Two things, one expected and one found by running the query planner. Expected: range and aggregate
queries over `data` ("age > 30") are not indexable; they are a partition scan narrowed by tenant and form, fine for
one form's recent month and not an analytics product — the designed path is CDC into ClickHouse. Found:
`jsonb @>` is not a `LEAKPROOF` operator, so under row-level security PostgreSQL will not evaluate it before the
policy qual, and the `webform_api` role never gets the GIN plan. A filtered list is a btree walk over the form's rows
with the containment as a post-filter: 27–72 ms at 172k rows on the laptop, linear in the form's size; the same
predicate run without RLS uses the GIN index in 0.07 ms (§6). The index exists and is valid; the role that needs it
cannot use it. That is a real cost of putting isolation in the database rather than only in the application, and
I would rather have it than the alternative.

**What would change my mind.** Evidence that filtered dashboard queries are a primary workload at scale. Then the
`SECURITY DEFINER` read function (which applies the tenant predicate itself and is allowed the GIN plan) stops being
optional, or the ClickHouse path gets built first. Nothing about the write side would change.

## 3. A server-rendered page on an isolated origin with a strict CSP, not an inline JS widget on the customer's page

**Decision.** The public form is `GET /f/{form}` on the `ingest` origin: Blade renders the fields, the definition
rides in a `<script type="application/json">` data block, and the page ships
`default-src 'none'; script-src 'self'; style-src 'self'; … frame-ancestors *` with no inline script or style (I9).
Customers embed it with an iframe. Validation runs twice: `validate.js` in the browser for immediate feedback, the
PHP validator on ingest as the authority.

**Rejected: a `<script>` widget that renders the form inside the customer's DOM.** It runs under the customer's CSP
(or none), inside their XSS surface, next to their frameworks and global CSS; our validator becomes third-party
JavaScript in a page we do not control, and a compromised customer page can read tokens and forge submissions. A
label such as `<img src=x onerror=…>` is our tenant's data rendered on someone else's origin. The iframe boundary
makes the tenant's content and the visitor's submission ours to protect.

**Rejected: an iframe that loads a client-rendered SPA.** Same isolation, larger surface: a framework bundle, an
inline bootstrap the CSP would have to allow, and two validators with even less in common.

**What it cost.** Embeddability is by iframe only — no seamless in-DOM styling, no SEO for the form content, and a
height that needs `postMessage` plumbing (not built). And two validators means drift is a standing risk: PHP and JS
must agree on trimming, type strictness, visibility, every error code and the exact stored object. The answer is
`conformance/`: the same JSON cases are executed by the Pest suite and by `node --test`, 188 submission cases plus the
definition and regex-portability sets, and the JS side compiles every pattern PHP accepted with the `u` flag. The
gaps that remain are named, not hidden — `\s` and `.` differ between PCRE and JS on exotic input, and JS has no
backtrack limit, which is why pattern checks run in a Web Worker with a 50 ms budget and defer to the server.

**What would change my mind.** A customer requirement to render inside their DOM that an iframe cannot meet —
typically brand-exact styling or the form's content indexed as part of their page. Then the widget is built as a
second, explicitly untrusted client of the same API, and the conformance suite is what makes that safe to do.
