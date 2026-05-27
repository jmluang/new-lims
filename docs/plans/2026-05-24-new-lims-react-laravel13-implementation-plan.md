# New LIMS React + Laravel 13 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a new LIMS platform with a React SPA frontend and a Laravel 13 API backend, using `zs-lims/` only as the business reference directory.

**Architecture:** The new project is split into `frontend/`, `backend/`, and optional backend services so the React SPA, Laravel API, and document rendering/signing workloads can evolve independently. Laravel owns authentication, authorization, audit logging, data persistence, backups, exports, and all field-level permission enforcement. React owns the admin user experience, responsive layouts, client-side routing, data fetching, forms, and print-oriented UI. Java owns complex PDF rendering, extraction, stamping, and signing.

**Tech Stack:** Laravel 13, PHP 8.3+, Sanctum, MySQL/MariaDB, React, TypeScript, Vite, TanStack Router, TanStack Query, Tailwind CSS, shadcn/ui, React Hook Form, Zod, Java Spring Boot PDF service, Apache PDFBox.

---

## Context

The existing `zs-lims/` project is a reference implementation, not the base for the new system. It contains useful business behavior around customers, equipment, audit logging, export, PDF/signature flows, and label printing, but the new system should not inherit the current Filament/Vue application structure.

The first release focuses on the web admin. Mini program support is outside this plan.

The database target is MySQL/MariaDB. Do not design around PostgreSQL-only features.

PDF rendering and signing should reuse the Java service pattern from `zs-lims/services/pdf-signer-java/` instead of making PHP the primary PDF renderer. The legacy service already exposes PDF processing endpoints, renders entrust order and contract PDFs, extracts cover fields, applies stamps, handles digital signing, and returns processed PDF bytes to Laravel through an HTTP client. The new project should copy the service into `services/pdf-renderer-java/`, clean the API contract, and keep Laravel as the orchestrator.

Detailed acceptance criteria and test coverage live in `docs/plans/2026-05-24-new-lims-acceptance-test-plan.md`. Keep implementation order in this file and keep detailed verification scenarios in the acceptance plan.

Equipment fields should be derived from the legacy migrations under `zs-lims/database/migrations/`:

- `2025_06_14_153548_devices.php`
- `2025_06_15_134952_add_additional_fields_to_devices_table.php`
- `2025_08_21_162238_add_next_calibration_date_to_devices_table.php`

The new system should keep the legacy business fields, but normalize the old `placement` text into the new `equipment_locations` tree. During data migration, preserve the original `placement` value in `legacy_placement` until it has been mapped to `location_id`.

## Target Directory Layout

```text
new-lims/
├── backend/
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── routes/
│   ├── storage/
│   └── tests/
├── frontend/
│   ├── src/
│   │   ├── app/
│   │   ├── components/
│   │   │   ├── ui/
│   │   │   └── app/
│   │   ├── features/
│   │   │   ├── auth/
│   │   │   ├── system/
│   │   │   ├── customers/
│   │   │   └── equipment/
│   │   ├── lib/
│   │   └── styles/
│   ├── package.json
│   └── vite.config.ts
├── services/
│   └── pdf-renderer-java/
│       ├── pom.xml
│       ├── Dockerfile
│       └── src/
├── docs/
│   ├── init-第一次会议.md
│   └── plans/
└── zs-lims/
```

## Architecture Decisions

1. Use a strict SPA + API split.
   - `frontend/` is a standalone Vite React application.
   - `backend/` is a standalone Laravel API application.
   - React never depends on Blade, Inertia, or Filament.

2. Use Sanctum cookie-based SPA authentication.
   - Development origin: `http://localhost:5173`.
   - Development API: `http://localhost:8000`.
   - Production should prefer the same site or same parent domain so cookies and CSRF stay simple.

3. Use group-based authorization, not role-first authorization.
   - Users belong to departments.
   - Users belong to multiple groups.
   - Groups own permissions directly.
   - Effective user permissions are the union of all active group permissions.
   - A `super_admin` group can exist, but it is still a group.

4. Enforce field-level permissions in the backend.
   - React may hide fields for UX, but Laravel must also filter reads, updates, exports, and audit views.
   - Export APIs must use the same permission engine as JSON APIs.

5. Treat audit logging as infrastructure.
   - Audit logging must be enabled from the beginning.
   - Audit records are append-only at the application layer.
   - Audit records include before/after values and a hash chain for tamper detection.

6. Keep shadcn/ui as source-owned UI primitives.
   - shadcn components live under `frontend/src/components/ui/`.
   - Project-specific components live under `frontend/src/components/app/`.
   - Feature components live under `frontend/src/features/*/components/`.
   - Custom styling is expected through Tailwind tokens, CSS variables, and project wrapper components.

7. Use Java for complex PDF workloads.
   - Reuse the `zs-lims/services/pdf-signer-java/` Spring Boot service as the starting point.
   - Keep Laravel-side integration as a typed HTTP client, similar to `zs-lims/app/Services/ExternalPdfSignerService.php`.
   - Avoid making `barryvdh/laravel-dompdf` the primary renderer for official LIMS documents.
   - Use PHP PDF libraries only for simple inspection, metadata, or fallback utilities.
   - Keep PDF service deployment explicit with Docker, health checks, timeout settings, and key/certificate configuration.

## Package Decisions

Use mature packages for infrastructure-level behavior. Do not rebuild these from scratch unless the package cannot satisfy the acceptance tests.

