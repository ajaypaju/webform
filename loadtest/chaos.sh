#!/bin/sh
# Burst while breaking things, then reconcile. Runs on the host (needs docker). Prints a timeline next to the results.
set -eu
cd "$(dirname "$0")/.."

COMPOSE="docker compose"
LOAD="docker compose -f compose.yaml -f compose.load.yaml"
PROJECT=${COMPOSE_PROJECT_NAME:-webform}
TAG="chaos-$(date +%Y%m%d-%H%M%S)"
TIMELINE="loadtest/out/$TAG.timeline"
mkdir -p loadtest/out

log() { printf '%s  %s\n' "$(date +%H:%M:%S)" "$*" | tee -a "$TIMELINE"; }

KEY=${LOAD_API_KEY:-$($COMPOSE run --rm --no-deps -T migrate php artisan tenants:create "chaos $TAG" | sed 's/\x1b\[[0-9;]*m//g' | awk '/api_key:/ {print $2}')}

# 5 + 5 + 150 + 5 + 30 = 195 s of load; the faults land inside the 150 s plateau.
log "burst: pre 5s@50, ramp 5s, spike 150s@${CHAOS_SPIKE:-300}/s, ramp 5s, post 30s@50 (bypass-ip-limit)"
$LOAD --profile load run --rm -T -e LOAD_API_KEY="$KEY" load node burst.mjs --tag "$TAG" \
  --steady 50 --spike "${CHAOS_SPIKE:-300}" --spike-secs 150 --ramp 5 --pre 5 --post 30 --bypass-ip-limit \
  > "loadtest/out/$TAG.burst.log" 2>&1 &
BURST=$!

sleep 20;  log "stop consumer (30s): lag must grow, nothing else changes"
$COMPOSE stop consumer >/dev/null;   sleep 30
log "start consumer";                 $COMPOSE start consumer >/dev/null

sleep 10;  log "stop postgres (30s): ingest keeps acking from the version store, consumer retries"
$COMPOSE stop postgres >/dev/null;   sleep 30
log "start postgres";                 $COMPOSE start postgres >/dev/null

sleep 15;  log "stop redpanda (15s): ingest answers 503, clients retry with the same id"
$COMPOSE stop redpanda >/dev/null;   sleep 15
log "start redpanda";                 $COMPOSE start redpanda >/dev/null

sleep 20;  log "kill -9 the consumer process mid-batch: offsets for the batch in flight are not committed; replay must dedupe"
# WHY: kill the PHP process, not the container — `docker kill` counts as a manual stop and the restart policy does
# not fire, and PID 1 (tini, compose `init: true`) cannot be SIGKILLed from inside. A crashed process is what we simulate.
$COMPOSE exec -T consumer sh -c 'kill -9 $(pidof php)' || true
sleep 8;   log "consumer after kill: $(docker inspect "$PROJECT-consumer-1" --format '{{.State.Status}}, restarts={{.RestartCount}}')"

wait $BURST || true
log "burst finished"
tail -n 6 "loadtest/out/$TAG.burst.log"

log "reconcile"
RUN="/app/loadtest/out/$(ls -t loadtest/out/*"$TAG".jsonl | head -1 | xargs basename)"
set +e
$LOAD --profile load run --rm -T load node reconcile.mjs --run "$RUN" > "loadtest/out/$TAG.reconcile.log" 2>&1
STATUS=$?
set -e
cat "loadtest/out/$TAG.reconcile.log" | tee -a "$TIMELINE"
log "done (exit $STATUS)"
exit $STATUS
