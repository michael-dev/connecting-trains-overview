#!/usr/bin/env bash
# Poll the configured default station's connection decisions once per minute.
# The log is kept only for the default station, so this takes no station argument.
set -uo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$root/data"

echo "Polling the default station every 60s -> $root/data/connection_log.json (log: $root/data/poll.log)"
while true; do
    if ! php "$root/bin/poll.php" >> "$root/data/poll.log" 2>&1; then
        echo "poll invocation failed" >> "$root/data/poll.log"
    fi
    sleep 60
done
