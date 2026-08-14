# Production deployment: `124.223.160.180`

The New LIMS production site is deployed from a checked-out repository using
[`scripts/deploy-production.sh`](../../scripts/deploy-production.sh).

## Production layout

```text
/www/wwwroot/lims.verify-pdf.com/
├── current -> releases/<git-commit-sha>
├── releases/
│   ├── <git-commit-sha>/
│   └── .tmp-<git-commit-sha>-<pid>/   # staging, never served
└── shared/backend/
    ├── .env                           # production-only configuration
    └── storage/                       # user uploads, logs, framework data
```

Nginx serves `current/backend/public`, with the React SPA under
`current/backend/public/app`. The server runs PHP 8.3/PHP-FPM. The `current`
symlink is the only serving pointer, so replacing it is an atomic activation
and changing it back provides a quick code rollback.

### Storage path must be pinned

The shared `.env` must set:

```dotenv
LARAVEL_STORAGE_PATH=/www/wwwroot/lims.verify-pdf.com/shared/backend/storage
```

Laravel derives `storage_path()` from the directory the application booted
from, and `config:cache` writes that absolute path into
`bootstrap/cache/config.php` — every filesystem disk root and the log file
path. In a release-directory layout that bakes one specific release (or worse,
a `.tmp-` staging directory) into the cache. Once that directory is replaced or
cleaned up, uploads and logs are written to a path nobody serves from: the
database rows remain, the files disappear on the next deploy.

This was observed in production, where all four disks (`local`, `public`,
`equipment`, `pdf`) and the log path pointed at a staging directory that no
longer existed, and previously uploaded seal images were lost. Pinning the
variable makes the path independent of which directory boots the application.

The Java PDF renderer is deployed from the same committed revision as the web
application. Its source is copied to `shared/pdf-renderer-java/source`, while
the signing keys remain in `shared/pdf-renderer-java/keys` and are never stored
in Git. The deploy script builds and health-checks a temporary container before
switching the internal port-8080 service. It skips rebuilding when the deployed
renderer commit already matches; use `--force` only for renderer recovery.

`scripts/deploy-production.sh` invokes this renderer deployment automatically.
Use `--skip-pdf-service` only for an intentional, temporary web-only rollback.

## Run a release

From a clean checkout on the desired commit:

```bash
cd /path/to/new-lims
scripts/deploy-production.sh --dry-run
scripts/deploy-production.sh
```

The default target is `ubuntu@124.223.160.180`. It uses the SSH key selected by
your normal SSH configuration. A CI job should provide its private key through
a secret and set `SSH_IDENTITY=/path/to/key`; do not put keys or production
environment values in the repository.

The script:

1. uploads committed source to a new temporary release directory;
2. links the existing shared `.env` and `storage` (they are never uploaded);
3. runs `composer install`, builds the React app, and publishes it to
   `backend/public/app`;
4. atomically changes `current` to the new commit directory;
5. removes ignored local Laravel cache artifacts, then refreshes the caches
   through the stable `current` path with CLI OPcache disabled, in a fresh
   server session.

It intentionally does **not** run database migrations by default. If a release
contains reviewed, backward-compatible migrations, run:

```bash
scripts/deploy-production.sh --run-migrations
```

Database migrations are not reversible simply by changing `current`, so use
that option only after reviewing the migration and taking the required backup.

## Roll back application code

List the releases, choose a known-good SHA, and repoint the symlink:

```bash
ssh ubuntu@124.223.160.180 \
  'sudo ln -s /www/wwwroot/lims.verify-pdf.com/releases/<known-good-sha> /www/wwwroot/lims.verify-pdf.com/current.next && sudo mv -Tf /www/wwwroot/lims.verify-pdf.com/current.next /www/wwwroot/lims.verify-pdf.com/current'
```

This reverts application files only. It does not reverse migrations or data
writes made by the newer release.
