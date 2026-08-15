#!/usr/bin/env bash
# Publish the committed new-lims revision to the existing production layout.
#
# This script is intentionally run from a checked-out repository (locally or
# from CI), never from the production server. It does not write credentials to
# the repository or upload .env / storage data.

set -Eeuo pipefail

readonly DEFAULT_HOST="124.223.160.180"
readonly DEFAULT_USER="ubuntu"
readonly DEFAULT_ROOT="/www/wwwroot/lims.verify-pdf.com"
readonly DEFAULT_GROUP="www"
readonly DEFAULT_PHP="/www/server/php/83/bin/php"
readonly DEFAULT_COMPOSER="/usr/bin/composer"

usage() {
  cat <<'EOF'
Usage: scripts/deploy-production.sh [--dry-run] [--run-migrations] [--skip-pdf-service]

Environment overrides:
  DEPLOY_HOST       Production host (default: 124.223.160.180)
  DEPLOY_USER       SSH/deployment user (default: ubuntu)
  DEPLOY_ROOT       Production site root (default: /www/wwwroot/lims.verify-pdf.com)
  DEPLOY_GROUP      Runtime Unix group (default: www)
  SSH_PORT          SSH port (default: 22)
  SSH_IDENTITY      Optional SSH private-key file
  DEPLOY_PHP        PHP executable on the server
  DEPLOY_COMPOSER   Composer executable on the server

The working tree must be clean. The release is identified by the checked-out
Git commit SHA, copied to releases/<SHA>, then activated by atomically swapping
the current symlink. Database migrations are deliberately opt-in. The Java PDF
renderer is updated from the same revision unless --skip-pdf-service is used.
EOF
}

die() {
  printf 'Error: %s\n' "$*" >&2
  exit 1
}

dry_run=0
run_migrations=0
skip_pdf_service=0
while (($#)); do
  case "$1" in
    --dry-run) dry_run=1 ;;
    --run-migrations) run_migrations=1 ;;
    --skip-pdf-service) skip_pdf_service=1 ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown option: $1" ;;
  esac
  shift
done

repo_root="$(git rev-parse --show-toplevel 2>/dev/null)" || die 'run this from a Git checkout'
cd "$repo_root"
git diff --quiet || die 'working tree has unstaged changes; commit or stash them first'
git diff --cached --quiet || die 'working tree has staged changes; commit them first'
[[ -z "$(git ls-files --others --exclude-standard)" ]] || die 'working tree has untracked files; commit, remove, or ignore them first'

command -v ssh >/dev/null || die 'ssh is required'
command -v rsync >/dev/null || die 'rsync is required'

release_sha="$(git rev-parse HEAD)"
deploy_host="${DEPLOY_HOST:-$DEFAULT_HOST}"
deploy_user="${DEPLOY_USER:-$DEFAULT_USER}"
deploy_root="${DEPLOY_ROOT:-$DEFAULT_ROOT}"
deploy_group="${DEPLOY_GROUP:-$DEFAULT_GROUP}"
deploy_php="${DEPLOY_PHP:-$DEFAULT_PHP}"
deploy_composer="${DEPLOY_COMPOSER:-$DEFAULT_COMPOSER}"
ssh_port="${SSH_PORT:-22}"
target="${deploy_user}@${deploy_host}"

ssh_opts=(
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o ConnectTimeout=15
  -p "$ssh_port"
)
if [[ -n "${SSH_IDENTITY:-}" ]]; then
  [[ -r "$SSH_IDENTITY" ]] || die "SSH_IDENTITY is not readable: $SSH_IDENTITY"
  ssh_opts+=(-i "$SSH_IDENTITY")
fi

ssh_run() {
  ssh "${ssh_opts[@]}" "$target" "$@"
}

rsync_ssh='ssh'
for ssh_opt in "${ssh_opts[@]}"; do
  printf -v rsync_ssh '%s %q' "$rsync_ssh" "$ssh_opt"
done

remote_quote_args() {
  local quoted=''
  local arg
  for arg in "$@"; do
    printf -v quoted '%s %q' "$quoted" "$arg"
  done
  printf '%s' "$quoted"
}

