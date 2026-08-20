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
  SSH_PROXY_COMMAND           Optional OpenSSH ProxyCommand for the host

The renderer source is published to <DEPLOY_ROOT>/shared/pdf-renderer-java.
Signing keys are kept in its keys/ directory and are never copied from Git.
Runtime secrets are read from <DEPLOY_ROOT>/shared/pdf-renderer-java/.env and
are never copied from Git.
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
if [[ -n "${SSH_PROXY_COMMAND:-}" ]]; then
  ssh_opts+=(-o "ProxyCommand=$SSH_PROXY_COMMAND")
fi

rsync_ssh='ssh'
for ssh_opt in "${ssh_opts[@]}"; do
  printf -v rsync_ssh '%s %q' "$rsync_ssh" "$ssh_opt"
done

pdf_root="$deploy_root/shared/pdf-renderer-java"
remote_staging="/tmp/lims-pdf-renderer-$release_sha-$$"

if ((dry_run)); then
  printf 'PDF renderer: %s\nTarget: %s:%s\n' "$release_sha" "$target" "$pdf_root"
  ssh "${ssh_opts[@]}" "$target" \
    "sudo -n true && sudo test -f $(printf '%q' "$pdf_root/.env") && { sudo test -d $(printf '%q' "$pdf_root/keys") || sudo test -d $(printf '%q' "$legacy_service_root/keys"); }"
  rsync -ani --delete --exclude='.git/' --exclude='/keys/' --exclude='/target/' --exclude='/fonts/' \
    -e "$rsync_ssh" "$source_dir/" "$target:$remote_staging/"
  exit 0
fi

ssh "${ssh_opts[@]}" "$target" "sudo -n true && sudo rm -rf -- $(printf '%q' "$remote_staging") && sudo install -d -m 0755 -o $(printf '%q' "$deploy_user") -g www $(printf '%q' "$remote_staging")"
cleanup() {
  ssh "${ssh_opts[@]}" "$target" "sudo rm -rf -- $(printf '%q' "$remote_staging")" >/dev/null 2>&1 || true
}
trap cleanup EXIT

rsync -a --delete --exclude='.git/' --exclude='/keys/' --exclude='/target/' --exclude='/fonts/' \
  -e "$rsync_ssh" "$source_dir/" "$target:$remote_staging/"

remote_args=()
for arg in "$release_sha" "$pdf_root" "$deploy_root" "$remote_staging" "$legacy_service_root" "$deploy_user" "$force"; do
  printf -v quoted_arg '%q' "$arg"
  remote_args+=("$quoted_arg")
done

ssh "${ssh_opts[@]}" "$target" "bash -s -- ${remote_args[*]}" <<'REMOTE_SCRIPT'
set -Eeuo pipefail

release_sha="$1"
pdf_root="$2"
deploy_root="$3"
staging_source="$4"
legacy_service_root="$5"
deploy_user="$6"
force="$7"
source_dir="$pdf_root/source"
keys_dir="$pdf_root/keys"
fonts_dir="$pdf_root/fonts"
state_dir="$pdf_root/state"
env_file="$pdf_root/.env"
backend_dir="$deploy_root/current/backend"
php_bin="${PDF_SMOKE_PHP:-/www/server/php/83/bin/php}"
marker="$state_dir/source-commit"
bundled_song_font="$staging_source/src/main/resources/fonts/LimsSongSC-Regular.ttf"
compose=(sudo docker compose --env-file "$env_file" --project-name lims-pdf-signer --file "$source_dir/docker-compose.yml")
old_container=''
old_image=''
old_backup_image="lims-pdf-signer:before-$release_sha"
old_was_running=0
old_runtime=''
switched=0

render_check() {
  local base_url="$1"
  local output_file
  output_file="$(mktemp /tmp/lims-pdf-renderer-check-XXXXXX.pdf)"
  if ! sudo -u "$deploy_user" env \
    PDF_SMOKE_BASE_URL="$base_url" PDF_SMOKE_OUTPUT_FILE="$output_file" \
    "$php_bin" -d opcache.enable_cli=0 "$backend_dir/artisan" tinker --execute='config(["pdf_service.base_url" => getenv("PDF_SMOKE_BASE_URL")]); $payload = ["base" => [], "client" => [], "manufacturer" => [], "producer" => [], "requirements" => [], "samples" => [], "logistics" => ["laboratory_name" => "中山市鑫普达检测有限公司"], "signatures" => [], "meta" => []]; file_put_contents(getenv("PDF_SMOKE_OUTPUT_FILE"), app(\App\Services\Pdf\PdfRendererClient::class)->renderEntrustOrder($payload));'; then
    rm -f -- "$output_file"
    return 1
  fi
  if ! test -s "$output_file"; then
    rm -f -- "$output_file"
    return 1
  fi
  if command -v pdftotext >/dev/null && ! pdftotext "$output_file" - | grep -Fqx '中山市鑫普达检测有限公司'; then
    rm -f -- "$output_file"
    return 1
  fi
  if command -v pdffonts >/dev/null && ! pdffonts "$output_file" | grep -Fq 'LIMSSongSC-Regular'; then
    rm -f -- "$output_file"
    return 1
  fi
  rm -f -- "$output_file"
}

rollback() {
  if ((switched)); then
    printf '%s\n' 'PDF renderer switch failed; restoring previous container.' >&2
    "${compose[@]}" down >/dev/null 2>&1 || true
    if ((old_was_running)); then
      sudo docker tag "$old_backup_image" pdf-signer:local >/dev/null 2>&1 || true
      if [[ "$old_runtime" == 'compose' ]]; then
        "${compose[@]}" up -d --force-recreate --no-build >/dev/null 2>&1 || true
      else
        sudo docker compose --file "$legacy_service_root/docker-compose.yml" up -d >/dev/null 2>&1 || true
      fi
    fi
  fi
}

