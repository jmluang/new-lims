# New LIMS Acceptance and Test Plan

**Goal:** Define detailed acceptance criteria and test coverage for the first React + Laravel 13 LIMS release, with special focus on group-based and field-level permissions.

**Scope:** This document expands the verification section from `docs/plans/2026-05-24-new-lims-react-laravel13-implementation-plan.md`. Keep implementation steps in the main plan and keep acceptance/test details here.

---

## Test Strategy

The first release must be verified at four layers:

1. Backend feature tests prove Laravel enforces permissions, audit rules, data filtering, exports, and mutations.
2. Frontend component and flow tests prove React hides fields/actions and renders the correct responsive views.
3. Integration tests prove React and Laravel agree on the permission metadata contract.
4. Manual acceptance tests prove the workflows are usable with real admin behavior.

Permission tests must always check both:

- **Visible behavior:** the user does not see forbidden menus, table columns, fields, buttons, and export options.
- **Data behavior:** the API does not return forbidden field values and rejects forbidden mutations even if the request is crafted manually.

## Canonical Test Users and Groups

Seed these users and groups in test fixtures. Use these names consistently across backend tests, frontend tests, and manual acceptance.

| User | Group Membership | Purpose |
|---|---|---|
| `super_admin@example.test` | `super_admin` | Full access to all resources, fields, exports, and system settings |
| `system_admin@example.test` | `system_admin` | Can manage users, groups, dictionaries, backups, and audit logs; cannot bypass field restrictions unless granted |
| `customer_viewer@example.test` | `customer_viewer` | Can view customer list but cannot see sensitive customer fields |
| `customer_editor@example.test` | `customer_editor` | Can create and update customer basics, but cannot update restricted fields |
| `equipment_manager@example.test` | `equipment_manager` | Can manage equipment and equipment locations |
| `auditor@example.test` | `auditor` | Can view and export audit logs; cannot modify business data |
| `locked_user@example.test` | `customer_viewer` | Account is locked and cannot log in |

## Permission Naming Contract

Use `spatie/laravel-permission` as the package-level engine. Product language may call roles "groups", but permission names must stay stable and explicit.

Resource permissions:

```text
system.users.read
system.users.create
system.users.update
system.users.delete
system.users.export

system.groups.read
system.groups.create
system.groups.update
system.groups.delete

system.audit_logs.read
system.audit_logs.export

customers.read
customers.create
customers.update
customers.delete
customers.export

customer_contacts.read
customer_contacts.create
customer_contacts.update
customer_contacts.delete
customer_contacts.export

equipment.read
equipment.create
equipment.update
equipment.delete
equipment.export

equipment_locations.read
equipment_locations.create
equipment_locations.update
equipment_locations.delete

equipment_labels.read
equipment_labels.print
```

Field permissions:

```text
system.users.field.phone.read
system.users.field.phone.update
system.users.field.email.read
system.users.field.email.update

customers.field.credit_code.read
customers.field.credit_code.update
customers.field.credit_code.export
customers.field.phone.read
customers.field.phone.update
customers.field.phone.export
customers.field.email.read
customers.field.email.update
customers.field.email.export

customer_contacts.field.phone.read
customer_contacts.field.phone.update
customer_contacts.field.phone.export
customer_contacts.field.email.read
customer_contacts.field.email.update
customer_contacts.field.email.export

equipment.field.serial_no.read
equipment.field.serial_no.update
equipment.field.serial_no.export
equipment.field.legacy_placement.read
equipment.field.legacy_placement.update
equipment.field.legacy_placement.export
equipment.field.device_image.read
equipment.field.manual_files.read
equipment.field.instruction_files.read
equipment.field.calibration_files.read
equipment.field.other_files.read
```

## Permission Matrix Acceptance

### PM-001: Multi-Group Permission Merge

Backend test file: `backend/tests/Feature/System/EffectivePermissionTest.php`

Acceptance:

- A user in `customer_viewer` and `equipment_manager` receives the union of both groups.
- Removing a group immediately removes its permissions after permission cache reset.
- Duplicate permissions from multiple groups appear once in the effective permission payload.

Expected API:

```http
GET /api/permissions/effective
```

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
          "export": false
        },
        "fields": {
          "phone": {
            "read": false,
            "update": false,
            "export": false
          }
        }
      }
    }
  }
}
```

### PM-002: Super Admin Bypass

Backend test file: `backend/tests/Feature/System/SuperAdminPermissionTest.php`

Acceptance:

- `super_admin@example.test` can access every first-release API route.
- `super_admin@example.test` can read, update, and export every protected field.
- The bypass is implemented through a single policy/gate hook, not repeated controller conditions.

### PM-003: Permission Catalog Completeness

Backend test file: `backend/tests/Feature/System/PermissionCatalogTest.php`

Acceptance:

- The permission catalog contains every resource permission and field permission listed in this document.
- The permission matrix API returns every catalog entry so admins can assign it from the UI.
- Removing or renaming a permission fails the catalog snapshot test.

## Backend Permission Tests

### BP-001: Resource Read Permission

Test as `customer_viewer@example.test`.

Acceptance:

- `GET /api/customers` returns `200`.
- `GET /api/equipment` returns `403` unless the user also belongs to `equipment_manager`.
- `GET /api/system/users` returns `403`.

### BP-002: Resource Mutation Permission

Test as `customer_viewer@example.test`.

Acceptance:

- `POST /api/customers` returns `403`.
- `PUT /api/customers/{customer}` returns `403`.
- `DELETE /api/customers/{customer}` returns `403`.
- No customer row changes.
- Each denied request writes a security audit event with action `authorization.denied`.

### BP-003: Field Read Filtering

Test as `customer_viewer@example.test` without customer sensitive field permissions.

Seed customer:

```json
{
  "name": "Sensitive Customer",
  "credit_code": "91330000123456789X",
  "phone": "13800000000",
  "email": "secret@example.test"
}
```

Acceptance:

- `GET /api/customers` returns the customer `name`.
- `credit_code`, `phone`, and `email` are `null` or absent according to the response contract.
- The raw sensitive values do not appear anywhere in the JSON response body.
- `meta.fields.credit_code.read` is `false`.
- `meta.fields.phone.read` is `false`.
- `meta.fields.email.read` is `false`.

### BP-004: Field Update Rejection

Test as `customer_editor@example.test` without `customers.field.credit_code.update`.

Request:

```json
{
  "name": "Updated Customer",
  "credit_code": "91330000999999999X"
}
```

Acceptance:

- `PUT /api/customers/{customer}` returns `422` or `403`.
- `name` is updated only if the API supports partial allowed updates.
- `credit_code` is unchanged in the database.
- The response includes a machine-readable error for `credit_code`.
- Audit log records the allowed change and the denied field update attempt separately, or records a single rejected mutation event with the denied field list.

### BP-005: Export Field Filtering

Test as `customer_viewer@example.test`.

Acceptance:

- `GET /api/customers/export` returns a file only if `customers.export` is granted.
- Exported headers do not include `credit_code`, `phone`, or `email` without corresponding field export permissions.
- Exported file bytes do not contain the forbidden values.
- Audit log records `customers.export` with filter parameters and exported column list.

### BP-006: User Information Field Filtering

Test as `system_admin@example.test` without `system.users.field.phone.read`.

Acceptance:

- `GET /api/system/users` returns user names, departments, groups, and status.
- User `phone` is hidden in list and detail responses.
- React receives `meta.fields.phone.read=false`.
- Direct API access cannot reveal the phone number.

### BP-007: Equipment Attachment Field Filtering

Test as `equipment_manager@example.test` without equipment file read permissions.

Acceptance:

- `GET /api/equipment/{equipment}` returns basic equipment fields.
- `device_image`, `manual_files`, `instruction_files`, `calibration_files`, and `other_files` are hidden unless read permission is granted.
- Direct download endpoints for those files return `403` without permission.
- File URLs are never exposed in hidden fields.

## Frontend Permission Tests

### FP-001: Table Column Visibility

Frontend test file: `frontend/src/features/customers/__tests__/customer-table-permissions.test.tsx`

Acceptance:

- When `meta.fields.phone.read=false`, the customer table does not render the phone column header.
- The phone value does not appear in the DOM.
- The column visibility menu does not offer a forbidden phone column.
- When permission changes to `true`, the column appears after permission refetch.

### FP-002: User Table Field Visibility

Frontend test file: `frontend/src/features/system/users/__tests__/user-table-permissions.test.tsx`

Acceptance:

- A system admin without phone field read permission can see users but cannot see the phone column.
- The user detail drawer also hides phone.
- The edit form does not render phone or renders it disabled with a clear permission state, depending on the chosen UI contract.

### FP-003: Form Field Update Permission

Frontend test file: `frontend/src/features/customers/__tests__/customer-form-permissions.test.tsx`

Acceptance:

- Fields without update permission are not editable.
- The submit payload excludes fields without update permission.
- If a stale UI sends a forbidden field, backend rejection is displayed beside that field.

### FP-004: Action Button Visibility

Frontend test file: `frontend/src/features/system/__tests__/permission-gate.test.tsx`

Acceptance:

- `PermissionGate` renders children only when the action is allowed.
- Export buttons are hidden without `*.export`.
- Delete actions are hidden without `*.delete`.
- Hidden actions are not just visually disabled; they are absent from keyboard navigation.

### FP-005: Responsive Permission Consistency

Frontend test file: `frontend/src/features/customers/__tests__/customer-responsive-permissions.test.tsx`

Acceptance:

- Desktop table and mobile card list use the same field permission metadata.
- A field hidden on desktop is also hidden on mobile.
- Mobile action menus obey the same permissions as desktop row actions.

## Audit Acceptance Tests

### AU-001: Permission Change Audit

Backend test file: `backend/tests/Feature/Audit/PermissionChangeAuditTest.php`

Acceptance:

- Creating a group writes an audit log.
- Assigning permissions to a group writes an audit log.
- Removing permissions from a group writes an audit log.
- Audit `before_values` and `after_values` include permission names.
- Audit log includes actor, target group, IP, user agent, request ID, and hash.

### AU-002: Sensitive Field Update Audit

Backend test file: `backend/tests/Feature/Audit/SensitiveFieldAuditTest.php`

Acceptance:

- Updating `customers.phone` writes before and after values when the actor has permission.
- Denied update attempts record the attempted field name but do not store unauthorized new sensitive values unless explicitly required for forensic logs.
- Audit logs are readable only by users with `system.audit_logs.read`.

### AU-003: Audit Append-Only Behavior

Backend test files:

```text
backend/tests/Feature/Audit/AuditLoggerTest.php
backend/tests/Unit/Audit/AuditHashServiceTest.php
```

Acceptance:

- Application code cannot update an audit log row.
- Application code cannot delete an audit log row.
- Hash chain verification passes for untouched logs.
- Hash chain verification fails after direct database value tampering in a controlled test.
- Hash chain verification fails after direct database middle-row deletion in a controlled test.
- Audit hash input uses canonical JSON serialization so key order does not change the hash.
- API requests preserve a provided `X-Request-Id`, generate one when missing, and return it in the response header.

## System Management Acceptance Tests

### SYS-5: User Management

Acceptance:

- Admin can create a user with department, groups, status, and password policy.
- First-login password change is enforced when `must_change_password=true`.
- Locked users cannot log in.
- Failed login attempts increment and eventually lock the account according to configured policy.
- Reset password invalidates active sessions or tokens according to the chosen auth contract.

Current backend coverage:

- `backend/tests/Feature/System/UserManagementTest.php` covers create/update, department assignment, multi-group assignment, lock/unlock, reset password token invalidation, field-level phone hiding, and mutation audit logs.
- `backend/tests/Feature/System/UserManagementTest.php` also covers server-side user filtering by search, status, department, and group.
- Runtime first-login password-change enforcement remains pending.

### SYS-3: User Group Management

Acceptance:

- Admin can create, update, disable, and list groups.
- Users can belong to multiple groups.
- Group permission matrix can save resource and field permissions in one request.
- Disabling a group removes its effective permissions from users.

### SYS-4: Fine-Grained Permission Control

Acceptance:

- Permission can control create, read, update, delete, export, and field visibility.
- Backend enforces field read/update/export independently.
- Frontend uses backend permission metadata and does not hard-code sensitive field visibility.

### SYS-8: Operation Audit

Acceptance:

- Audit is always enabled in non-test runtime.
- Create, update, delete, export, login, logout, backup, permission changes, and denied authorization events are logged.
- Audit list supports query by actor, module, action, subject, date range, and request ID.
- Audit export obeys audit export permission.

Current backend coverage:

- `backend/tests/Feature/Audit/AuditLogAccessTest.php` covers audit list filtering, before/after values, read permission, and export permission.

### SYS-12: Backup and Restore

Acceptance:

- Manual backup creates a `backup_runs` row.
- Scheduled backup command creates a `backup_runs` row.
- Failed backup records error message.
- Restore action requires explicit permission and records audit log.

Current backend coverage:

- `backend/tests/Feature/System/BackupCommandTest.php` covers `lims:backup --type=daily`, `backup_runs` metadata, and backup audit logging.
- Real MySQL/MariaDB dumps, file archives, failed backup simulation, and restore remain pending.

### SYS-6: Data Dictionary

Acceptance:

- Admin can create dictionary sets and items.
- Dictionary items support label, value, color, sort order, default flag, and status.
- Customer and equipment forms load dictionary options from API.
- Disabled dictionary items cannot be selected for new records but remain displayable on old records.

## Business Module Acceptance Tests

### CUS-1: Customer Basic Information

Acceptance:

- Customer supports name, credit code, type, level, source, industry, phone, email, address, remark, and status.
- Type, level, source, industry, and status use data dictionaries.
- Sensitive fields obey field read/update/export permissions.
- Customer list supports search, filters, pagination, and export.

Current backend coverage:

- `backend/tests/Feature/Customers/CustomerApiTest.php` covers customer list search/filter by name/credit code/phone, type, level, source, industry, status, default contact serialization, and filtered export.
- `backend/tests/Feature/Customers/CustomerFieldPermissionTest.php` covers sensitive field read hiding, update rejection, export exclusion, delete response filtering, and authorization denial audit.

### CUS-2: Customer Contacts

Acceptance:

- Customer can have multiple contacts.
- Exactly one contact can be marked default per customer.
- Contact phone and email obey field permissions.
- Deleting a non-default contact works with permission.
- Deleting a default contact requires selecting or promoting another default contact, or backend rejects the action with a clear error.

Current backend coverage:

- `backend/tests/Feature/Customers/CustomerApiTest.php` covers multiple contacts and exactly one default contact after create/update.

### EQ-1: Equipment Ledger

Acceptance:

- Equipment supports fields derived from legacy migrations:
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
- `equipment_no` is unique.
- Old `placement` text can be stored in `legacy_placement` during migration.
- New records use `equipment_locations`.
- Equipment file fields obey field read permissions.

Current backend coverage:

- `backend/tests/Feature/Equipment/EquipmentApiTest.php` covers create/update/disable with legacy fields and list filters by search, status, location, manufacturer, and calibration due date.
- `backend/tests/Feature/Equipment/EquipmentFieldPermissionTest.php` covers hidden equipment file fields in detail and delete responses.

### Equipment Location Tree

Acceptance:

- Admin can create root and child locations.
- Equipment can be assigned to a location.
- Location tree returns stable sort order.
- A location with equipment cannot be deleted.
- Disabled locations cannot be selected for new equipment.

Current backend coverage:

- `backend/tests/Feature/Equipment/EquipmentApiTest.php` covers root/child location tree responses and rejection when disabling a location with equipment.

### Equipment Label Printing

Acceptance:

- Batch-selected equipment can open label print preview.
- Label size is 40mm x 60mm.
- Label includes equipment number, equipment name, QR code, and `XPD_LIMS` footer.
- Multiple labels page-break correctly.
- Print UI works from desktop browser.

Current backend coverage:

- `backend/tests/Feature/Equipment/EquipmentLabelTest.php` covers label preview permission, 40mm x 60mm dimensions, equipment number/name, QR text, and `XPD_LIMS` footer.

## Java PDF Service Acceptance Tests

### PDF-001: Service Health

Acceptance:

- Laravel PDF client returns healthy when `PDF_SERVICE_ENABLED=true` and Java service is reachable.
- Laravel returns a controlled service unavailable error when Java service is not reachable.

Current backend coverage:

- `backend/tests/Feature/System/PdfServiceHealthTest.php` covers healthy, disabled, and failing-client responses.

### PDF-002: Legacy Endpoint Compatibility

Acceptance:

- `POST /api/pdf/process` accepts multipart PDF and returns `success=true`.
- Response includes `pdf_base64`.
- Response includes `cover_fields` when extraction succeeds.
- Laravel client decodes `pdf_base64` into a stored PDF path.

Current implementation coverage:

- `backend/app/Services/Pdf/PdfRendererClient.php` preserves the legacy Java endpoint paths and decodes `pdf_base64` responses into private storage.
- `services/pdf-renderer-java/src/main/java/com/luang/pdfsigner/web/PdfController.java` keeps the legacy process, extract-cover, entrust-order, and contract endpoints.

### PDF-003: Build Verification

Run:

```bash
cd services/pdf-renderer-java
mvn -q -e -B -DskipTests package
```

Expected:

```text
target/pdf-signer-*.jar
```

Current verification:

- `mvn -q -e -B -DskipTests package` passed for `services/pdf-renderer-java`.

## Minimum Automated Test Files

Backend:

```text
backend/tests/Feature/Auth/LoginPolicyTest.php
backend/tests/Feature/System/EffectivePermissionTest.php
backend/tests/Feature/System/PermissionCatalogTest.php
backend/tests/Feature/System/SuperAdminPermissionTest.php
backend/tests/Feature/System/UserManagementTest.php
backend/tests/Feature/System/DictionaryManagementTest.php
backend/tests/Feature/System/BackupCommandTest.php
backend/tests/Feature/System/PdfServiceHealthTest.php
backend/tests/Feature/Customers/CustomerApiTest.php
backend/tests/Feature/Customers/CustomerFieldPermissionTest.php
backend/tests/Feature/Customers/CustomerExportPermissionTest.php
backend/tests/Feature/Equipment/EquipmentApiTest.php
backend/tests/Feature/Equipment/EquipmentFieldPermissionTest.php
backend/tests/Feature/Equipment/EquipmentLabelTest.php
backend/tests/Feature/Audit/PermissionChangeAuditTest.php
backend/tests/Feature/Audit/SensitiveFieldAuditTest.php
backend/tests/Feature/Audit/AuditAppendOnlyTest.php
backend/tests/Feature/Smoke/AdminWorkflowTest.php
```

Frontend:

```text
frontend/src/features/system/__tests__/permission-gate.test.tsx
frontend/src/features/system/users/__tests__/user-table-permissions.test.tsx
frontend/src/features/system/groups/__tests__/permission-matrix.test.tsx
frontend/src/features/customers/__tests__/customer-table-permissions.test.tsx
frontend/src/features/customers/__tests__/customer-form-permissions.test.tsx
frontend/src/features/customers/__tests__/customer-responsive-permissions.test.tsx
frontend/src/features/equipment/__tests__/equipment-table-permissions.test.tsx
frontend/src/features/equipment/__tests__/equipment-label-print.test.tsx
```

## Release Gate

The first release is not acceptable until every item below passes:

- [ ] Backend full test suite passes.
- [ ] Frontend build passes.
- [ ] Permission catalog test passes.
- [ ] Field read filtering test passes for users, customers, contacts, and equipment.
- [ ] Field update rejection test passes for users, customers, contacts, and equipment.
- [ ] Export filtering test proves forbidden values do not appear in exported bytes.
- [ ] Frontend table column visibility tests pass.
- [ ] Mobile and desktop permission consistency tests pass.
- [ ] Audit append-only and hash chain tests pass.
- [ ] Manual acceptance flow passes with the canonical test users.
- [ ] Java PDF service build passes if PDF integration is included in the milestone.

Current automated verification:

```text
backend: php artisan test -> 50 tests, 282 assertions passed
frontend: npm run test -> 1 test file, 3 tests passed
frontend: npm run lint -> passed
frontend: npm run build -> built successfully with the existing large chunk warning
java pdf: mvn -q -e -B -DskipTests package -> passed
```
