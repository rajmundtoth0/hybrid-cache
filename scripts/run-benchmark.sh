#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/docker-compose.benchmark.yml"
WITH_BASE="http://127.0.0.1:8081"
WITHOUT_BASE="http://127.0.0.1:8082"
WORK_MS="${WORK_MS:-40}"
COLD_RUNS="${COLD_RUNS:-12}"
WARM_RUNS="${WARM_RUNS:-40}"

cleanup() {
    docker compose -f "$COMPOSE_FILE" down --remove-orphans >/dev/null 2>&1 || true
}

wait_for_url() {
    local url="$1"

    for _ in $(seq 1 60); do
        if curl -fsS "$url" >/dev/null 2>&1; then
            return 0
        fi

        sleep 1
    done

    echo "Timed out waiting for $url" >&2
    exit 1
}

fetch_json() {
    local url="$1"
    local attempt

    for attempt in 1 2 3; do
        if curl -fsS "$url"; then
            return 0
        fi

        sleep 1
    done

    echo "Request failed after retries: $url" >&2
    exit 1
}

json_field() {
    local field="$1"

    php -r '
        $field = $argv[1];
        $data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $value = $data;
        foreach (explode(".", $field) as $segment) {
            $value = $value[$segment];
        }
        if (is_bool($value)) {
            echo $value ? "true" : "false";
            exit(0);
        }
        echo $value;
    ' "$field"
}

report_stats() {
    local label="$1"
    local file="$2"

    php -r '
        $label = $argv[1];
        $file = $argv[2];
        $values = array_values(array_filter(array_map("trim", file($file) ?: []), static fn (string $value): bool => $value !== ""));
        $values = array_map("floatval", $values);
        sort($values);

        $count = count($values);
        if ($count === 0) {
            fwrite(STDERR, "No benchmark values recorded for {$label}\n");
            exit(1);
        }

        $sum = array_sum($values);
        $mean = $sum / $count;
        $median = $values[(int) floor(($count - 1) / 2)];
        $p95 = $values[(int) floor(($count - 1) * 0.95)];
        $min = $values[0];
        $max = $values[$count - 1];

        printf("%-28s count=%-3d avg=%8.2fms median=%8.2fms p95=%8.2fms min=%8.2fms max=%8.2fms\n", $label, $count, $mean, $median, $p95, $min, $max);
    ' "$label" "$file"
}

run_cold_series() {
    local label="$1"
    local base_url="$2"
    local query="$3"
    local file
    file="$(mktemp)"

    for index in $(seq 1 "$COLD_RUNS"); do
        local key="${label}:cold:${index}:$(date +%s%N)"
        local response
        response="$(fetch_json "$base_url/benchmark?key=$key&$query")"
        printf '%s\n' "$response" | json_field duration_ms >> "$file"
        printf '\n' >> "$file"
    done

    report_stats "$label cold" "$file"
    rm -f "$file"
}

run_warm_series() {
    local label="$1"
    local base_url="$2"
    local query="$3"
    local key="${label}:warm"
    local file
    file="$(mktemp)"

    fetch_json "$base_url/benchmark/reset?key=$key" >/dev/null
    fetch_json "$base_url/benchmark?key=$key&$query" >/dev/null

    for _ in $(seq 1 "$WARM_RUNS"); do
        local response
        response="$(fetch_json "$base_url/benchmark?key=$key&$query")"
        printf '%s\n' "$response" | json_field duration_ms >> "$file"
        printf '\n' >> "$file"
    done

    report_stats "$label warm" "$file"
    rm -f "$file"
}

run_stale_probe() {
    local key="with-package:stale:$(date +%s%N)"

    fetch_json "$WITH_BASE/benchmark/reset?key=$key" >/dev/null

    local first stale refreshed
    first="$(fetch_json "$WITH_BASE/benchmark?key=$key&ttl=1&stale=5&work_ms=$WORK_MS")"
    sleep 2
    stale="$(fetch_json "$WITH_BASE/benchmark?key=$key&ttl=1&stale=5&work_ms=$WORK_MS")"
    sleep 1
    refreshed="$(fetch_json "$WITH_BASE/benchmark?key=$key&ttl=1&stale=5&work_ms=$WORK_MS")"

    printf 'stale probe first:      duration=%sms computed=%s token=%s\n' \
        "$(printf '%s\n' "$first" | json_field duration_ms)" \
        "$(printf '%s\n' "$first" | json_field computed)" \
        "$(printf '%s\n' "$first" | json_field value.token)"

    printf 'stale probe stale-hit:  duration=%sms computed=%s token=%s\n' \
        "$(printf '%s\n' "$stale" | json_field duration_ms)" \
        "$(printf '%s\n' "$stale" | json_field computed)" \
        "$(printf '%s\n' "$stale" | json_field value.token)"

    printf 'stale probe refreshed:  duration=%sms computed=%s token=%s\n' \
        "$(printf '%s\n' "$refreshed" | json_field duration_ms)" \
        "$(printf '%s\n' "$refreshed" | json_field computed)" \
        "$(printf '%s\n' "$refreshed" | json_field value.token)"
}

trap cleanup EXIT

docker compose -f "$COMPOSE_FILE" up --build -d

wait_for_url "$WITH_BASE/"
wait_for_url "$WITHOUT_BASE/"

echo "Benchmark configuration: work_ms=$WORK_MS cold_runs=$COLD_RUNS warm_runs=$WARM_RUNS"

run_cold_series "with-package" "$WITH_BASE" "ttl=30&stale=60&work_ms=$WORK_MS"
run_cold_series "without-package" "$WITHOUT_BASE" "ttl=30&work_ms=$WORK_MS"
run_warm_series "with-package" "$WITH_BASE" "ttl=30&stale=60&work_ms=$WORK_MS"
run_warm_series "without-package" "$WITHOUT_BASE" "ttl=30&work_ms=$WORK_MS"
run_stale_probe
