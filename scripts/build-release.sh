#!/usr/bin/env bash

set -euo pipefail

version="${1:?Usage: build-release.sh VERSION [GIT_REF]}"
ref="${2:-HEAD}"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dist_dir="${repo_root}/dist"
archive="${dist_dir}/yamashin-wp-migration-${version}.zip"
checksum="${archive}.sha256"

if [[ ! "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
  printf 'Invalid release version: %s\n' "${version}" >&2
  exit 1
fi

require_version() {
  local path="$1"
  local expected="$2"
  if ! git -C "${repo_root}" show "${ref}:${path}" | grep -F -- "${expected}" >/dev/null; then
    printf 'Release version %s is not reflected in %s at %s\n' "${version}" "${path}" "${ref}" >&2
    exit 1
  fi
}

require_version flares-sync.php " * Version: ${version}"
require_version flares-sync.php "define('FSYNC_VERSION', '${version}');"
require_version readme.txt "Stable tag: ${version}"
require_version mcp/package.json "\"version\": \"${version}\""
require_version mcp/package-lock.json "\"version\": \"${version}\""

mkdir -p "${dist_dir}"
rm -f "${archive}" "${checksum}"

git -C "${repo_root}" archive \
  --format=zip \
  --prefix=flares-sync/ \
  --output="${archive}" \
  "${ref}"

(
  cd "${dist_dir}"
  shasum -a 256 "$(basename "${archive}")" > "$(basename "${checksum}")"
)

printf 'Built %s\n' "${archive}"
printf 'Checksum %s\n' "${checksum}"