# rsync copies the working tree, not `git archive`, so everything Git ignores
# has to be repeated here or it ships to production anyway. The reference
# checkouts are the reason this matters: zs-lims alone is 40MB and carries its
# own signing keys, none of which belong on the production host.
#
# Shared by the dry run and the real upload so the two cannot drift apart.
rsync_excludes=(
  --exclude='.git/'
  --exclude='/.claude/'
  --exclude='/.vite/'
  --exclude='/example/'
  --exclude='/zs-lims/'
  --exclude='/output/'
  --exclude='/*.doc'
  --exclude='/*.docx'
  --exclude='/*.pdf'
  --exclude='/tmp/'
  # The renderer is published by scripts/deploy-pdf-renderer-production.sh,
  # which uploads its own copy and takes the signing keys from the server's
  # shared directory. Copying them here only scatters the key that signs every
  # report across release directories, alongside 300MB of Maven output.
  --exclude='/services/pdf-renderer-java/target/'
  --exclude='/services/pdf-renderer-java/keys/'
  --exclude='/services/pdf-renderer-java/tmp/'
  --exclude='backend/.env'
  --exclude='backend/storage/'
  --exclude='backend/vendor/'
  --exclude='backend/node_modules/'
  --exclude='backend/bootstrap/cache/*.php'
  --exclude='backend/public/app/'
  --exclude='backend/public/storage'
  --exclude='frontend/node_modules/'
  --exclude='frontend/dist/'
)

printf 'Release: %s\nTarget:  %s:%s\n' "$release_sha" "$target" "$deploy_root"

if ((dry_run)); then
  printf '%s\n' 'Dry run: validating SSH access and showing the upload delta only.'
  ssh_run 'sudo -n true'
  rsync -ani --delete \
    "${rsync_excludes[@]}" \
    -e "$rsync_ssh" \
    ./ "$target:/tmp/new-lims-release-preview-$release_sha/"
  exit 0
fi

remote_release="$deploy_root/releases/.tmp-$release_sha-$$"
cleanup_remote_release() {
  ssh_run "sudo rm -rf -- $(printf '%q' "$remote_release")" >/dev/null 2>&1 || true
}

if ! ssh_run "sudo test ! -e $(printf '%q' "$deploy_root/releases/$release_sha") && sudo mkdir -p $(printf '%q' "$deploy_root/releases") $(printf '%q' "$deploy_root/shared/backend/storage/app/public") && sudo install -d -m 2775 -o $(printf '%q' "$deploy_user") -g $(printf '%q' "$deploy_group") $(printf '%q' "$remote_release")"; then
  die "cannot create release staging directory (or release $release_sha already exists)"
fi
trap cleanup_remote_release ERR INT TERM

# --out-format/--stats rather than --info=name,stats2: the latter is GNU rsync
# 3.1+ only, and macOS ships openrsync, where the upload died on an unknown
# option before a release was ever staged.
rsync -a --delete --out-format='%n' --stats \
  "${rsync_excludes[@]}" \
  -e "$rsync_ssh" \
  ./ "$target:$remote_release/"

remote_args="$(remote_quote_args "$release_sha" "$deploy_root" "$remote_release" "$deploy_user" "$deploy_group" "$deploy_php" "$deploy_composer" "$run_migrations")"
ssh "${ssh_opts[@]}" "$target" "bash -s --$remote_args" <<'REMOTE_SCRIPT'
set -Eeuo pipefail

release_sha="$1"
deploy_root="$2"
staging_release="$3"
deploy_user="$4"
deploy_group="$5"
php_bin="$6"
composer_bin="$7"
run_migrations="$8"

release_dir="$deploy_root/releases/$release_sha"
shared_backend="$deploy_root/shared/backend"
backend_dir="$staging_release/backend"
frontend_dir="$staging_release/frontend"

fail() {
  printf 'Remote deployment error: %s\n' "$*" >&2
  exit 1
}

sudo -n true || fail "passwordless sudo is required for $deploy_user"
[[ -x "$php_bin" ]] || fail "PHP executable not found: $php_bin"
[[ -x "$composer_bin" ]] || fail "Composer executable not found: $composer_bin"
[[ -f "$shared_backend/.env" ]] || fail "missing shared backend .env: $shared_backend/.env"
[[ -d "$shared_backend/storage" ]] || fail "missing shared storage: $shared_backend/storage"
[[ -d "$backend_dir" && -d "$frontend_dir" ]] || fail 'uploaded release is incomplete'

# User-generated files must outlive a release.  A normal deployment excludes
# backend/storage entirely, but merge any storage directory that is present in
# the staging release before replacing it with the shared-storage symlink.  It
# protects uploads from older/manual deployment paths without ever deleting an
# existing shared file.
if [[ -d "$backend_dir/storage" && ! -L "$backend_dir/storage" ]]; then
  printf '%s\n' 'Merging release-local storage into shared persistent storage.'
  sudo rsync -a --ignore-existing "$backend_dir/storage/" "$shared_backend/storage/"
  sudo rm -rf -- "$backend_dir/storage"
fi

sudo chown -R "$deploy_user:$deploy_group" "$staging_release"
sudo find "$staging_release" -type d -exec chmod 2775 {} +
sudo find "$staging_release" -type f -exec chmod 0664 {} +

