#!/usr/bin/env bash
# Deploy the Java PDF renderer used by the LIMS production application.
#
# The renderer owns the internal port 8080 separately from the Laravel release.
# Keep its signing keys outside the Git checkout, but publish its source from the
# same checked-out revision as the application.

set -Eeuo pipefail

readonly DEFAULT_HOST="124.223.160.180"
readonly DEFAULT_USER="ubuntu"
readonly DEFAULT_DEPLOY_ROOT="/www/wwwroot/lims.verify-pdf.com"
readonly DEFAULT_LEGACY_SERVICE_ROOT="/www/wwwroot/prod.verify-pdf.com/services/pdf-signer-java"
readonly PDF_PROJECT_NAME="lims-pdf-signer"

usage() {
  cat <<'EOF'
Usage: scripts/deploy-pdf-renderer-production.sh [--force] [--dry-run]

Environment overrides:
  DEPLOY_HOST                 Production host
  DEPLOY_USER                 SSH deployment user
  DEPLOY_ROOT                 LIMS production root
  PDF_LEGACY_SERVICE_ROOT     Existing service directory used only to migrate
                              the keys and stop the previous container
  SSH_PORT                    SSH port (default: 22)
  SSH_IDENTITY                Optional SSH private-key file

The renderer source is published to <DEPLOY_ROOT>/shared/pdf-renderer-java.
Signing keys are kept in its keys/ directory and are never copied from Git.
EOF
}

force=0
dry_run=0
while (($#)); do
  case "$1" in
    --force) force=1 ;;
    --dry-run) dry_run=1 ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Error: unknown option: %s\n' "$1" >&2; exit 1 ;;
  esac
  shift
done

repo_root="$(git rev-parse --show-toplevel 2>/dev/null)" || {
  printf '%s\n' 'Error: run this from a Git checkout' >&2
  exit 1
}
cd "$repo_root"
git diff --quiet || { printf '%s\n' 'Error: working tree has unstaged changes' >&2; exit 1; }
git diff --cached --quiet || { printf '%s\n' 'Error: working tree has staged changes' >&2; exit 1; }
[[ -z "$(git ls-files --others --exclude-standard)" ]] || {
  printf '%s\n' 'Error: working tree has untracked files' >&2
  exit 1
}

source_dir="$repo_root/services/pdf-renderer-java"
[[ -f "$source_dir/docker-compose.yml" && -f "$source_dir/Dockerfile" ]] || {
  printf '%s\n' 'Error: PDF renderer source is incomplete' >&2
  exit 1
}

release_sha="$(git rev-parse HEAD)"
deploy_host="${DEPLOY_HOST:-$DEFAULT_HOST}"
deploy_user="${DEPLOY_USER:-$DEFAULT_USER}"
deploy_root="${DEPLOY_ROOT:-$DEFAULT_DEPLOY_ROOT}"
legacy_service_root="${PDF_LEGACY_SERVICE_ROOT:-$DEFAULT_LEGACY_SERVICE_ROOT}"
ssh_port="${SSH_PORT:-22}"
target="$deploy_user@$deploy_host"

ssh_opts=(-o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 -p "$ssh_port")
if [[ -n "${SSH_IDENTITY:-}" ]]; then
  [[ -r "$SSH_IDENTITY" ]] || { printf '%s\n' "Error: SSH_IDENTITY is not readable" >&2; exit 1; }
  ssh_opts+=(-i "$SSH_IDENTITY")
fi

rsync_ssh='ssh'
for ssh_opt in "${ssh_opts[@]}"; do
  printf -v rsync_ssh '%s %q' "$rsync_ssh" "$ssh_opt"
done

pdf_root="$deploy_root/shared/pdf-renderer-java"
remote_staging="/tmp/lims-pdf-renderer-$release_sha-$$"

if ((dry_run)); then
  printf 'PDF renderer: %s\nTarget: %s:%s\n' "$release_sha" "$target" "$pdf_root"
  ssh "${ssh_opts[@]}" "$target" "sudo -n true && sudo test -d $(printf '%q' "$legacy_service_root/keys")"
  rsync -ani --delete --exclude='.git/' --exclude='keys/' --exclude='target/' \
    -e "$rsync_ssh" "$source_dir/" "$target:$remote_staging/"
  exit 0
fi

ssh "${ssh_opts[@]}" "$target" "sudo -n true && sudo rm -rf -- $(printf '%q' "$remote_staging") && sudo install -d -m 0755 -o $(printf '%q' "$deploy_user") -g www $(printf '%q' "$remote_staging")"
cleanup() {
  ssh "${ssh_opts[@]}" "$target" "sudo rm -rf -- $(printf '%q' "$remote_staging")" >/dev/null 2>&1 || true
}
trap cleanup EXIT

rsync -a --delete --exclude='.git/' --exclude='keys/' --exclude='target/' \
  -e "$rsync_ssh" "$source_dir/" "$target:$remote_staging/"

remote_args=()
for arg in "$release_sha" "$pdf_root" "$remote_staging" "$legacy_service_root" "$deploy_user" "$force"; do
  printf -v quoted_arg '%q' "$arg"
  remote_args+=("$quoted_arg")
done

ssh "${ssh_opts[@]}" "$target" "bash -s -- ${remote_args[*]}" <<'REMOTE_SCRIPT'
set -Eeuo pipefail