### Backend Packages

Install these packages in `backend/`:

```bash
composer require spatie/laravel-permission
composer require spatie/laravel-activitylog:^4.12
composer require spatie/laravel-query-builder
composer require spatie/laravel-data
composer require spatie/laravel-medialibrary
composer require spatie/laravel-backup
composer require maatwebsite/excel
composer require larastan/larastan --dev
```

Package responsibilities:

| Package | Responsibility | Project Rule |
|---|---|---|
| `spatie/laravel-permission` | Group/role and permission engine | Product calls roles "groups"; permission names stay explicit |
| `spatie/laravel-activitylog` | Optional activity helper package | Compliance audit source of truth is the custom `audit_logs` table; Spatie is not used for ISO 17025 hash-chain records |
| `spatie/laravel-query-builder` | API filtering, sorting, includes, and allowed fields | All list endpoints must use explicit allowed filters/sorts/fields |
| `spatie/laravel-data` | Request/response DTOs | Use for stable API payload boundaries where useful |
| `spatie/laravel-medialibrary` | Equipment images and file attachments | File URLs still obey field-level permissions |
| `spatie/laravel-backup` | Database and file backup execution | Wrap with `backup_runs`, permissions, and audit logs |
| `maatwebsite/excel` | Excel import/export | Export must pass through field-level export filtering |
| `larastan/larastan` | Static analysis | Run in CI once baseline code is stable |

Do not use `barryvdh/laravel-dompdf` as the main official document renderer. Complex PDF generation, extraction, stamping, and signing belong in `services/pdf-renderer-java/`.

### Frontend Packages

Install these packages in `frontend/`:

```bash
npm install @tanstack/react-router @tanstack/react-query @tanstack/react-table
npm install axios react-hook-form zod @hookform/resolvers
npm install lucide-react date-fns qrcode.react
npm install -D tailwindcss @tailwindcss/vite
```

Package responsibilities:

| Package | Responsibility | Project Rule |
|---|---|---|
| `@tanstack/react-router` | SPA routing | Route guards must use effective permissions |
| `@tanstack/react-query` | API cache and server state | Cache keys must include filters and pagination state |
| `@tanstack/react-table` | Table state and column visibility | Columns must be generated from backend field permission metadata |
| `react-hook-form` + `zod` | Forms and validation | Submit payloads must exclude fields without update permission |
| `lucide-react` | Icons | Use inside shadcn/ui buttons and menus |
| `date-fns` | Date formatting and range helpers | Keep dates consistent across filters and display |
| `qrcode.react` | Browser-side QR rendering | Use for equipment label print preview |
| `shadcn/ui` | Source-owned UI primitives | Keep base primitives in `components/ui`, business wrappers in `components/app` |

## Backend Domain Model

### System

- `users`
  - `id`
  - `name`
  - `email`
  - `phone`
  - `department_id`
  - `status`
  - `password_changed_at`
  - `must_change_password`
  - `locked_at`
  - `lock_reason`
  - `failed_login_attempts`
  - `last_login_at`
  - `created_at`
  - `updated_at`

- `departments`
  - `id`
  - `parent_id`
  - `name`
  - `code`
  - `sort_order`
  - `status`
  - `created_at`
  - `updated_at`

- `groups`
  - `id`
  - `name`
  - `code`
  - `description`
  - `is_system`
  - `status`
  - `created_at`
  - `updated_at`

- `group_user`
  - `group_id`
  - `user_id`

- `permissions`
  - `id`
  - `resource`
  - `field`
  - `action`
  - `effect`
  - `description`
  - `created_at`
  - `updated_at`

- `group_permission`
  - `group_id`
  - `permission_id`

- `audit_logs`
  - `id`
  - `request_id`
  - `actor_user_id`
  - `actor_name_snapshot`
  - `action`
  - `module`
  - `subject_type`
  - `subject_id`
  - `before_values`
  - `after_values`
  - `changed_values`
  - `ip_address`
  - `user_agent`
  - `prev_hash`
  - `hash`
  - `created_at`

- `dictionary_sets`
  - `id`
  - `code`
  - `name`
  - `description`
  - `status`
  - `created_at`
  - `updated_at`

- `dictionary_items`
  - `id`
  - `dictionary_set_id`
  - `label`
  - `value`
  - `color`
  - `sort_order`
  - `is_default`
  - `status`
  - `created_at`
  - `updated_at`

- `backup_runs`
  - `id`
  - `type`
  - `status`
  - `database_path`
  - `files_path`
  - `size_bytes`
  - `started_at`
  - `finished_at`
  - `error_message`
  - `created_by`
  - `created_at`
  - `updated_at`

### Customers

- `customers`
  - `id`
  - `name`
  - `credit_code`
  - `type`
  - `level`
  - `source`
  - `industry`
  - `phone`
  - `email`
  - `address`
  - `remark`
  - `status`
  - `created_at`
  - `updated_at`

- `customer_contacts`
  - `id`
  - `customer_id`
  - `name`
  - `title`
  - `phone`
  - `email`
  - `wechat`
  - `is_default`
  - `remark`
  - `created_at`
  - `updated_at`

### Equipment

- `equipment_locations`
  - `id`
  - `parent_id`
  - `name`
  - `code`
  - `sort_order`
  - `status`
  - `created_at`
  - `updated_at`

