#!/usr/bin/env bash
# Start the development server with the document root pinned to public/.
# Usage: bin/serve.sh [host:port]   (default 127.0.0.1:8077)
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
addr="${1:-127.0.0.1:8077}"

echo "Serving ${root}/public at http://${addr}/"
exec php -S "${addr}" -t "${root}/public"
