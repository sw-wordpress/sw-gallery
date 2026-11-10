#!/usr/bin/env bash
# Rebuilds the generated frontend output after a source file changed.
#   apps/admin/**  -> assets/admin   (Vite build of the Vue/Quasar admin app, then the CSS scope check)
#   assets/**      -> assets/build   (Vite build of the lightbox, then the manifest/budget check)
#
# Used as a Claude Code PostToolUse hook (.claude/settings.json): reads the hook JSON on stdin and takes
# tool_input.file_path. Also callable by hand: bin/rebuild-on-change.sh <changed-file>.
# Exit 0 = nothing to do or build ok (quiet); exit 2 = build failed, the log tail goes to stderr so the
# editing agent sees the error immediately. Type checking is left to the normal gates (npm run build).
set -uo pipefail
export NO_COLOR=1 FORCE_COLOR=0

file="${1:-}"
if [ -z "$file" ]; then
  file="$(python3 -c 'import json,sys
try:
    d = json.load(sys.stdin)
    t = d.get("tool_input") or {}
    print(t.get("file_path") or (d.get("tool_response") or {}).get("filePath") or "")
except Exception:
    print("")' 2>/dev/null)"
fi
[ -n "$file" ] || exit 0
case "$file" in /*) ;; *) file="$PWD/$file" ;; esac

# The repository the edited file belongs to (works in git worktrees of parallel agents as well).
dir="$(dirname "$file")"
[ -d "$dir" ] || exit 0
root="$(git -C "$dir" rev-parse --show-toplevel 2>/dev/null)" || exit 0
rel="${file#"$root"/}"

project=""
case "$rel" in
  apps/admin/tests/* | apps/admin/coverage/* | apps/admin/node_modules/* | apps/admin/README.md) exit 0 ;;
  apps/admin/src/* | apps/admin/tools/* | apps/admin/index.html | apps/admin/vite.config.ts | apps/admin/package.json | apps/admin/tsconfig.json)
    project="apps/admin"
    check="node scripts/check-css-scope.mjs"
    ;;
  assets/lightbox/tests/* | assets/lightbox/fixtures/* | assets/frontend/tests/* | assets/admin/* | assets/build/* | assets/coverage/* | assets/node_modules/* | assets/README.md) exit 0 ;;
  assets/lightbox/src/* | assets/frontend/src/* | assets/vite.config.ts | assets/package.json | assets/tsconfig.json)
    project="assets"
    check="node scripts/check-build.mjs"
    ;;
  *) exit 0 ;;
esac

cd "$root/$project" || exit 0

# Node may be managed by nvm and is then missing from a hook's PATH.
if ! command -v node >/dev/null 2>&1; then
  export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
  # shellcheck disable=SC1091
  [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh" >/dev/null 2>&1 && nvm use >/dev/null 2>&1
fi
if ! command -v node >/dev/null 2>&1; then
  echo "rebuild-on-change: node not found, $project was not rebuilt." >&2
  exit 0
fi

# One build per project at a time; a second edit waits for the running build and then rebuilds.
lock="${TMPDIR:-/tmp}/swg-rebuild-$(printf '%s' "$root/$project" | cksum | cut -d' ' -f1).lock"
exec 9>"$lock"
flock 9

log="$(mktemp)"
trap 'rm -f "$log"' EXIT

if [ ! -d node_modules ]; then
  echo "rebuild-on-change: installing dependencies of $project (first run)..." >&2
  npm ci --no-audit --no-fund >"$log" 2>&1 || { tail -n 30 "$log" >&2; echo "rebuild-on-change: npm ci failed in $project." >&2; exit 2; }
fi

if ! { npm run build:only && $check; } >"$log" 2>&1; then
  tail -n 40 "$log" >&2
  echo "rebuild-on-change: build of $project FAILED (triggered by $rel)." >&2
  exit 2
fi
exit 0