- `equipment`
  - `id`
  - `equipment_no`
  - `name`
  - `manufacturer`
  - `model`
  - `serial_no`
  - `location_id`
  - `legacy_placement`
  - `purchase_date`
  - `enable_date`
  - `calibration_date`
  - `calibration_duration`
  - `next_calibration_date`
  - `status`
  - `device_image`
  - `manual_files`
  - `instruction_files`
  - `calibration_files`
  - `other_files`
  - `remark`
  - `created_at`
  - `updated_at`

- `equipment_label_print_jobs`
  - `id`
  - `created_by`
  - `equipment_ids`
  - `label_width_mm`
  - `label_height_mm`
  - `created_at`

## API Shape

```text
POST   /api/login
POST   /api/logout
GET    /api/me
GET    /api/permissions/effective

GET    /api/system/users
POST   /api/system/users
GET    /api/system/users/{user}
PUT    /api/system/users/{user}
POST   /api/system/users/{user}/reset-password
POST   /api/system/users/{user}/lock
POST   /api/system/users/{user}/unlock

GET    /api/system/departments
POST   /api/system/departments
PUT    /api/system/departments/{department}
DELETE /api/system/departments/{department}

GET    /api/system/groups
POST   /api/system/groups
GET    /api/system/groups/{group}
PUT    /api/system/groups/{group}
PUT    /api/system/groups/{group}/permissions

GET    /api/system/permissions/catalog

GET    /api/audit-logs
GET    /api/audit-logs/{auditLog}
GET    /api/audit-logs/export

GET    /api/dictionaries
POST   /api/dictionaries
PUT    /api/dictionaries/{dictionarySet}
POST   /api/dictionaries/{dictionarySet}/items
PUT    /api/dictionaries/{dictionarySet}/items/{dictionaryItem}

GET    /api/backups
POST   /api/backups
POST   /api/backups/{backupRun}/restore

GET    /api/customers
POST   /api/customers
GET    /api/customers/{customer}
PUT    /api/customers/{customer}
DELETE /api/customers/{customer}
GET    /api/customers/export

GET    /api/customers/{customer}/contacts
POST   /api/customers/{customer}/contacts
PUT    /api/customers/{customer}/contacts/{contact}
DELETE /api/customers/{customer}/contacts/{contact}

GET    /api/equipment
POST   /api/equipment
GET    /api/equipment/{equipment}
PUT    /api/equipment/{equipment}
DELETE /api/equipment/{equipment}
GET    /api/equipment/export

GET    /api/equipment-locations
POST   /api/equipment-locations
PUT    /api/equipment-locations/{location}
DELETE /api/equipment-locations/{location}

POST   /api/equipment-labels/preview
POST   /api/equipment-labels/print-jobs
```

## Frontend UX Direction

Desktop pages should optimize for repeated admin work: dense tables, filter panels, batch actions, column visibility, export actions, and direct edit flows.

Mobile pages should not render full desktop tables. Use card/list views with the same field schema and permission metadata.

```text
Desktop
+----------------------------------------------------------+
| Sidebar             | Header: search / user / actions    |
|---------------------+------------------------------------|
| System              | Filters                            |
| Customers           | +--------------------------------+ |
| Equipment           | | Data table                     | |
| Audit Logs          | | columns / status / actions     | |
| Dictionaries        | +--------------------------------+ |
| Backups             | Pagination                         |
+----------------------------------------------------------+

Mobile
+-----------------------------+
| Header        [Menu]        |
| Search / Filter             |
| +-------------------------+ |
| | Name / No. / Status     | |
| | key fields              | |
| | actions menu            | |
| +-------------------------+ |
+-----------------------------+
```

## Implementation Tasks

### Task 1: Scaffold Project Directories

**Files:**
- Create: `backend/`
- Create: `frontend/`
- Create: `docs/plans/`
- Keep: `zs-lims/`

- [ ] **Step 1: Create the Laravel API project**

Run:

```bash
composer create-project laravel/laravel:^13.0 backend
```

Expected:

```text
Application key set successfully.
```

- [ ] **Step 2: Create the React SPA project**

Run:

```bash
npm create vite@latest frontend -- --template react-ts
```

Expected:

```text
Done. Now run:
```

- [ ] **Step 3: Verify the root layout**

Run:

```bash
ls -la
```

Expected:

```text
backend
frontend
docs
zs-lims
```

- [ ] **Step 4: Commit scaffold**

Run:

```bash
git add backend frontend docs
git commit -m "chore: scaffold React SPA and Laravel API"
```

Expected:

```text
[main ...] chore: scaffold React SPA and Laravel API
```

### Task 2: Configure Backend for MySQL/MariaDB and Sanctum

**Files:**
- Modify: `backend/composer.json`
- Modify: `backend/.env.example`
- Modify: `backend/config/cors.php`
- Modify: `backend/routes/api.php`
- Create: `backend/tests/Feature/Auth/MeEndpointTest.php`

- [ ] **Step 1: Install Sanctum**

Run:

```bash
cd backend
composer require laravel/sanctum
php artisan install:api --without-migration-prompt --no-interaction
```

Expected:

```text
Publishing complete.
```

- [ ] **Step 2: Install backend infrastructure packages**

Run:

```bash
composer require spatie/laravel-permission
composer require spatie/laravel-activitylog:^4.12
composer require spatie/laravel-query-builder
composer require spatie/laravel-data
composer require spatie/laravel-medialibrary
composer require spatie/laravel-backup
composer require maatwebsite/excel
composer require larastan/larastan --dev
```

