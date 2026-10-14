#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
# A separate Compose project name prevents collision with existing deployments.
exec docker compose --project-name new-lims-rust-isolated -f compose.yaml -f compose.isolated.yaml "$@"