on_error() {
  exit_code=$?
  rollback
  exit "$exit_code"
}
trap on_error ERR

if [[ "$force" != '1' && -r "$marker" && "$(<"$marker")" == "$release_sha" ]]; then
  if curl -fsS --max-time 10 http://127.0.0.1:8080/api/pdf/health >/dev/null; then
    printf 'PDF renderer already deployed at %s\n' "$release_sha"
    exit 0
  fi
fi

sudo install -d -m 0755 -o "$deploy_user" -g www "$pdf_root" "$state_dir"
[[ -r "$env_file" ]] || { printf '%s\n' "Missing PDF renderer environment: $env_file" >&2; exit 1; }
[[ -x "$php_bin" && -f "$backend_dir/artisan" ]] || { printf '%s\n' 'Laravel smoke client is unavailable.' >&2; exit 1; }
if [[ ! -d "$keys_dir" ]]; then
  [[ -d "$legacy_service_root/keys" ]] || { printf '%s\n' 'Missing existing PDF signing keys; refusing to deploy.' >&2; exit 1; }
  sudo cp -a "$legacy_service_root/keys" "$keys_dir"
fi
if [[ ! -r "$fonts_dir/NotoSansSC-VariableFont_wght.ttf" ]]; then
  legacy_font="$legacy_service_root/src/main/resources/fonts/NotoSansSC-VariableFont_wght.ttf"
  [[ -r "$legacy_font" ]] || { printf '%s\n' 'Missing the existing PDF renderer CJK font; refusing to deploy.' >&2; exit 1; }
  sudo install -d -m 0755 -o "$deploy_user" -g www "$fonts_dir"
  sudo install -m 0644 -o "$deploy_user" -g www "$legacy_font" "$fonts_dir/NotoSansSC-VariableFont_wght.ttf"
fi
[[ -r "$bundled_song_font" ]] || { printf '%s\n' 'Missing bundled LIMS Song SC font; refusing to deploy.' >&2; exit 1; }
sudo install -m 0644 -o "$deploy_user" -g www "$bundled_song_font" "$fonts_dir/LimsSongSC-Regular.ttf"
sudo install -d -m 0755 -o "$deploy_user" -g www "$source_dir"
sudo rsync -a --delete --exclude='/keys/' --exclude='/fonts/' --exclude='/target/' "$staging_source/" "$source_dir/"
sudo rm -rf -- "$source_dir/keys" "$source_dir/fonts"
sudo ln -s ../keys "$source_dir/keys"
sudo ln -s ../fonts "$source_dir/fonts"
sudo chown "$deploy_user:www" "$pdf_root" "$keys_dir" "$fonts_dir" "$state_dir"
sudo chown -R "$deploy_user:www" "$source_dir" "$fonts_dir" "$state_dir"

old_container="$("${compose[@]}" ps -q pdf-signer | head -n1)"
if [[ -n "$old_container" ]]; then
  old_runtime='compose'
else
  old_container="$(sudo docker ps --filter 'name=^/pdf-signer-java-pdf-signer-1$' --format '{{.ID}}' | head -n1)"
  [[ -z "$old_container" ]] || old_runtime='legacy'
fi
if [[ -n "$old_container" ]]; then
  old_was_running=1
  old_image="$(sudo docker inspect --format '{{.Image}}' "$old_container")"
  sudo docker tag "$old_image" "$old_backup_image"
fi

"${compose[@]}" build

# Verify the newly built image before the existing port-8080 service is stopped.
smoke_name="lims-pdf-signer-smoke-$$"
result_root_host="$(sudo awk -F= '$1 == "PDF_EXECUTION_RESULT_ROOT_HOST" {sub(/^[^=]*=/, ""); print; exit}' "$env_file")"
[[ "$result_root_host" == /* ]] || { printf '%s\n' 'PDF_EXECUTION_RESULT_ROOT_HOST must be an absolute path.' >&2; exit 1; }
sudo install -d -m 0750 -o "$deploy_user" -g www "$result_root_host"
sudo docker rm -f "$smoke_name" >/dev/null 2>&1 || true
sudo docker run -d --rm --name "$smoke_name" -p 127.0.0.1:18081:8081 \
  --env-file "$env_file" --add-host host.docker.internal:host-gateway \
  -e PDF_SERVICE_BIND_ADDRESS=0.0.0.0 -e PDF_EXECUTION_LEDGER_ENABLED=true \
  -e PDF_SERVICE_HMAC_NONCE_STORE=memory \
  -v "$keys_dir:/keys:ro" -v "$fonts_dir:/fonts:ro" \
  -v "$result_root_host:/data/signing-results" pdf-signer:local >/dev/null
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
if ((old_was_running)) && [[ "$old_runtime" == 'legacy' ]]; then
  sudo docker compose --file "$legacy_service_root/docker-compose.yml" down
fi
"${compose[@]}" up -d --force-recreate --no-build

live_ok=0
for _ in $(seq 1 30); do
  if curl -fsS --max-time 2 http://127.0.0.1:8080/api/pdf/health >/dev/null; then live_ok=1; break; fi
  sleep 1
done
if (( ! live_ok )); then
  printf '%s\n' 'PDF renderer did not become healthy on port 8080.' >&2
  rollback
  exit 1
fi
if ! render_check http://127.0.0.1:8080; then
  printf '%s\n' 'PDF renderer failed the live rendering check.' >&2
  rollback
  exit 1
fi

printf '%s\n' "$release_sha" | sudo tee "$marker" >/dev/null
printf 'PDF renderer deployed and healthy: %s\n' "$release_sha"
REMOTE_SCRIPT
