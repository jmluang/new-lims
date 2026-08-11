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

The Java PDF signer is deliberately outside this release script. Its running
Docker container currently uses keys and source mounted from a separate
production directory; rebuilding it during every PHP/frontend release would
be unsafe. Manage it as a separate deployment once its own configuration is
also versioned and documented.

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
4. removes ignored local Laravel cache artifacts, then refreshes the caches
   from the final release path;
5. atomically changes `current` to the new commit directory.

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
