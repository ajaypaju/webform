#!/bin/sh
# Consumer drain rate D (ARCHITECTURE §2): stop the consumer, put N envelopes straight on the topic (each one
# flushed and acked, so this also times the producer), start the consumer, sample lag every second until 0.
# Bypasses HTTP on purpose: D is consumer -> PostgreSQL, and the per-form rate limit would cap ingest first.
set -eu
cd "$(dirname "$0")/.."
N=${1:-60000}
FORM=${FORM:-$(grep -ohE '"form": "[0-9a-f-]{36}"' "$(ls -t loadtest/out/*.summary.json | head -1)" | head -1 | cut -d'"' -f4)}
[ -n "$FORM" ] || { echo "no published form found in Redis; run make load first or set FORM=<id>"; exit 1; }
VER=$(docker compose exec -T postgres psql -U webform_ingest -d webform -Atc "select current_version_id from forms where id = '$FORM'")
TENANT=$(docker compose exec -T postgres psql -U webform_ingest -d webform -Atc "select tenant_id from forms where id = '$FORM'")

docker compose stop consumer >/dev/null
docker compose exec -T api php -r "
require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$p = app(App\Kafka\Producer::class); \$t = microtime(true);
for (\$i = 0; \$i < $N; \$i++) { \$id = (string) Illuminate\Support\Str::uuid7(); \$p->send('submissions', \$id, json_encode(['v' => 1, 'submission_id' => \$id, 'tenant_id' => '$TENANT', 'form_id' => '$FORM', 'form_version_id' => '$VER', 'received_at' => now()->format('Y-m-d\\\\TH:i:s.uP'), 'data' => ['name' => 'Drain', 'email' => 'd@e.co', 'plan' => 'free', 'consent' => true], 'meta' => ['ip_hash' => 'x', 'user_agent' => 'drain', 'referer' => null]])); }
printf('produced %d in %.1fs (%.0f/s, each produce+flush+delivery report)'.PHP_EOL, $N, microtime(true) - \$t, $N / (microtime(true) - \$t));"
LAG0=$(docker compose exec -T redpanda rpk group describe "${KAFKA_CONSUMER_GROUP:-submissions-consumer}" | awk '/TOTAL-LAG/ {print $2}')
echo "backlog before start: $LAG0"
docker compose start consumer >/dev/null; T0=$(date +%s.%N)
while :; do
  L=$(docker compose exec -T redpanda rpk group describe "${KAFKA_CONSUMER_GROUP:-submissions-consumer}" | awk '/TOTAL-LAG/ {print $2}')
  printf 't=%5.1fs lag=%s\n' "$(echo "$(date +%s.%N) - $T0" | bc)" "$L"
  [ "$L" = "0" ] && break
  sleep 1
done
T=$(echo "$(date +%s.%N) - $T0" | bc)
printf 'drained %s rows in %.1fs (includes consumer start-up and group join) -> D >= %.0f rows/s\n' "$LAG0" "$T" "$(echo "$LAG0 / $T" | bc -l)"
