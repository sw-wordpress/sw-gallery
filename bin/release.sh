#!/usr/bin/env bash
# Explicit operator command: version commit plus atomic branch/tag push. Never creates keys or sets secrets.
set -euo pipefail
cd "$(dirname "$0")/.."
version="${SWG_RELEASE_VERSION:-}"
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'Usage: make release VERSION=1.2.3' >&2; exit 1; }
branch="$(git symbolic-ref --quiet --short HEAD)"
[[ -z "$(git status --porcelain --untracked-files=all)" ]] || { echo 'Commit all changes before releasing.' >&2; exit 1; }
php -r 'exit(version_compare($argv[1],$argv[2],">")?0:1);' "$version" "$(bin/version.sh get)"
! git show-ref --verify --quiet "refs/tags/$version" || { echo 'Tag already exists locally.' >&2; exit 1; }
if git ls-remote --exit-code --tags origin "refs/tags/$version" "refs/tags/v$version"; then
  echo 'Release tag already exists on origin.' >&2; exit 1
else
  result=$?
  [[ "$result" = 2 ]] || exit "$result"
fi
bin/version.sh set "$version"
bin/version.sh check
git add sw-gallery.php CHANGELOG.md apps/admin/package.json apps/admin/package-lock.json assets/package.json assets/package-lock.json assets/blocks/package.json assets/blocks/package-lock.json e2e/package.json e2e/package-lock.json
git commit -m "Release $version"
git tag -a "$version" -m "Release $version"
git push --atomic origin "HEAD:refs/heads/$branch" "refs/tags/$version"