Expected:

```text
Generating optimized autoload files
```

- [ ] **Step 3: Publish package configuration and migrations**

Run:

```bash
php artisan vendor:publish --tag=permission-migrations --tag=permission-config
php artisan vendor:publish --tag=activitylog-migrations --tag=activitylog-config
php artisan vendor:publish --tag=medialibrary-migrations --tag=medialibrary-config
php artisan vendor:publish --tag=backup-config
php artisan vendor:publish --provider="Maatwebsite\\Excel\\ExcelServiceProvider" --tag=config
```

Expected:

```text
Publishing complete.
```

- [ ] **Step 4: Set MySQL/MariaDB defaults in `.env.example`**

Use these values:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=new_lims
DB_USERNAME=root
DB_PASSWORD=

FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173
SESSION_DOMAIN=localhost
```

- [ ] **Step 5: Add a protected `/api/me` route**

Expected route behavior:

```php
Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
    return response()->json([
        'data' => [
            'id' => $request->user()->id,
            'name' => $request->user()->name,
            'email' => $request->user()->email,
        ],
    ]);
});
```

- [ ] **Step 6: Write the auth feature test**

Test behavior:

```php
public function test_me_endpoint_returns_authenticated_user(): void
{
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/me');

    $response->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email);
}
```

- [ ] **Step 7: Run backend tests**

Run:

```bash
php artisan test --filter=MeEndpointTest
```

Expected:

```text
PASS  Tests\Feature\Auth\MeEndpointTest
```

- [ ] **Step 8: Commit backend auth foundation**

Run:

```bash
git add backend
git commit -m "feat: configure Laravel API authentication"
```

Expected:

```text
[main ...] feat: configure Laravel API authentication
```

### Task 3: Configure Frontend Foundation

**Files:**
- Modify: `frontend/package.json`
- Modify: `frontend/src/main.tsx`
- Create: `frontend/src/app/router.tsx`
- Create: `frontend/src/lib/api.ts`
- Create: `frontend/src/lib/query-client.ts`
- Create: `frontend/src/styles/globals.css`

- [ ] **Step 1: Install frontend dependencies**

Run:

```bash
cd frontend
npm install @tanstack/react-router @tanstack/react-query @tanstack/react-table
npm install axios react-hook-form zod @hookform/resolvers
npm install lucide-react date-fns qrcode.react
npm install -D tailwindcss @tailwindcss/vite
```

Expected:

```text
added
```

- [ ] **Step 2: Initialize shadcn/ui**

Run:

```bash
npx shadcn@latest init
```

Expected selections:

```text
Style: New York
Base color: Zinc
CSS variables: yes
```

- [ ] **Step 3: Add required shadcn/ui primitives**

Run:

```bash
npx shadcn@latest add button input label select checkbox dropdown-menu dialog sheet table badge textarea form toast
```

Expected:

```text
Created
```

- [ ] **Step 4: Create API client**

Expected behavior:

```ts
import axios from "axios";

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? "http://localhost:8000",
  withCredentials: true,
  headers: {
    Accept: "application/json",
  },
});
```

- [ ] **Step 5: Run frontend typecheck and build**

Run:

```bash
npm run build
```

Expected:

```text
built in
```

- [ ] **Step 6: Commit frontend foundation**

Run:

```bash
git add frontend
git commit -m "feat: configure React admin foundation"
```

Expected:

```text
[main ...] feat: configure React admin foundation
```

### Task 4: Implement Permission Catalog and Effective Permissions

**Files:**
- Created: `backend/app/Enums/PermissionAction.php`
- Created: `backend/app/Enums/PermissionEffect.php`
- Created: `backend/app/Services/Authorization/PermissionCatalog.php`
- Created: `backend/app/Services/Authorization/EffectivePermissionService.php`
- Created: `backend/app/Services/Authorization/EffectivePermissions.php`
- Created: `backend/app/Http/Controllers/System/EffectivePermissionController.php`
- Created: `backend/app/Http/Controllers/System/PermissionCatalogController.php`
- Created: `backend/tests/Feature/System/EffectivePermissionTest.php`
- Created: `backend/tests/Feature/System/PermissionCatalogTest.php`
- Updated: `backend/app/Models/User.php`
- Updated: `backend/routes/api.php`

Implementation note: groups are implemented with Spatie roles instead of custom group tables. The product UI can still call them groups; the database source of truth is Spatie's `roles`, `permissions`, `model_has_roles`, and `role_has_permissions` tables.

- [x] **Step 1: Define actions**

Required actions:

```text
create
read
update
delete
export
hide
print
```

- [x] **Step 2: Define permission resources**

Required first-release resources:

```text
system.users
system.departments
system.groups
system.audit_logs
system.dictionaries
system.backups
customers
customer_contacts
equipment
equipment_locations
equipment_labels
```

- [x] **Step 3: Define field permission examples**

Required first-release field permissions:

```text
system.users.phone: read, update
system.users.email: read, update
customers.credit_code: read, update, export
customers.phone: read, update, export
customers.email: read, update, export
customer_contacts.phone: read, update, export
customer_contacts.email: read, update, export
equipment.serial_no: read, update, export
equipment.legacy_placement: read, update, export
equipment.device_image: read
equipment.manual_files: read
equipment.instruction_files: read
equipment.calibration_files: read
equipment.other_files: read
```

- [x] **Step 4: Test multi-group permission merge**

Test behavior:

```php
public function test_user_effective_permissions_are_merged_from_all_groups(): void
{
    $user = User::factory()->create();
    $viewer = Role::create(['name' => 'viewer', 'guard_name' => 'web']);
    $exporter = Role::create(['name' => 'exporter', 'guard_name' => 'web']);

    $viewer->givePermissionTo(Permission::findOrCreate('customers.read', 'web'));
    $exporter->givePermissionTo(Permission::findOrCreate('customers.export', 'web'));
    $user->assignRole($viewer, $exporter);

    $permissions = app(EffectivePermissionService::class)->forUser($user);

    $this->assertTrue($permissions->allows('customers', null, 'read'));
    $this->assertTrue($permissions->allows('customers', null, 'export'));
}
```

- [x] **Step 5: Expose effective permissions API**

Expected response shape:

```json
{
  "data": {
    "resources": {
      "customers": {
        "actions": {
          "read": true,
          "create": false,
          "update": false,
          "delete": false,
          "export": true
        },
        "fields": {
          "phone": {
            "read": true,
            "export": false,
            "update": false
          }
        }
      }
    }
  }
}
```

- [x] **Step 6: Commit permission foundation**

Run:

```bash
git add backend
git commit -m "feat: add group-based permission foundation"
```

Expected:

```text
[main ...] feat: add group-based permission foundation
```

### Task 5: Implement Audit Logging Infrastructure

**Files:**
- Created: `backend/database/migrations/2026_05_27_000000_create_audit_logs_table.php`
- Created: `backend/app/Models/AuditLog.php`
- Created: `backend/app/Services/Audit/AuditLogger.php`
- Created: `backend/app/Services/Audit/AuditHashService.php`
- Created: `backend/app/Http/Middleware/AttachRequestId.php`
- Created: `backend/tests/Feature/Audit/AuditLoggerTest.php`
- Created: `backend/tests/Unit/Audit/AuditHashServiceTest.php`
- Updated: `backend/bootstrap/app.php`

Implementation note: ISO 17025 audit records use the custom `audit_logs` table as the only compliance audit source of truth. Spatie Activitylog remains installed but is not the hash-chain audit store.

- [x] **Step 1: Create append-only audit table**

Required columns:

```text
id
request_id
actor_user_id
actor_name_snapshot
action
module
subject_type
subject_id
before_values
after_values
changed_values
ip_address
user_agent
prev_hash
hash
created_at
```

- [x] **Step 2: Implement hash chain calculation**

Expected hash input:

```text
prev_hash + request_id + actor_user_id + action + module + subject_type + subject_id + before_values + after_values + changed_values + created_at
```

Hash rules:

```text
JSON payloads are canonicalized by recursive key sorting.
Timestamps are normalized to Y-m-d H:i:s.
The logger calculates and inserts records inside a database transaction.
The previous audit row is selected with lockForUpdate before prev_hash is calculated.
```

- [x] **Step 3: Test before/after logging**

Test behavior:

```php
public function test_audit_logger_records_before_after_and_changed_values(): void
{
    $user = User::factory()->create(['name' => 'Operator']);
    $customer = Customer::factory()->create(['name' => 'Old Name']);

    $log = app(AuditLogger::class)->record(
        actor: $user,
        action: 'customers.update',
        module: 'customers',
        subject: $customer,
        before: ['name' => 'Old Name'],
        after: ['name' => 'New Name'],
        requestMeta: ['ip_address' => '127.0.0.1', 'user_agent' => 'Test']
    );

    $this->assertSame(['name' => ['old' => 'Old Name', 'new' => 'New Name']], $log->changed_values);
    $this->assertNotEmpty($log->hash);
}
```

- [x] **Step 4: Block update and delete from application code**

Expected model behavior:

```php
protected static function booted(): void
{
    static::updating(fn () => false);
    static::deleting(fn () => false);
}
```

Boundary:

```text
Eloquent update/delete is blocked.
Direct database tampering is detected by AuditHashService::verifyChain().
Deleting the final row requires an external anchor/count mechanism and is out of scope for Task 5.
```

- [x] **Step 5: Commit audit foundation**

Run:

```bash
git add backend
git commit -m "feat: add append-only audit logging"
```

Expected:

```text
[main ...] feat: add append-only audit logging
```

### Task 6: Implement System Management APIs

**Files:**
- Created: `backend/database/migrations/2026_05_27_005000_create_departments_table.php`
- Created: `backend/database/migrations/2026_05_27_010000_add_system_management_fields_to_users_table.php`
- Created: `backend/database/migrations/2026_05_27_010100_add_group_metadata_to_roles_table.php`
- Created: `backend/database/migrations/2026_05_27_010200_create_dictionary_tables.php`
- Created: `backend/database/migrations/2026_05_27_010300_create_backup_runs_table.php`
- Created: `backend/app/Http/Controllers/System/UserController.php`
- Created: `backend/app/Http/Controllers/System/DepartmentController.php`
- Created: `backend/app/Http/Controllers/System/GroupController.php`
- Created: `backend/app/Http/Controllers/System/DictionaryController.php`
- Created: `backend/app/Http/Controllers/System/BackupController.php`
- Created: `backend/app/Actions/System/LockUserAction.php`
- Created: `backend/app/Actions/System/ResetUserPasswordAction.php`
- Created: `backend/app/Console/Commands/RunSystemBackup.php`
- Created: `backend/tests/Feature/System/UserManagementTest.php`
- Created: `backend/tests/Feature/System/DepartmentManagementTest.php`
- Created: `backend/tests/Feature/System/GroupManagementTest.php`
- Created: `backend/tests/Feature/System/DictionaryManagementTest.php`
- Created: `backend/tests/Feature/System/BackupCommandTest.php`

Implementation note: this slice implements the system management API foundation, role-as-group management, audit-backed mutations, and backup run metadata. Full login-runtime enforcement for failed attempts/first password change and real database/file archive execution remain separate follow-up slices.

- [x] **Step 1: Implement user management**

Required behavior:

```text
create user
update name, phone, department, status
assign multiple groups
force first-login password change
reset password
lock user
unlock user
record audit log for every mutation
```

- [x] **Step 2: Implement department tree management**

Required behavior:

```text
create root department
create child department
update department
disable department
return departments as tree
```

- [x] **Step 3: Implement group permission management**

Required behavior:

```text
create group
update group
assign permissions to group
return group permission matrix
record audit log for permission changes
```

- [x] **Step 4: Implement data dictionary management**

Required dictionary sets:

```text
customer.type
customer.level
customer.source
customer.industry
customer.status
equipment.status
equipment.calibration_duration
```

- [ ] **Step 5: Implement backup command**

Required command:

```bash
php artisan lims:backup --type=daily
```

Required behavior:

```text
dump MySQL/MariaDB database
archive configured storage files
write backup metadata to backup_runs
record success or failure
record audit log for manual backup trigger
```

Current status: `lims:backup` writes `backup_runs` metadata and audit logs. Actual MySQL/MariaDB dump and file archive execution still need to be wired to `spatie/laravel-backup` or an equivalent backup runner before SYS-12 is complete.

- [x] **Step 6: Commit system APIs**

Run:

```bash
git add backend
git commit -m "feat: add system management APIs"
```

Expected:

```text
[main ...] feat: add system management APIs
```

### Task 7: Implement Customer APIs

**Files:**
- Created: `backend/database/migrations/2026_05_27_020000_create_customers_table.php`
- Created: `backend/database/migrations/2026_05_27_020100_create_customer_contacts_table.php`
- Created: `backend/app/Models/Customer.php`
- Created: `backend/app/Models/CustomerContact.php`
- Created: `backend/app/Http/Controllers/CustomerController.php`
- Created: `backend/app/Http/Controllers/CustomerContactController.php`
- Created: `backend/app/Services/Authorization/FieldPermissionFilter.php`
- Created: `backend/tests/Feature/Customers/CustomerApiTest.php`
- Created: `backend/tests/Feature/Customers/CustomerFieldPermissionTest.php`

- [x] **Step 1: Implement customer CRUD**

Required fields:

```text
name
credit_code
type
level
source
industry
phone
email
address
remark
status
```

- [x] **Step 2: Implement contacts**

Required behavior:

```text
customer has many contacts
only one default contact per customer
default contact can be changed
contact phone and email are field-permission controlled
```

- [x] **Step 3: Enforce field-level read permissions**

Expected masked response when phone is hidden:

```json
{
  "data": {
    "id": 1,
    "name": "Example Customer",
    "phone": null,
    "_field_permissions": {
      "phone": {
        "read": false,
        "update": false,
        "export": false,
        "hidden": true
      }
    }
  }
}
```

- [x] **Step 4: Enforce field-level export permissions**

Required behavior:

```text
export excludes fields without export permission
export records audit log
export uses the same FieldPermissionFilter as JSON APIs
```

- [x] **Step 5: Commit customer APIs**

Run:

```bash
git add backend
git commit -m "feat: add customer management APIs"
```

Expected:

```text
[main ...] feat: add customer management APIs
```

### Task 8: Implement Equipment APIs and Label Printing

**Files:**
- Created: `backend/database/migrations/2026_05_27_030000_create_equipment_locations_table.php`
- Created: `backend/database/migrations/2026_05_27_030100_create_equipment_table.php`
- Created: `backend/database/migrations/2026_05_27_030200_create_equipment_label_print_jobs_table.php`
- Created: `backend/app/Models/Equipment.php`
- Created: `backend/app/Models/EquipmentLocation.php`
- Created: `backend/app/Models/EquipmentLabelPrintJob.php`
- Created: `backend/app/Http/Controllers/EquipmentController.php`
- Created: `backend/app/Http/Controllers/EquipmentLocationController.php`
- Created: `backend/app/Http/Controllers/EquipmentLabelController.php`
- Created: `backend/tests/Feature/Equipment/EquipmentApiTest.php`
- Created: `backend/tests/Feature/Equipment/EquipmentFieldPermissionTest.php`
- Created: `backend/tests/Feature/Equipment/EquipmentLabelTest.php`

- [x] **Step 1: Implement equipment location tree**

Required behavior:

```text
create root location
create child location
update location
disable location
return locations as tree
prevent deleting a location that has equipment
```

- [x] **Step 2: Implement equipment CRUD**

Required fields:

```text
equipment_no
name
manufacturer
model
serial_no
location_id
legacy_placement
purchase_date
enable_date
calibration_date
calibration_duration
next_calibration_date
status
device_image
manual_files
instruction_files
calibration_files
other_files
remark
```

- [x] **Step 3: Implement label print preview API**

Required input:

```json
{
  "equipment_ids": [1, 2, 3],
  "label_width_mm": 40,
  "label_height_mm": 60
}
```

Required output:

```json
{
  "data": [
    {
      "equipment_no": "EQ-001",
      "name": "Example Equipment",
      "qr_text": "EQ-001"
    }
  ]
}
```

- [x] **Step 4: Commit equipment APIs**

Run:

```bash
git add backend
git commit -m "feat: add equipment management APIs"
```

Expected:

```text
[main ...] feat: add equipment management APIs
```

### Task 9: Implement Frontend App Shell and Auth

**Files:**
- Created: `backend/app/Http/Controllers/Auth/LoginController.php`
- Created: `backend/tests/Feature/Auth/LoginEndpointTest.php`
- Created: `frontend/src/app/App.tsx`
- Created: `frontend/src/app/routes.tsx`
- Created: `frontend/src/features/auth/LoginPage.tsx`
- Created: `frontend/src/features/auth/useCurrentUser.ts`
- Created: `frontend/src/features/dashboard/DashboardPage.tsx`
- Created: `frontend/src/components/app/Sidebar.tsx`
- Created: `frontend/src/components/app/MobileNav.tsx`
- Created: `frontend/src/components/app/PermissionGate.tsx`
- Created: `frontend/src/components/app/PlaceholderPage.tsx`
- Updated: `frontend/src/components/app/AppLayout.tsx`
- Updated: `frontend/src/lib/api.ts`

- [x] **Step 1: Build responsive shell**

Required layout:

```text
desktop: fixed sidebar + top header + content area
mobile: top header + sheet menu + content cards
```

- [x] **Step 2: Implement login page**

Required behavior:

```text
email and password form
CSRF cookie request before login
redirect to dashboard after login
show validation errors from API
```

- [x] **Step 3: Implement permission gate**

Expected usage:

```tsx
<PermissionGate resource="customers" action="export">
  <Button>Export</Button>
