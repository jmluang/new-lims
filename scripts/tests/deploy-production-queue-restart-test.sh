#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
deployment_script="$repo_root/scripts/deploy-production.sh"

python3 - "$deployment_script" <<'PY'
from pathlib import Path
import sys

script = Path(sys.argv[1]).read_text()
cache_warmed = script.index('"$active_backend/artisan" view:cache')
release_pruned = script.index('releases_dir="$deploy_root/releases"')
worker_restarted = script.find('systemctl restart lims-pdf-queue.service')

if not cache_warmed < worker_restarted < release_pruned:
    raise SystemExit('The LIMS queue worker must restart after cache warmup and before old releases are pruned.')

print('The LIMS queue worker restart is ordered before release pruning.')
PY
