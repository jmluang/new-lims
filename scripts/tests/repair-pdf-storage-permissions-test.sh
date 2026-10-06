#!/usr/bin/env bash
set -Eeuo pipefail
repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
fixture="$(mktemp -d "${TMPDIR:-/tmp}/pdf-permissions.XXXXXX")"
trap 'rm -rf -- "$fixture"' EXIT
root="$fixture/storage/app/private/pdf"
mkdir -p "$root/signed/2026/10" "$root/workflow/staging/operation/7"
printf signed > "$root/signed/2026/10/report.pdf"
printf candidate > "$root/workflow/staging/operation/7/candidate.pdf"
printf untouched > "$fixture/outside.pdf"
ln -s "$fixture/outside.pdf" "$root/outside.pdf"
chmod 2700 "$root/signed/2026/10"
chmod 2750 "$root/workflow/staging/operation/7"
chmod 0600 "$root/signed/2026/10/report.pdf"
chmod 0440 "$root/workflow/staging/operation/7/candidate.pdf"
chmod 0600 "$fixture/outside.pdf"
group="$(id -gn)"
script="$repo_root/scripts/repair-pdf-storage-permissions.sh"
bash "$script" "$root" "$group"
[[ "$(stat -c %a "$root/signed/2026/10")" == 2700 ]]
bash "$script" --apply "$root" "$group"
[[ "$(stat -c %a "$root/signed/2026/10")" == 2770 ]]
[[ "$(stat -c %a "$root/workflow/staging/operation/7")" == 2770 ]]
[[ "$(stat -c %a "$root/signed/2026/10/report.pdf")" == 640 ]]
[[ "$(stat -c %a "$root/workflow/staging/operation/7/candidate.pdf")" == 440 ]]
[[ "$(stat -c %a "$fixture/outside.pdf")" == 600 ]]
[[ "$(stat -c %g "$root/signed/2026/10/report.pdf")" == "$(id -g)" ]]
if bash "$script" --apply "$fixture" "$group" >/dev/null 2>&1; then
  printf 'Unsafe repair root was accepted.\n' >&2
  exit 1
fi
printf 'PDF permission repair fixture passed.\n'
