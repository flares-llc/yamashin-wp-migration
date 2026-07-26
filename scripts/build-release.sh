#!/usr/bin/env bash

set -euo pipefail

version="${1:-0.1.0}"
ref="${2:-HEAD}"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dist_dir="${repo_root}/dist"
archive="${dist_dir}/yamashin-wp-migration-${version}.zip"
checksum="${archive}.sha256"

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
