# Load and chaos runs

Evidence for the no-lost-submissions claim, and the source of every measured number in `ARCHITECTURE.md`.
Node 22 in a container (`compose.load.yaml`, profile `load`), no npm dependencies: `fetch` is undici. Output lands in
`loadtest/out/` (gitignored): `<run>.jsonl` (one line per request, append-only), `<run>.summary.json`,
`<run>.reconcile.json`, and for chaos runs `<tag>.timeline`.

```
make load                                     # overlay + tenant + burst (defaults) + reconcile
make load ARGS="--spike 200 --spike-secs 60"  # any burst.mjs flag
make chaos                                    # burst while stopping consumer / postgres / redpanda and kill -9 consumer
make reconcile                                # re-check the latest run
```

## burst.mjs — arrival-rate driven

`--steady 20 --spike 2000 --spike-secs 60 --ramp 10 --pre 10 --post 20 --workers 512 --max-attempts 6`.
A scheduler releases requests at the target rate (50 ms slices, exact on average); a bounded pool sends them. Every
request is a fresh UUIDv7; 429, 503 and network errors are retried with backoff + jitter honouring `Retry-After`,
with the **same id**, up to `--max-attempts`. A request is *acked* only on 202.

What the report means:

- **sent / acked / refused** — unique ids; refused = sent − acked, split by final status.
- **429** — a rate limit refused it (per IP+form, per form, per tenant — `config/ingest.php`). Counted per response, so one
  request retried three times counts three. With the default spike the per-form bucket (2000 burst, 200/s sustained) is
  *supposed* to refuse most of it; that is the product working, not a failure.
- **503** — the broker didn't confirm within the timeout (or the circuit breaker was open). Nothing was acked.
- **ack latency** — first attempt to 202, retries included, so it is what a user waits.
- **spike window** — target vs *dispatched* and acked per second, averaged over the plateau. Per-second counts are
  bucketed by when a request actually left the generator, not when it was scheduled: with many requests in retry
  sleeps the run can outlast its schedule (see `wall_seconds`), and the achieved rate is what the server really saw.
- **generator queueing delay** — time a request waited for a free worker. If p95 is more than a few ms the generator,
  not the server, was the bottleneck: raise `--workers` or lower `--spike`. It is reported so the numbers can't lie by omission.
- **sent but not acked** — refused, not lost. The server said no (429/503) and the client stopped retrying; no 202 was
  ever issued for those ids, so the no-loss claim does not cover them and reconcile lists them separately.

`--bypass-ip-limit` sends a distinct synthetic `X-Forwarded-For` per request. It only has an effect when ingest trusts
the load network (`compose.load.yaml` sets `TRUSTED_PROXIES=172.28.0.0/16` for ingest; `make load` applies it). This
exercises the full server path honestly — token, version, validation, produce, breaker, per-form and per-tenant buckets —
while removing the one limit that exists only because all the load comes from one host.

## reconcile.mjs — the claim, checked

Waits until the consumer group's `TOTAL-LAG` is 0 (`rpk`, timeout `--drain-timeout 300`), reads the run's ids, reads
the form's stored ids as `webform_api` (SELECT-only, tenant-scoped), and asserts:

| line | must be | meaning |
|---|---|---|
| acked but missing | 0 | **the claim**: a 202 means a row |
| stored but never sent (phantoms) | 0 | nothing appears that no one sent |
| ids stored more than once | 0 | exactly-once effect (I2) |
| refused but stored anyway | informational | a 503 after the broker had in fact stored it, later replayed by the client or the consumer — allowed, and why ids are reused |

Exit code is non-zero on any violation. While waiting it samples lag every second and reports the observed
**drain rate** (rows/s while the backlog shrank) — that is `D` in ARCHITECTURE §2.

## drain.sh

`make drain N=60000`: stops the consumer, produces N envelopes straight onto the topic (each produce+flush+report, so it
also times the producer), starts the consumer and samples lag every second. Bypasses HTTP on purpose: D is consumer →
PostgreSQL, and the per-form rate limit would cap ingest first.

## chaos.sh

A 195 s burst (`CHAOS_SPIKE`, default 300/s) with, on a printed timeline: consumer stopped 30 s, postgres stopped 30 s,
redpanda stopped 15 s, consumer `kill -9`. Then reconcile. Read the timeline next to `per_second` in the summary.
