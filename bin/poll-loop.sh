#!/usr/bin/env bash
# Poll the configured default station's connection decisions once per minute.
# The log is kept only for the default station, so this takes no station argument.
set -uo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$root/data"

# Interval from config (poll.interval_seconds), default 60s.
interval=$(php -r 'require $argv[1]; echo Bahn\Config::load($argv[2])->pollIntervalSeconds();' \
    "$root/src/bootstrap.php" "$root/config.yaml" 2>/dev/null || echo 60)
[ -n "$interval" ] || interval=60

echo "Polling the default station every ${interval}s -> $root/data/connection_log.json (log: $root/data/poll.log)"
while true; do
    if ! php "$root/bin/poll.php" >> "$root/data/poll.log" 2>&1; then
        echo "poll invocation failed" >> "$root/data/poll.log"
    fi
    sleep "$interval"
done
