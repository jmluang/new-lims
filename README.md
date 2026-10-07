# New LIMS

New LIMS is a split React SPA + Laravel API laboratory management system. The legacy `zs-lims/` checkout is kept in this repository only as a business reference while the new implementation is built under `frontend/`, `backend/`, and `services/`.

## Directory Layout

```text
.
├── backend/                 Laravel 13 API, auth, permissions, audit, backups, exports
├── frontend/                React + TypeScript SPA, routing, UI, forms, table views
├── services/
│   └── pdf-renderer-java/   Spring Boot PDF rendering/signing service
├── docs/
│   └── plans/               Architecture, implementation, and acceptance plans
├── zs-lims/                 Legacy reference project, not part of deployment
└── example/                 Reference/sample assets, not part of deployment
```

### `backend/`

Laravel owns all server-side security and data contracts:

- Sanctum authentication
- group-based permissions through `spatie/laravel-permission`
- field-level read/update/export filtering
- append-only audit logs with hash chaining
- customers, customer contacts, equipment, equipment locations, labels
- dictionary options
- backup and restore APIs
- Java PDF service client integration
- PDF tamper-proof signing, digest ledger, and public report verification

Important commands:

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
php artisan test
```

### `frontend/`

React owns the browser experience:

- TanStack Router route guards
- TanStack Query API data loading
- permission-gated navigation and actions
- field visibility in tables and forms
- responsive desktop/mobile layouts
- print-oriented equipment label pages

Important commands:

```bash
cd frontend
npm install
npm run dev
npm run lint
npm run test
npm run build
```

### `services/pdf-renderer-java/`

The Java service handles PDF workloads that should not live inside PHP:

- legacy `/api/pdf/process` compatibility
- PDF stamping/signing pipeline
- cover extraction
- entrust order and contract rendering endpoints

Important commands:

```bash
cd services/pdf-renderer-java
mvn -q -e -B -DskipTests package
java -jar target/pdf-signer-*.jar
```

### `docs/`

Planning and acceptance documentation. Start here before changing scope:

- `docs/plans/2026-05-24-new-lims-react-laravel13-implementation-plan.md`
- `docs/plans/2026-05-24-new-lims-acceptance-test-plan.md`

### `zs-lims/`

Legacy reference only. Use it to check old business fields, migrations, and workflow behavior. Do not deploy it with the new system.

## Local Development

Use separate terminals for backend and frontend.

Backend:

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

Frontend:

```bash
cd frontend
npm install
VITE_API_BASE_URL=http://127.0.0.1:8000 npm run dev -- --host 127.0.0.1 --port 5173
```

Optional Java PDF service:

```bash
cd services/pdf-renderer-java
mvn -q -e -B -DskipTests package
java -jar target/pdf-signer-*.jar
```

For local PDF integration, set these in `backend/.env`:

```dotenv
PDF_SERVICE_ENABLED=true
PDF_SERVICE_BASE_URL=http://127.0.0.1:8080
PDF_SERVICE_TIMEOUT=120
```

### LM-79 report preparation

`/reports/lm79` prepares reports that each reference one received physical sample.
Commission parties, product details and standards populate the editable form. The report
keeps its source snapshot, private instrument files, photos and PDF appendices.
Entering the creation page reserves an `XPDYYYYMMDD-NNN` number from a daily
sequence; regeneration reserves another number, while editing retains the stored
number. Abandoned forms can leave sequence gaps.
Spectral measurements are stored as ordered rows in `lm79_spectrum_points`;
scalar fields remain on the report. Native readers adapted from `haas_read.php`
import HAAS colorimetry and spectra, with an explicit record choice for multi-record
files. GODATA 100 imports voltage, current, power and power factor from the four
float32 values after seven sample-detail CStrings, plus luminous flux and environment.
An all-zero electrical header preserves manual input. Frequency is read 12 bytes
before the R_V1 boundary after all angle matrices. Displacement factor uses its
own availability flag; unavailable values become blank inputs and are never
replaced by PF. Array bounds and availability flags are checked. THD and other
electrical fields are not inferred from anonymous OP settings. Uploading GOS also
imports its native C-gamma photometry, retaining the header's measured flux.
Import requires PHP mbstring, caps HAAS at
100 records and 10,000 spectral points per record, and keeps the original files.
Saving preserves corrections, including cleared values, after a record is selected.
HAAS Duv is derived from the saved x/y/CCT with the instrument's CIE 1931 5 nm
matching table and Planckian-locus calculation. SDCM uses the legacy F-series
ellipse parameters shown in the reference PDFs, choosing the closest nominal
CCT by default. The F target and centre coordinates are filled into the existing
editable target input; they are not labelled as the separate ANSI377 table.
Invalid colorimetry leaves derived inputs blank. No external DLL or local
installation path is required at runtime.
Signer names are not entered in the report form. Inspector, reviewer and issuer
identities are assigned in the signing workflow; unsigned PDFs leave their names blank.

IES uploads immediately parse Type-C, TILT=NONE photometry and fill distribution
parameters, calculated flux and efficacy using the current power input. When GOS
is also attached, its native photometry takes precedence regardless of upload
order; IES is still validated and retained. IES does not need a record selection.
The explicit calculation action uses the native GOS C-gamma grid when available,
or Type-C, TILT=NONE IES photometry otherwise. GOS coordinates preserve the C0/180
direction in its instrument PDF; standalone IES retains its own coordinates.
Ring flux uses mean endpoint intensity weighted by solid angle. A repeated C360
closure does not add a measurement plane. Stored precision is preserved, while
PDF values follow the reference instrument's display precision. Saving never
overwrites manual corrections. PDF preview uses
the Java `/api/pdf/lm79-report` renderer, including spectral charts and merged
unsigned, unencrypted appendices. Submitting freezes the draft and hands its PDF
to the existing source inspection, finalization and three-person signing workflow.
Apply the new migrations and deploy the Laravel, React and Java changes together.
Access is controlled by `lm79_reports` permissions; signing preparation also needs
`pdf.workflow.create`. Frozen generated documents cannot be independently renamed
or deleted in the signing document list.

### PDF tamper-proof system

The signing desk (`/pdf/signing`) stamps and signs a report through the Java
service, then records its SHA-256, MD5 and byte length. Verification recomputes
those digests and looks for a matching ledger row, so any edit — even one that
preserves the file size — fails the check. Report recipients can verify a file
themselves at `/verify` without logging in.

The "处理光度数据后签名" mode is ported but disabled; see
[the migration plan](docs/plans/2026-08-13-pdf-tamper-proof-migration-plan.md)
for how to turn it on and why it ships off.

## Production Deployment

### Yanzhenjia synchronization

The legacy sender enqueues completed PDFs from the signing desk and the
three-person signing workflow for a one-way copy.
`pdf:dispatch-yanzhenjia-syncs` runs every minute and the database queue worker
transfers the exact signed bytes, SHA-256, MD5 and
size to `POST /api/integrations/new-lims/reports`. The target maps each request
to the configured `zdlmmm` account and treats repeated source file IDs as the
same upload. It also keeps the original report number for public report lookup.
Failed transfers remain visible in `pdf_yanzhenjia_syncs` and are
retried; they do not change the local signing result.

Deploy the Yanzhenjia API before enabling this sender. Set the same random
secret of at least 32 characters in `LIMS_SYNC_SECRET` on Yanzhenjia and
`YANZHENJIA_SYNC_SECRET` here, then set `YANZHENJIA_SYNC_ENABLED=true` here.
Both hosts need clocks within five minutes. Keep the scheduler and database
queue worker running. The target account must be active and have enough file
and storage quota. This copies new completed reports; existing reports and
deletions are not synchronized retroactively.

The company API v1 sender is configured separately on the PDF Yanzhenjia
settings page. It registers new signed reports at `POST /api/v1/files` using
company AppID/Secret Basic authentication and a JSON body containing stable
`source_file_id`, report metadata, local SHA-256, optional MD5, and byte size.
It never uploads the PDF original. A successful registration has
`hash_verified: false`; the original remains on this server for local download
and later visitor-side hash comparison. Frozen request metadata is reused on
retries. Existing legacy sync rows continue using the HMAC upload endpoint and
are not replayed through v1.

Deploy the three runtime parts independently:

```text
Browser
  |
  v