</PermissionGate>
```

- [x] **Step 4: Commit frontend shell**

Run:

```bash
git add frontend
git commit -m "feat: add React admin shell and auth"
```

Expected:

```text
[main ...] feat: add React admin shell and auth
```

### Task 10: Implement Frontend System Pages

**Files:**
- Create: `frontend/src/features/system/users/UserListPage.tsx`
- Create: `frontend/src/features/system/users/UserForm.tsx`
- Create: `frontend/src/features/system/groups/GroupListPage.tsx`
- Create: `frontend/src/features/system/groups/PermissionMatrix.tsx`
- Create: `frontend/src/features/system/audit/AuditLogListPage.tsx`
- Create: `frontend/src/features/system/dictionaries/DictionaryListPage.tsx`
- Create: `frontend/src/features/system/backups/BackupListPage.tsx`

- [x] **Step 1: Implement user management pages**

Required behavior:

```text
list users
filter by status, department, group
create user
edit user
lock and unlock user
reset password
assign groups
```

- [x] **Step 2: Implement permission matrix**

Required behavior:

```text
group by resource
show action-level permissions
show field-level permissions
save group permission changes in one request
```

- [x] **Step 3: Implement audit log page**

Required behavior:

```text
filter by actor, module, action, subject, time range
view before and after values
export filtered logs
no delete action in UI
```

- [x] **Step 4: Commit system pages**

Run:

```bash
git add frontend
git commit -m "feat: add system management pages"
```

Expected:

```text
[main ...] feat: add system management pages
```

### Task 11: Implement Frontend Customer Pages

**Files:**
- Create: `frontend/src/features/customers/CustomerListPage.tsx`
- Create: `frontend/src/features/customers/CustomerForm.tsx`
- Create: `frontend/src/features/customers/CustomerContactList.tsx`
- Create: `frontend/src/features/customers/customerColumns.tsx`
- Create: `frontend/src/features/customers/customerSchema.ts`

- [x] **Step 1: Implement desktop customer table**

Required behavior:

```text
filter by type, level, source, industry, status
search by name, credit code, phone
hide fields according to backend field permissions
show export action only when allowed
```

- [x] **Step 2: Implement mobile customer cards**

Required behavior:

```text
same filter state as desktop
same permission rules as desktop
compact card with name, level, status, default contact
actions in dropdown menu
```

- [x] **Step 3: Implement customer form**

Required behavior:

```text
dictionary-driven select options
field-level update permission disables forbidden fields
contacts can be added, edited, and marked default
```

- [x] **Step 4: Commit customer pages**

Run:

```bash
git add frontend
git commit -m "feat: add customer management pages"
```

Expected:

```text
[main ...] feat: add customer management pages
```

### Task 12: Implement Frontend Equipment Pages and Label Print UI

**Files:**
- Create: `frontend/src/features/equipment/EquipmentListPage.tsx`
- Create: `frontend/src/features/equipment/EquipmentForm.tsx`
- Create: `frontend/src/features/equipment/EquipmentLocationTreePage.tsx`
- Create: `frontend/src/features/equipment/EquipmentLabelPrintPage.tsx`
- Create: `frontend/src/features/equipment/equipmentColumns.tsx`
- Create: `frontend/src/features/equipment/equipmentSchema.ts`

- [x] **Step 1: Implement equipment table and cards**

Required behavior:

```text
filter by status, location, manufacturer, calibration due date
search by name, equipment number, model
desktop table
mobile card list
batch select for label printing
```

- [x] **Step 2: Implement equipment location tree**

Required behavior:

```text
show nested locations
create child location
rename location
disable location
prevent destructive action when backend rejects it
```

- [x] **Step 3: Implement label print page**

Required print layout:

```css
@page {
  size: 40mm 60mm;
  margin: 0;
}

