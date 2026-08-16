#!/usr/bin/env bash
#
# Run the PDF signing service locally, without Docker.
#
# The HMAC secret is shared with the Laravel application and nothing else checks
# that the two sides match, so it lives in .env.local (gitignored) instead of a
# shell export that disappears with the terminal that started it. Verify both
# sides afterwards with:  cd backend && php artisan pdf:check-runtime
#
set -euo pipefail

cd "$(dirname "$0")"

if [[ ! -f .env.local ]]; then
    echo "Missing .env.local. Copy the HMAC entry used by backend/.env into it:" >&2
    echo "  PDF_SERVICE_HMAC_ACTIVE_KEY_ID=primary" >&2
    echo "  PDF_SERVICE_HMAC_KEYS=primary:<base64 secret, >=32 raw bytes>" >&2
    exit 1
fi

set -a
# shellcheck disable=SC1091
source .env.local
set +a

if [[ -z "${PDF_SERVICE_HMAC_KEYS:-}" ]]; then
    echo "PDF_SERVICE_HMAC_KEYS is empty; the service would start with hmac_ready=false." >&2
    exit 1
fi

# Spring binds to 127.0.0.1 by default; keep the signing boundary on loopback.
exec mvn spring-boot:run
