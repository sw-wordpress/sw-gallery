#!/usr/bin/env bash
# Rebuilds the generated frontend output whenever a source file changes, for edits made by hand
# (Claude Code does this through the PostToolUse hook, see bin/rebuild-on-change.sh).
#   bin/watch-build.sh        watches apps/admin (-> assets/admin) and assets (-> assets/build)
# Stop with CTRL-C. Needs the dependencies installed (npm ci in apps/admin and assets).
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"

pids=()
cleanup() { kill "${pids[@]}" 2>/dev/null || true; }
trap cleanup EXIT INT TERM

for project in apps/admin assets; do
  if [ ! -d "$root/$project/node_modules" ]; then
    echo "watch-build: $project/node_modules missing - run: (cd $project && npm ci)" >&2
    exit 1
  fi
  (cd "$root/$project" && npm run --silent build:watch) &
  pids+=($!)
done
echo "watch-build: watching apps/admin and assets (CTRL-C to stop)"
wait