release_sha="$1"
pdf_root="$2"
staging_source="$3"
legacy_service_root="$4"
deploy_user="$5"
force="$6"
source_dir="$pdf_root/source"
keys_dir="$pdf_root/keys"
state_dir="$pdf_root/state"
marker="$state_dir/source-commit"
compose=(sudo docker compose --project-name lims-pdf-signer --file "$source_dir/docker-compose.yml")
old_container=''
old_image=''
old_backup_image="lims-pdf-signer:before-$release_sha"
old_was_running=0
switched=0

render_check() {
  local base_url="$1"
  local output_file
  output_file="$(mktemp /tmp/lims-pdf-renderer-check-XXXXXX.pdf)"
  trap 'rm -f -- "$output_file"' RETURN
  curl -fsS --max-time 30 -X POST "$base_url/api/pdf/entrust-order" \
    -H 'Content-Type: application/json' \
    --data-binary '{"base":{},"client":{},"manufacturer":{},"producer":{},"requirements":{},"samples":[],"logistics":{"laboratory_name":"中山市鑫普达检测有限公司"},"signatures":{},"meta":{}}' \
    -o "$output_file"
  test -s "$output_file"
  if command -v pdftotext >/dev/null; then
    pdftotext "$output_file" - | grep -Fqx '中山市鑫普达检测有限公司'
  fi
  rm -f -- "$output_file"
  trap - RETURN
}

rollback() {
  exit_code=$?
  if ((switched)); then
    printf '%s\n' 'PDF renderer switch failed; restoring previous container.' >&2
    "${compose[@]}" down >/dev/null 2>&1 || true
    if ((old_was_running)); then
      sudo docker tag "$old_backup_image" pdf-signer:local >/dev/null 2>&1 || true
      sudo docker compose --file "$legacy_service_root/docker-compose.yml" up -d >/dev/null 2>&1 || true
    fi
  fi
  exit "$exit_code"
}
trap rollback ERR

if [[ "$force" != '1' && -r "$marker" && "$(<"$marker")" == "$release_sha" ]]; then
  if curl -fsS --max-time 10 http://127.0.0.1:8080/api/pdf/health >/dev/null; then
    printf 'PDF renderer already deployed at %s\n' "$release_sha"
    exit 0
  fi
fi

sudo install -d -m 0755 -o "$deploy_user" -g www "$pdf_root" "$state_dir"
if [[ ! -d "$keys_dir" ]]; then
  [[ -d "$legacy_service_root/keys" ]] || { printf '%s\n' 'Missing existing PDF signing keys; refusing to deploy.' >&2; exit 1; }
  sudo cp -a "$legacy_service_root/keys" "$keys_dir"
fi
sudo install -d -m 0755 -o "$deploy_user" -g www "$source_dir"
sudo rsync -a --delete --exclude='keys/' --exclude='target/' "$staging_source/" "$source_dir/"
sudo rm -rf -- "$source_dir/keys"
sudo ln -s ../keys "$source_dir/keys"
sudo chown -R "$deploy_user:www" "$pdf_root"

old_container="$(sudo docker ps --filter 'name=^/pdf-signer-java-pdf-signer-1$' --format '{{.ID}}' | head -n1)"
if [[ -n "$old_container" ]]; then
  old_was_running=1
  old_image="$(sudo docker inspect --format '{{.Image}}' "$old_container")"
  sudo docker tag "$old_image" "$old_backup_image"
fi

"${compose[@]}" build

# Verify the newly built image before the existing port-8080 service is stopped.
smoke_name="lims-pdf-signer-smoke-$$"
sudo docker rm -f "$smoke_name" >/dev/null 2>&1 || true
sudo docker run -d --rm --name "$smoke_name" -p 127.0.0.1:18081:8081 \
  -v "$keys_dir:/keys:ro" pdf-signer:local >/dev/null
smoke_ok=0
for _ in $(seq 1 30); do
  if curl -fsS --max-time 2 http://127.0.0.1:18081/api/pdf/health >/dev/null; then smoke_ok=1; break; fi
  sleep 1
done
if ((smoke_ok)); then
  render_check http://127.0.0.1:18081 || smoke_ok=0
fi
sudo docker rm -f "$smoke_name" >/dev/null
((smoke_ok)) || { printf '%s\n' 'New PDF renderer did not pass the health and render checks.' >&2; exit 1; }

switched=1
if ((old_was_running)); then
  sudo docker compose --file "$legacy_service_root/docker-compose.yml" down
fi
"${compose[@]}" up -d --force-recreate --no-build

live_ok=0
for _ in $(seq 1 30); do
  if curl -fsS --max-time 2 http://127.0.0.1:8080/api/pdf/health >/dev/null; then live_ok=1; break; fi
  sleep 1
done
((live_ok)) || { printf '%s\n' 'PDF renderer did not become healthy on port 8080.' >&2; exit 1; }
render_check http://127.0.0.1:8080 || { printf '%s\n' 'PDF renderer failed the live rendering check.' >&2; exit 1; }

printf '%s\n' "$release_sha" | sudo tee "$marker" >/dev/null
printf 'PDF renderer deployed and healthy: %s\n' "$release_sha"
REMOTE_SCRIPT
