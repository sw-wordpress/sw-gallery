#!/usr/bin/env bash
# Dependency audit of every Composer and npm project (used by .github/workflows/security-audit.yml, runnable locally; needs network).
#
#   bin/security-audit.sh [report-dir]        default report dir: dist/audit-report/ (dist/ is git-ignored)
#
# BLOCKING (exit code 1): shipped dependencies, i.e. `composer audit --locked --no-dev` and `npm audit --omit=dev --audit-level=high`
#   for apps/admin, assets and assets/blocks. An audit that cannot run (network, registry error) also fails: no result is no success.
# REPORTED ONLY: development dependencies (all `composer audit --locked` runs and `npm audit --audit-level=high` of the three projects) and the
#   whole e2e project (dev-only tooling, never shipped). They are recorded in report.md and the JSON files but never fail the run.
# Output: <dir>/report.md (summary table + details), <dir>/<name>.txt and <dir>/<name>.json per audit.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/dist/audit-report}"
mkdir -p "$OUT"
REPORT="$OUT/report.md"
blocking_failed=0
RESULTS="$(mktemp)"
trap 'rm -f "$RESULTS"' EXIT

for tool in composer npm; do
  command -v "$tool" >/dev/null || { echo "missing prerequisite: $tool" >&2; exit 2; }
done

# audit <name> <blocking|report> <dir> <composer|npm> <args...>
audit() {
  local name="$1" mode="$2" dir="$3" tool="$4" status
  shift 4
  local json_flag="--json"
  [ "$tool" = composer ] && json_flag="--format=json"
  local audit_command=("$tool" audit "$@")
  if [ "$name" = composer-production ]; then
    audit_command=(php "$ROOT/bin/audit-composer-production.php")
  fi
  (cd "$ROOT/$dir" && "${audit_command[@]}" "$json_flag") >"$OUT/$name.json" 2>"$OUT/$name.err"
  (cd "$ROOT/$dir" && "${audit_command[@]}") >"$OUT/$name.txt" 2>&1
  status=$?
  if [ "$status" -eq 0 ]; then
    echo "| $name | $mode | no findings |" >>"$RESULTS"
  elif [ "$mode" = blocking ]; then
    blocking_failed=1
    echo "| $name | $mode | **FAILED** (findings or audit not possible, exit $status) |" >>"$RESULTS"
  else
    echo "| $name | $mode | findings reported (exit $status), not blocking |" >>"$RESULTS"
  fi
  rm -f "$OUT/$name.err"
}

audit composer-production blocking . composer --locked --no-dev
audit composer-dev report . composer --locked
audit composer-wp-tests-dev report tests/wp composer --locked
for project in apps/admin assets assets/blocks; do
  slug="${project//\//-}"
  audit "npm-$slug-production" blocking "$project" npm --omit=dev --audit-level=high
  audit "npm-$slug-dev" report "$project" npm --audit-level=high
done
audit npm-e2e-dev report e2e npm --audit-level=high

{
  echo "## Dependency audit"
  echo
  echo "| Audit | Mode | Result |"
  echo "|---|---|---|"
  cat "$RESULTS"
  echo
  echo "Blocking = shipped dependencies; report = development tooling (never shipped), see bin/security-audit.sh."
  for text in "$OUT"/*.txt; do
    printf '\n<details><summary>%s</summary>\n\n```text\n' "$(basename "$text" .txt)"
    head -c 6000 "$text"
    printf '\n```\n\n</details>\n'
  done
} >"$REPORT"

cat "$REPORT"
if [ "$blocking_failed" -ne 0 ]; then
  echo "security-audit: findings in shipped dependencies (or an audit could not run)" >&2
  exit 1
fi
echo "security-audit: no findings in shipped dependencies"