.equipment-label {
  width: 40mm;
  height: 60mm;
  page-break-after: always;
}

.equipment-label:last-child {
  page-break-after: avoid;
}
```

Required content:

```text
equipment number
equipment name
QR code
footer text: XPD_LIMS
```

- [x] **Step 4: Commit equipment pages**

Run:

```bash
git add frontend
git commit -m "feat: add equipment management pages"
```

Expected:

```text
[main ...] feat: add equipment management pages
```

### Task 13: Prepare Java PDF Service Integration

**Files:**
- Create: `services/pdf-renderer-java/`
- Create: `backend/config/pdf_service.php`
- Create: `backend/app/Services/Pdf/PdfRendererClient.php`
- Create: `backend/app/Http/Controllers/System/PdfServiceHealthController.php`
- Create: `backend/tests/Feature/System/PdfServiceHealthTest.php`

- [x] **Step 1: Copy the legacy Java PDF service as a starting point**

Run:

```bash
mkdir -p services
cp -R zs-lims/services/pdf-signer-java services/pdf-renderer-java
```

Expected:

```text
services/pdf-renderer-java/pom.xml
services/pdf-renderer-java/src/main/java/com/luang/pdfsigner/web/PdfController.java
```

- [x] **Step 2: Preserve the existing endpoint contracts**

Required legacy-compatible endpoints:

```text
POST /api/pdf/process
POST /api/pdf/extract-cover
POST /api/pdf/entrust-order
POST /api/pdf/contract
```

Required behavior:

```text
process accepts multipart PDF, stamp images, signature images, function stamps, and signing metadata
process returns success, pdf_base64, and optional cover_fields
extract-cover returns report_number, product_name, model_specification, entrust_company, test_items, report_date, extraction_status
entrust-order and contract return application/pdf bytes
```

- [x] **Step 3: Add Laravel PDF service configuration**

Required config values:

```php
return [
    'base_url' => env('PDF_SERVICE_BASE_URL', 'http://localhost:8080'),
    'timeout' => (float) env('PDF_SERVICE_TIMEOUT', 120),
    'enabled' => (bool) env('PDF_SERVICE_ENABLED', false),
];
```

- [x] **Step 4: Implement Laravel client wrapper**

Required methods:

```php
public function health(): bool;
public function processPdf(string $pdfPath, array $options = []): array;
public function extractCover(string $pdfPath): array;
public function renderEntrustOrder(array $payload): string;
public function renderContract(array $payload): string;
```

Required client behavior:

```text
use Guzzle with configured base_url and timeout
throw RuntimeException when the Java service is disabled
throw RuntimeException on non-2xx responses
decode pdf_base64 into a temporary PDF path
return cover_fields when present
```

- [x] **Step 5: Verify Java service build**

Run:

```bash
cd services/pdf-renderer-java
mvn -q -e -B -DskipTests package
```

Expected:

```text
target/pdf-signer-*.jar
```

- [x] **Step 6: Commit Java PDF service foundation**

Run:

```bash
git add services backend
git commit -m "feat: add Java PDF service integration"
```

Expected:

```text
[main ...] feat: add Java PDF service integration
```

### Task 14: End-to-End Verification

**Files:**
- Create: `backend/tests/Feature/Smoke/AdminWorkflowTest.php`
- Create: `frontend/src/features/system/__tests__/permission-gate.test.tsx`
- Create: `docs/plans/2026-05-24-new-lims-acceptance-test-plan.md`
- Create: `docs/plans/2026-05-24-new-lims-react-laravel13-implementation-plan.md`

- [ ] **Step 1: Verify backend**

Run:

```bash
cd backend
php artisan test
```

Expected:

```text
Tests: ... passed
```

- [ ] **Step 2: Verify frontend build**

Run:

```bash
cd frontend
npm run build
```

Expected:

```text
built in
```

- [ ] **Step 3: Verify acceptance workflow**

Use the detailed release gate and test matrix from:

```text
docs/plans/2026-05-24-new-lims-acceptance-test-plan.md
```

- [ ] **Step 4: Commit verification additions**

Run:

```bash
git add backend frontend docs
git commit -m "test: add initial LIMS workflow verification"
```

Expected:

```text
[main ...] test: add initial LIMS workflow verification
```

## Initial Delivery Order

1. Scaffold `backend/` and `frontend/`.
2. Configure backend auth, MySQL/MariaDB, CORS, and Sanctum.
3. Configure frontend routing, query client, shadcn/ui, and app shell.
4. Build group-based permission foundation.
5. Build append-only audit logging.
6. Build system management APIs and pages.
7. Build customer APIs and pages.
8. Build equipment APIs, pages, location tree, and label printing.
9. Prepare Java PDF service integration from `zs-lims/services/pdf-signer-java/`.
10. Run end-to-end verification.

## Non-Goals for the First Release

- Mini program support.
- Full report signing workflow migration.
- Full historical data migration.
- Advanced workflow engine.
- PostgreSQL-specific schema or query design.

## Verification Checklist

- [ ] Backend full test suite passes with MySQL/MariaDB configuration.
- [ ] Frontend production build passes.
- [ ] Permission, field-level visibility, export filtering, audit, customer, equipment, backup, label printing, and Java PDF service gates pass according to `docs/plans/2026-05-24-new-lims-acceptance-test-plan.md`.