Static frontend host / Nginx
  |
  v
Laravel API backend
  |
  +--> MySQL or MariaDB
  +--> queue worker
  +--> scheduler
  +--> private storage
  +--> Java PDF service
```

### 1. Prepare the Server

Required runtime:

- PHP 8.3+
- Composer
- Node.js matching the frontend build toolchain
- MySQL 8+ or MariaDB
- Java 17 if PDF service is enabled
- Nginx or another reverse proxy

Recommended process supervision:

- PHP-FPM for Laravel web requests
- Supervisor or systemd for Laravel queue workers
- cron for Laravel scheduler
- systemd or container runtime for Java PDF service

### 2. Deploy the Backend API

Clone or pull the repository, then install production dependencies:

```bash
cd backend
composer install --no-dev --prefer-dist --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `backend/.env` for production:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=new_lims
DB_USERNAME=new_lims
DB_PASSWORD=change-me

FRONTEND_URL=https://lims.example.com
SANCTUM_STATEFUL_DOMAINS=lims.example.com
CORS_ALLOWED_ORIGINS=https://lims.example.com

QUEUE_CONNECTION=database
BACKUP_QUEUE_CONNECTION=backups
BACKUP_QUEUE=backups
BACKUP_QUEUE_RETRY_AFTER=1920
BACKUP_JOB_TIMEOUT=1800
FILESYSTEM_DISK=local
```

Run migrations and optimize Laravel:

```bash
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Start the queue worker:

```bash
php artisan queue:work --tries=3 --timeout=120
php artisan queue:work backups --queue=backups --tries=1 --timeout=1800
```

The PDF signing workflow must keep an asynchronous queue connection and a running
worker. If backups need to run synchronously on a small server, only override the
dedicated backup queue:

```dotenv
BACKUP_QUEUE_CONNECTION=sync
```

Configure the scheduler with cron:

```cron
* * * * * cd /path/to/new-lims/backend && php artisan schedule:run >> /dev/null 2>&1
```

Manual backup command:

```bash
php artisan lims:backup --type=daily
```

### 3. Deploy the Frontend SPA

Build the frontend with the production API URL:

```bash
cd frontend
npm ci
VITE_API_BASE_URL=https://api.example.com npm run build
```

Publish `frontend/dist/` to the static web root, for example:

```text
/var/www/new-lims/frontend/dist
```

Nginx must route all SPA paths to `index.html`:

```nginx
server {
    listen 80;
    server_name lims.example.com;
    root /var/www/new-lims/frontend/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

### 4. Deploy the Java PDF Service

Build and run the service:

```bash
cd services/pdf-renderer-java
mvn -q -e -B -DskipTests package
java -jar target/pdf-signer-*.jar
```

If signing keys are needed, provide them outside git and mount them read-only:

```bash
DEFAULT_PFX_PATH=/keys/signer.pfx \
DEFAULT_PFX_PASS=change-me \
java -jar target/pdf-signer-*.jar
```

Then enable the service in `backend/.env`:

```dotenv
PDF_SERVICE_ENABLED=true
PDF_SERVICE_BASE_URL=http://127.0.0.1:8080
PDF_SERVICE_TIMEOUT=120
```

### 5. Reverse Proxy Notes

The frontend and backend can be served from different domains:

```text
https://lims.example.com       React SPA
https://api.example.com        Laravel API
http://127.0.0.1:8080          Java PDF service, internal only
```

Keep these aligned:

- `FRONTEND_URL`
- `SANCTUM_STATEFUL_DOMAINS`
- `CORS_ALLOWED_ORIGINS`
- frontend `VITE_API_BASE_URL`

Do not expose the Java PDF service publicly unless there is a separate authentication layer in front of it.

## Verification

Run these before releasing:

```bash
cd backend
php artisan test

cd ../frontend
npm run lint
npm run test
npm run build

cd ../services/pdf-renderer-java
mvn -q -e -B -DskipTests package
```

Current expected status:

```text
backend: 57 tests, 326 assertions
frontend: 9 test files, 14 tests
java pdf: package succeeds
```

## First Production Checklist

- Configure MySQL/MariaDB database and database user.
- Set `APP_KEY`, `APP_ENV=production`, and `APP_DEBUG=false`.
- Set frontend/backend domains in Sanctum and CORS env values.
- Run `php artisan migrate --force`.
- Seed only the data that should exist in the target environment.
- Start queue worker and scheduler.
- Configure backup storage and test `php artisan lims:backup --type=daily`.
- Keep uploaded files, backup artifacts, and signing keys outside the public web root.
- Build and publish `frontend/dist`.
- Confirm Chrome/mobile acceptance for login, route permissions, customers, equipment, audit logs, and backups.