sudo ln -s "$shared_backend/.env" "$backend_dir/.env"
sudo ln -s "$shared_backend/storage" "$backend_dir/storage"
sudo ln -s "$shared_backend/storage/app/public" "$backend_dir/public/storage"

[[ "$(sudo readlink -f "$backend_dir/storage")" == "$shared_backend/storage" ]] \
  || fail 'release storage is not linked to shared persistent storage'
[[ "$(sudo readlink -f "$backend_dir/public/storage")" == "$shared_backend/storage/app/public" ]] \
  || fail 'public storage is not linked to shared persistent storage'

sudo -u "$deploy_user" "$php_bin" "$composer_bin" --working-dir="$backend_dir" install \
  --no-dev --prefer-dist --optimize-autoloader --no-interaction

sudo -u "$deploy_user" npm --prefix "$frontend_dir" ci
sudo -u "$deploy_user" npm --prefix "$frontend_dir" run build
sudo install -d -m 2775 -o "$deploy_user" -g "$deploy_group" "$backend_dir/public/app"
sudo rsync -a --delete "$frontend_dir/dist/" "$backend_dir/public/app/"
sudo chown -R "$deploy_user:$deploy_group" "$backend_dir/public/app"

sudo -u "$deploy_user" "$php_bin" "$backend_dir/artisan" storage:link --force

# Laravel's cached configuration contains absolute paths. Promote the completed
# release to its final immutable directory before generating those caches; only
# the `current` symlink remains unchanged until all steps have succeeded.
sudo mv -T "$staging_release" "$release_dir"
backend_dir="$release_dir/backend"

# A local checkout can have ignored Laravel cache files. Remove any such files
# before booting Artisan from the final path, otherwise config:cache may export
# stale absolute paths from the staging release.
sudo rm -f -- "$backend_dir/bootstrap/cache/config.php" \
  "$backend_dir/bootstrap/cache/routes-v7.php" \
  "$backend_dir/bootstrap/cache/events.php"

if [[ "$run_migrations" == '1' ]]; then
  printf '%s\n' 'Running requested database migrations before activation.'
  sudo -u "$deploy_user" "$php_bin" "$backend_dir/artisan" migrate --force
fi

# Disable CLI OPcache while producing these files. On this host its persistent
# CLI cache can otherwise keep the staging directory's absolute paths after a
# release directory is renamed.
sudo ln -s "$release_dir" "$deploy_root/current.next"
sudo mv -Tf "$deploy_root/current.next" "$deploy_root/current"

printf 'Activated release %s\n' "$release_sha"
REMOTE_SCRIPT

cache_args="$(remote_quote_args "$release_sha" "$deploy_root" "$deploy_user" "$deploy_php")"
ssh "${ssh_opts[@]}" "$target" "bash -s --$cache_args" <<'CACHE_SCRIPT'
set -Eeuo pipefail

release_sha="$1"
deploy_root="$2"
deploy_user="$3"
php_bin="$4"
active_release="$(sudo readlink -f "$deploy_root/current")"

[[ "$active_release" == "$deploy_root/releases/$release_sha" ]] || {
  printf 'Refusing to cache a different active release: %s\n' "$active_release" >&2
  exit 1
}

# This is deliberately a fresh SSH/PHP CLI process. The host keeps realpath
# state during a long build session, and Laravel writes absolute paths to its
# cache files. The stable current symlink avoids stale release paths.
active_backend="$deploy_root/current/backend"
sudo rm -f -- "$active_backend/bootstrap/cache/config.php" \
  "$active_backend/bootstrap/cache/routes-v7.php" \
  "$active_backend/bootstrap/cache/events.php"
sudo -u "$deploy_user" "$php_bin" -d opcache.enable_cli=0 "$active_backend/artisan" config:cache
sudo -u "$deploy_user" "$php_bin" -d opcache.enable_cli=0 "$active_backend/artisan" route:cache
sudo -u "$deploy_user" "$php_bin" -d opcache.enable_cli=0 "$active_backend/artisan" view:cache
CACHE_SCRIPT

if (( ! skip_pdf_service )); then
  if [[ -n "${SSH_IDENTITY:-}" ]]; then
    DEPLOY_HOST="$deploy_host" DEPLOY_USER="$deploy_user" DEPLOY_ROOT="$deploy_root" \
      SSH_PORT="$ssh_port" SSH_IDENTITY="$SSH_IDENTITY" \
      "$repo_root/scripts/deploy-pdf-renderer-production.sh"
  else
    DEPLOY_HOST="$deploy_host" DEPLOY_USER="$deploy_user" DEPLOY_ROOT="$deploy_root" \
      SSH_PORT="$ssh_port" "$repo_root/scripts/deploy-pdf-renderer-production.sh"
  fi
fi

trap - ERR INT TERM
printf 'Deployment completed: %s\n' "$release_sha"
