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
Usage: scripts/deploy-production.sh [--dry-run] [--run-migrations]

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
the current symlink. Database migrations are deliberately opt-in.
EOF
}

die() {
  printf 'Error: %s\n' "$*" >&2
  exit 1
}

dry_run=0
run_migrations=0
while (($#)); do
  case "$1" in
    --dry-run) dry_run=1 ;;
    --run-migrations) run_migrations=1 ;;
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

printf 'Release: %s\nTarget:  %s:%s\n' "$release_sha" "$target" "$deploy_root"

if ((dry_run)); then
  printf '%s\n' 'Dry run: validating SSH access and showing the upload delta only.'
  ssh_run 'sudo -n true'
  rsync -ani --delete \
    --exclude='.git/' \
    --exclude='backend/.env' \
    --exclude='backend/storage/' \
    --exclude='backend/vendor/' \
    --exclude='backend/node_modules/' \
    --exclude='backend/bootstrap/cache/*.php' \
    --exclude='backend/public/app/' \
    --exclude='backend/public/storage' \
    --exclude='frontend/node_modules/' \
    --exclude='frontend/dist/' \
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

rsync -a --delete --info=name,stats2 \
  --exclude='.git/' \
  --exclude='backend/.env' \
  --exclude='backend/storage/' \
  --exclude='backend/vendor/' \
  --exclude='backend/node_modules/' \
  --exclude='backend/bootstrap/cache/*.php' \
  --exclude='backend/public/app/' \
  --exclude='backend/public/storage' \
  --exclude='frontend/node_modules/' \
  --exclude='frontend/dist/' \
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

sudo chown -R "$deploy_user:$deploy_group" "$staging_release"
sudo find "$staging_release" -type d -exec chmod 2775 {} +
sudo find "$staging_release" -type f -exec chmod 0664 {} +

sudo ln -s "$shared_backend/.env" "$backend_dir/.env"
sudo ln -s "$shared_backend/storage" "$backend_dir/storage"
sudo ln -s "$shared_backend/storage/app/public" "$backend_dir/public/storage"

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
sudo -u "$deploy_user" "$php_bin" -d opcache.enable_cli=0 "$backend_dir/artisan" config:cache
sudo -u "$deploy_user" "$php_bin" -d opcache.enable_cli=0 "$backend_dir/artisan" route:cache
sudo -u "$deploy_user" "$php_bin" -d opcache.enable_cli=0 "$backend_dir/artisan" view:cache

sudo ln -s "$release_dir" "$deploy_root/current.next"
sudo mv -Tf "$deploy_root/current.next" "$deploy_root/current"

printf 'Activated release %s\n' "$release_sha"
REMOTE_SCRIPT

trap - ERR INT TERM
printf 'Deployment completed: %s\n' "$release_sha"
