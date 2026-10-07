#!/usr/bin/env bash
# Opt-in repair for a shared PDF tree after stopping PDF writes (or during a
# maintenance window). Never point this at a release's storage symlink.
set -Eeuo pipefail

usage() {
  printf 'Usage: %s [--apply] /absolute/path/to/storage/app/private/pdf group\n' "$0" >&2
  exit 2
}

apply=0
if [[ "${1:-}" == '--apply' ]]; then
  apply=1
  shift
fi
(($# == 2)) || usage
root="${1%/}"
group="$2"
[[ "$root" == /*/storage/app/private/pdf && -d "$root" && ! -L "$root" ]] || usage
[[ "$(realpath -e -- "$root")" == "$root" ]] || { printf 'Refusing a symlinked root.\n' >&2; exit 2; }
getent group "$group" >/dev/null || { printf 'Unknown group.\n' >&2; exit 2; }

if (( ! apply )); then
  printf 'Dry run only: would repair regular files and directories under %s for group %s. Pass --apply to change them.\n' "$root" "$group"
  exit 0
fi

# find defaults to -P (no symlink following). -xdev avoids mounted subtrees;
# preserve owner write bits on immutable 0440 files while giving group read.
find -P "$root" -xdev -type d -exec chgrp -- "$group" {} +
find -P "$root" -xdev -type f -exec chgrp -- "$group" {} +
find -P "$root" -xdev -type d -exec chmod 2770 -- {} +
find -P "$root" -xdev -type f -exec chmod g+r,o-rwx -- {} +
printf 'PDF storage group access repaired under %s.\n' "$root"
