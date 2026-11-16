#!/usr/bin/env bash
# Scale series: runs tests/k6/scale.js once per size, against the running Docker
# Compose stack with the mock provider, and adds what the stack itself shows.
#
#   tests/k6/scale.sh                       # 100, 1000, 10000, 100000 and 1000000
#   tests/k6/scale.sh 100 1000              # only these sizes
#
# It uses the Compose project of the current directory; COMPOSE_PROJECT_NAME and
# COMPOSE_FILE select another one, APP_PORT its API port. Everything goes to
# $OUT_DIR (default scale-results): for each size the raw k6 output and the mock
# provider's counters, and one JSON line in results.jsonl with the k6 summary,
# the largest Redis memory and queue backlog seen while it ran, the end-to-end
# latency of its notifications from the database, and how long typical reads take
# afterwards.
set -euo pipefail

SIZES=("$@")
[ ${#SIZES[@]} -eq 0 ] && SIZES=(100 1000 10000 100000 1000000)
OUT_DIR=${OUT_DIR:-scale-results}
mkdir -p "$OUT_DIR"
API="http://localhost:${APP_PORT:-8000}/api/v1"
AUTH=(-H 'Authorization: Bearer edns-demo-token' -H 'Accept: application/json')
# Laravel prefixes its Redis keys with the slug of APP_NAME (config/database.php).
QUEUE_PREFIX=${REDIS_PREFIX:-edns-database-}queues

psql() { docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "$0"' "$1"; }
redis() { docker compose exec -T redis redis-cli "$@"; }

# The median of five timed requests, in milliseconds.
timed() {
    for _ in 1 2 3 4 5; do curl -s -o /dev/null -w '%{time_total}\n' "${AUTH[@]}" "$1"; done |
        sort -n | sed -n 3p | awk '{printf "%.0f", $1 * 1000}'
}

# Notification jobs waiting in Redis: ready, delayed and reserved, on every channel and priority.
backlog() {
    local total=0
    for channel in sms email push; do
        for priority in high normal low; do
            local queue="$QUEUE_PREFIX:notifications-$channel-$priority"
            total=$((total + $(redis LLEN "$queue") + $(redis ZCARD "$queue:delayed") + $(redis ZCARD "$queue:reserved")))
        done
    done
    echo $total
}

for size in "${SIZES[@]}"; do
    echo "== $size notifications"
    samples=$(mktemp)

    # Sample Redis while the scenario runs.
    (
        while true; do
            echo "$(redis INFO memory | sed -n 's/^used_memory:\([0-9]*\).*/\1/p') $(backlog) $(redis LLEN "$QUEUE_PREFIX:broadcasts")" >> "$samples"
            sleep 5
        done
    ) &
    sampler=$!

    k6_output=$(docker compose --profile loadtest run --rm -T k6 run -e "TOTAL=$size" -e "DRAIN_TIMEOUT_SECONDS=${DRAIN_TIMEOUT_SECONDS:-}" /scripts/scale.js 2>&1)
    kill $sampler 2>/dev/null || true
    wait $sampler 2>/dev/null || true
    echo "$k6_output" > "$OUT_DIR/k6-$size.txt"
    curl -s "http://localhost:${MOCK_PROVIDER_PORT:-8090}/__requests" > "$OUT_DIR/provider-$size.json"

    # k6 prints console output as a log line with escaped quotes.
    result=$(echo "$k6_output" | grep -o 'SCALE_RESULT {.*}' | sed 's/^SCALE_RESULT //' | sed 's/\\"/"/g' | head -1)
    batches=$(echo "$k6_output" | grep 'batch_duration\.' | sed -E 's/.*p\(50\)=([^ ]+) +p\(95\)=([^ ]+) +p\(99\)=([^ ]+) +max=([^ ]+).*/{"p50": "\1", "p95": "\2", "p99": "\3", "max": "\4"}/')
    throttled=$(echo "$k6_output" | sed -nE 's/.*api_requests_throttled\.+: ([0-9]+).*/\1/p')

    latency=$(psql "select json_build_object(
        'sent', count(*) filter (where status = 'sent'),
        'failed', count(*) filter (where status = 'failed'),
        'more_than_one_attempt', count(*) filter (where attempts > 1),
        'end_to_end_ms', json_build_object(
            'p50', round(percentile_cont(0.50) within group (order by extract(epoch from sent_at - created_at) * 1000)),
            'p95', round(percentile_cont(0.95) within group (order by extract(epoch from sent_at - created_at) * 1000)),
            'p99', round(percentile_cont(0.99) within group (order by extract(epoch from sent_at - created_at) * 1000)),
            'max', round(max(extract(epoch from sent_at - created_at) * 1000))))
        from notifications where content like 'Scale $size-%'")

    database=$(psql "select json_build_object(
        'notifications', (select count(*) from notifications),
        'attempts', (select count(*) from notification_attempts),
        'size_mb', round(pg_database_size(current_database()) / 1048576.0))")

    peaks=$(sort -k1,1n "$samples" | tail -1 | awk '{printf "%.0f", $1 / 1048576}')
    peak_backlog=$(sort -k2,2n "$samples" | tail -1 | awk '{print $2}')
    peak_broadcasts=$(sort -k3,3n "$samples" | tail -1 | awk '{print $3}')
    rm -f "$samples"

    reads=$(printf '{"list_filtered_ms": %s, "list_by_recipient_ms": %s, "metrics_ms": %s}' \
        "$(timed "$API/notifications?status=sent&channel=sms&per_page=50")" \
        "$(timed "$API/notifications?recipient=%2B905551234567&per_page=50")" \
        "$(timed "$API/metrics")")

    line=$(printf '{"k6": %s, "batch_latency": %s, "api_requests_throttled": %s, "redis_peak_mb": %s, "peak_backlog": %s, "peak_broadcast_queue": %s, "notifications": %s, "database": %s, "reads": %s}' \
        "${result:-null}" "${batches:-null}" "${throttled:-0}" "${peaks:-null}" "${peak_backlog:-null}" "${peak_broadcasts:-null}" "$latency" "$database" "$reads")
    echo "$line" | tee -a "$OUT_DIR/results.jsonl"
done
