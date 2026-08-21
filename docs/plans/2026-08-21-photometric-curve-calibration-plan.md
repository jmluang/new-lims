# Photometric Curve Calibration Records Plan

Date: 2026-08-21
Planning branch: `feature/integrating-sphere-calibration`
Target implementation branch: `feature/photometric-curve-calibration`
Source: `/Users/luang/Library/Containers/com.tencent.xinWeChat/Data/Documents/xwechat_files/woxinlidenixinli_71dc/temp/drag/配光曲线定标记录表.xlsx`

## Goal

Add the fourth and final optical workflow as an independent, production-ready feature:

1. integrating-sphere inspection — implemented
2. photometric-curve inspection — implemented
3. integrating-sphere calibration — implemented and awaiting its own commit/merge step
4. photometric-curve calibration — this plan

An operator records the equipment used, one active equipment system, one standard device, the selected probe, the test distance, the calibration coefficient, optical/electrical measurements, attachments, a server-owned timestamp, and an immutable operator snapshot. The feature exposes both a calibration-record ledger and the flattened used-equipment ledger defined by the workbook.

This is a new workflow, not a column added to the photometric-curve inspection workflow and not an extension of the generic equipment-calibration module.

## Implementation prerequisite and repository boundary

The planning snapshot is tracked HEAD `907611dd8a7f5f95b9aaf28b54e1db997aee5c31` on `feature/integrating-sphere-calibration`, with the accepted integrating-sphere calibration implementation and scanner-order changes still uncommitted.

Before implementation starts:

1. independently re-check the integrating-sphere calibration diff;
2. commit/merge that feature only when separately instructed by the user;
3. record the resulting exact baseline SHA;
4. create `feature/photometric-curve-calibration` from that clean baseline;
5. confirm the three unrelated PDF-signing plans and the original workbook remain untracked and untouched.

The final-feature worker must not overwrite, revert, stage, or reformat unrelated dirty files. It must not run against a shared MySQL database, production configuration, or a live system. Database lifecycle tests use an explicit scratch SQLite database only.

## Workbook authority and resolved contract

The workbook contains three authoritative sheets:

1. `配光曲线定标记录表`: record-list columns and representative values;
2. `配光曲线定标记录-使用设备`: flattened calibration-to-equipment ledger;
3. `Sheet3`: field inclusion, precision, source, input method, and cardinality.

`Sheet3` controls the field contract when presentation rows are incomplete. The red cells identify `定标系数` as the newly added field relative to the earlier form, but the entire workbook describes a new independent system function.

Resolved field mapping:

| Workbook field | API/database field | Type and rule | Source |
| --- | --- | --- | --- |
| 使用设备 | `equipment_ids` / child snapshots | one or more distinct equipment rows | scan or exact manual equipment number |
| 系统编号/系统编码 | `equipment_system_id` plus `system_code`, `system_name` snapshots | one active system required on create | scan or exact manual system code |
| 标准件编号 | `standard_equipment_id` plus full standard snapshots | exactly one equipment-ledger row required on create | scan or exact manual equipment number |
| 选择探头 | `probe` | `near_field` or `far_field`; stored as a string | single select |
| 测试距离 | `test_distance` | decimal, 4 places, metres | manual input |
| 定标系数 | `calibration_coefficient` | decimal, 4 places | manual input |
| 峰值光强 | `peak_luminous_intensity` | decimal, 1 place, cd | manual input |
| 光通量 | `luminous_flux` | decimal, 1 place, lm | manual input |
| 电压 | `voltage` | decimal, 1 place, V | manual input |
| 电流 | `current` | decimal, 4 places, A | manual input |
| 功率 | `power` | decimal, 4 places, W | manual input |
| 功率因数 | `power_factor` | decimal, 4 places, physical range 0 through 1 | manual input |
| 频率 | `frequency` | integer, Hz | manual input |
| 备注 | `remark` | nullable text, maximum 2000 characters | manual input |
| 照片 | `photos[]` | private validated media | upload |
| 上传文件 | `files[]` | private validated media | upload |
| 日期 | `recorded_at` | server-owned timestamp | server |
| 操作人 | `operator_id`, `operator_name` | authenticated user plus immutable name snapshot | server |

Normalizations:

- `标准件编号` is an equipment-ledger number; the representative value is `XPD-L-030`.
- the `17位` note conflicts with `XPD-L-030` and with the stated equipment-ledger source, so it is not a literal length rule; exact live equipment lookup is authoritative.
- `系统编号` and `系统编码` are the same domain field and become `system_code`.
- `DATA` means `DATE` and does not become an API name.
- workbook symbols such as `K`, `Φ`, `V`, `I`, `W`, `PF`, and `F` are display units/symbols, not field names.
- workbook display precision is authoritative. Decimal values cross the API as canonical strings so binary floating point cannot lose scale.
- no angle columns or average angle belong to calibration; those remain exclusive to photometric-curve inspection.
- no mode or sensitivity belongs to photometric-curve calibration; those remain exclusive to integrating-sphere calibration.

## Architecture decisions

### 1. Dedicated typed aggregate

Create `photometric_curve_calibration_records` with:

- `id`;
- nullable `standard_equipment_id` FK to `equipment`, `nullOnDelete`;
- immutable standard snapshots:
  - `standard_no`;
  - `standard_name`;
  - nullable `standard_manufacturer`;
  - nullable `standard_model`;
  - nullable `standard_serial_no`;
  - nullable `standard_next_calibration_date`;
- nullable `equipment_system_id` FK to `equipment_systems`, `nullOnDelete`;
- immutable `system_code` and nullable `system_name` snapshots;
- `probe` string;
- `test_distance` decimal `(12, 4)`;
- `calibration_coefficient` decimal `(12, 4)`;
- `peak_luminous_intensity` decimal `(12, 1)`;
- `luminous_flux` decimal `(12, 1)`;
- `voltage` decimal `(9, 1)`;
- `current` decimal `(12, 4)`;
- `power` decimal `(12, 4)`;
- `power_factor` decimal `(6, 4)`;
- `frequency` integer;
- nullable `remark` text;
- indexed, server-owned `recorded_at`;
- nullable `operator_id` FK to `users`, `nullOnDelete`;
- nullable immutable `operator_name`;
- timestamps and indexes on `standard_no`, `system_code`, `probe`, and practical date/FK combinations.

Validation bounds follow the typed database contract and the physical non-negative domain:

- `test_distance`, `calibration_coefficient`, `current`, and `power`: `0` through `99999999.9999`;
- `peak_luminous_intensity` and `luminous_flux`: `0` through `99999999999.9`;
- `voltage`: `0` through `99999999.9`;
- `power_factor`: `0` through `1`;
- `frequency`: integer `0` through `1000000`.

Create `photometric_curve_calibration_equipment` with the same immutable equipment snapshot columns and retention behavior used by the other three optical workflows. Its parent FK is `calibration_record_id`, cascades on parent deletion, and its live equipment FK becomes null when the ledger row is deleted.

Do not create a polymorphic optical-record table, a JSON measurement bag, a generic dynamic form engine, or nullable calibration columns on an inspection table.

### 2. Evidence snapshot lifecycle

Creation requires:

- at least one used device;
- exactly one active equipment system;
- exactly one standard from the equipment ledger.

The standard may also appear in the used-equipment list because the two selections express different roles. They are not deduplicated across roles.

Editing follows retained/selected semantics:

- omitted standard/system/equipment inputs retain their stored snapshots;
- an explicit re-scan replaces only the selected role with a fresh ledger snapshot;
- deleting a live standard, system, device, or operator row never destroys historical record evidence;
- a measurement-only edit preserves standard, system, used devices, media, `recorded_at`, and operator snapshots;
- a child snapshot ID from another parent can never be retained or grafted onto this record.

Extract a small reusable backend `CalibrationStandardSnapshots` service from the integrating-sphere calibration controller while adding this feature. It owns the seven-field standard snapshot mapping and serialization for both calibration controllers. This removes duplicated lifecycle logic without merging their business tables or measurement contracts.

### 3. Probe contract

Current workbook values are:

- `near_field` / `近场`;
- `far_field` / `远场`.

Store the code in a string column. Do not use a database enum or check constraint. Share the code/label mapping between the two photometric-curve frontend schemas instead of creating another independent label array. Backend validation remains authoritative and rejects unknown values.

Unlike integrating-sphere mode/sensitivity, no requirement currently says probes are an administrator-managed catalog. Do not introduce a catalog table or management UI.

### 4. Shared infrastructure

Reuse the accepted infrastructure from the earlier optical workflows:

- `InspectionSubjectLookup` for equipment and active-system resolution;
- `InspectionEquipmentSnapshots` for immutable used-equipment creation and edit retention;
- `InspectionEquipmentLedger` with `calibration_record_id` support;
- `InspectionMediaLibrary` and the private `inspection_media` disk;
- `EquipmentScannerBlock`, `SystemScannerBlock`, `StandardScannerBlock`, responsive selected-equipment cards/tables, detail snapshots, and used-equipment ledger components;
- `InspectionMediaComponents` for attachment editing, photo viewing, and authenticated file download;
- shared decimal normalization, comparison, media-limit validation, pagination, permission, and QR-scanner behavior.

Keep a dedicated controller, model, schema, query keys, page, and tests for this workflow. Similar-looking measurement grids still have different domain fields and must stay typed.

### 5. Private media and audit identity

Use the established media contract unchanged:

- photos: JPEG/PNG/WebP, at most 10 items, at most 10 MB each;
- files: PDF/XLS/XLSX/CSV/DOC/DOCX/ZIP, at most 10 items, at most 20 MB each;
- extension/content and OOXML/OLE structure checks;
- private, authenticated, parent-scoped photo view and file download;
- retained-media edits and request-scoped cleanup after failed writes;
- no storage path or public URL in API payloads;
- metadata-only audit records.

`recorded_at`, `operator_id`, and `operator_name` are never writable client fields. Creation takes them from the server clock and authenticated user. Updates preserve them. Create/update/delete and media download produce the existing audit actions.

## API and authorization

Resource: `photometric_curve_calibration_records`

Literal authenticated routes must precede resource binding:

- `GET /api/photometric-curve-calibration-records/lookup?type=equipment|standard|system&code=...`
- `GET /api/photometric-curve-calibration-records/equipment`
- `GET /api/photometric-curve-calibration-records/{record}/media/{media}/view`
- `GET /api/photometric-curve-calibration-records/{record}/media/{media}/download`

Resource routes:

- `GET /api/photometric-curve-calibration-records`
- `POST /api/photometric-curve-calibration-records`
- `GET /api/photometric-curve-calibration-records/{record}`
- `PUT /api/photometric-curve-calibration-records/{record}`
- `DELETE /api/photometric-curve-calibration-records/{record}`

List filters:

- free text across standard number/name, system code/name, and used-equipment snapshots;
- exact probe code;
- date range against `recorded_at`;
- deterministic newest-first order by `recorded_at`, then ID.

The global equipment ledger returns child ID, `calibration_record_id`, nullable live `equipment_id`, every immutable equipment snapshot field, parent date, and parent operator. It retains the established filters and pagination behavior.

Add `read`, `create`, `update`, and `delete` permission actions:

- `super_admin`: all actions;
- `equipment_manager`: all actions;
- `sample_manager`: read, create, update;
- all other roles: no implicit grant.

Lookup is available to users who may create or update this record resource without granting broad equipment/system ledger read access.

## Frontend

Add navigation:

- label: `配光曲线定标记录`;
- path: `/equipment/photometric-curve-calibrations`;
- permission: `photometric_curve_calibration_records.read`.

One page contains `定标记录总表` and `使用设备总表` views.

Desktop editor:

```text
+--------------------------------------------------------------------------------+
| 使用设备（先录入） [扫码/手输设备编号____________] [添加] [打开扫码]           |
| [XPD-S-001 ×] [XPD-S-002 ×]                                                    |
+--------------------------------------------------------------------------------+
| 系统编码 [扫码/手输系统编码________________] [添加] [打开扫码]  sys-01 · 系统1 |
+--------------------------------------------------------------------------------+
| 标准件编号 [扫码/手输标准件编号____________] [添加] [打开扫码]                |
| XPD-L-030 · 标准灯                                                             |
+--------------------------------------------------------------------------------+
| 探头 [远场▼]                测试距离(m) [26.2314]                              |
| 定标系数 [1.0024]           峰值光强(cd) [221.0]                              |
| 光通量(lm) [1674.0]         电压(V) [220.8]                                   |
| 电流(A) [0.1189]            功率(W) [14.2400]                                 |
| 功率因数 [0.5422]           频率(Hz) [50]                                     |
| 备注 [____________________________________________________________________]   |
| 照片 [选择文件] [缩略图 ×]      文件 [选择文件] [文件名 ×]                    |
|                                                   [取消] [保存定标记录]        |
+--------------------------------------------------------------------------------+
```

Editor order is fixed:

`equipment -> system -> standard -> probe/distance -> coefficient/measurements -> remark/media`

Desktop record list:

```text
ID | 标准件编号 | 系统编码 | 探头 | 测试距离 | 定标系数 | 峰值光强 | 光通量 | 日期 | 操作人 | 操作
```

Mobile uses record cards, selected-equipment cards, and used-equipment cards. Primary flows must not require horizontal scrolling. Detail shows every stored standard/system/device snapshot, every measurement with unit/scale, media, date, and operator.

## Expected change set

New backend files:

- `backend/app/Http/Controllers/PhotometricCurveCalibrationRecordController.php`;
- `backend/app/Models/PhotometricCurveCalibrationRecord.php`;
- `backend/app/Models/PhotometricCurveCalibrationEquipment.php`;
- `backend/app/Services/Inspection/CalibrationStandardSnapshots.php`;
- `backend/database/migrations/2026_08_21_000400_create_photometric_curve_calibration_tables.php`;
- `backend/database/migrations/2026_08_21_000500_add_photometric_curve_calibration_permissions.php`;
- `backend/tests/Feature/Equipment/PhotometricCurveCalibrationRecordTest.php`.

Expected backend modifications:

- `backend/app/Http/Controllers/IntegratingSphereCalibrationRecordController.php` for behavior-preserving standard-snapshot extraction;
- `backend/app/Services/Authorization/PermissionCatalog.php`;
- `backend/database/seeders/CanonicalAcceptanceSeeder.php`;
- `backend/routes/api.php`;
- focused permission and canonical-seeder tests.

New frontend files:

- `frontend/src/features/equipment/PhotometricCurveCalibrationPage.tsx`;
- `frontend/src/features/equipment/photometricCurveCalibrationSchema.ts`;
- `frontend/src/features/equipment/photometricCurveCalibrationQueries.ts`;
- focused page and schema tests.

Expected frontend modifications:

- `frontend/src/app/routes.tsx`;
- `frontend/src/components/app/navigation.ts`;
- the smallest shared photometric probe mapping/module needed to keep inspection and calibration labels aligned;
- existing photometric inspection tests only where the shared mapping is extracted without behavior change.

Any additional production file must be justified in the worker handoff. Generated output, downloaded media, workbook copies, and unrelated formatting changes are forbidden.

## Implementation sequence

### Phase 0 — Freeze the baseline

- record clean baseline SHA and branch status;
- preserve unrelated untracked files;
- create `feature/photometric-curve-calibration`;
- run existing focused optical tests before changing code.

### Phase 1 — Backend data model and shared standard lifecycle

- add record/equipment migrations and models;
- extract `CalibrationStandardSnapshots` and refactor integrating-sphere calibration to use it without behavior change;
- add permissions, migration grants, canonical seeder grants, and permission tests;
- register literal routes before resource routes.

### Phase 2 — Backend behavior

- implement lookup, CRUD, filters, used-equipment ledger, serialization, media, and audit;
- enforce canonical decimal-string validation and database-safe bounds;
- cover retained/replaced/orphaned standard, system, equipment, and media semantics;
- add the complete feature test suite before frontend integration.

### Phase 3 — Frontend behavior

- add typed schema and query-key module;
- reuse the shared scanner/media/ledger components;
- add record list, filters, details, create/edit dialog, delete control, and used-equipment view;
- add route/navigation permission gates;
- preserve all three existing optical pages exactly.

### Phase 4 — Review and acceptance

- run focused and full automated gates;
- perform findings-first diff review and return every actionable finding to the worker;
- repeat work/review rounds until an independent review has no actionable findings;
- run isolated real Chrome desktop, role, media, edit-retention, and mobile acceptance;
- report exact branch/HEAD/status, test counts, review rounds, findings, screenshots, and remaining external gates;
- do not commit, merge, push, or touch live state without a separate instruction.

## Automated acceptance criteria

### Backend feature tests

The new backend suite must prove:

1. denied users cannot list, create, read, update, delete, lookup, view media, download media, or read the equipment ledger;
2. creation requires one standard, one active system, at least one distinct used device, and a valid probe;
3. standard/system/device values are immutable snapshots, not live joins in API responses;
4. retained, replaced, and orphaned standard/system/device snapshots behave correctly on update;
5. a cross-record retained equipment/media ID is rejected;
6. `test_distance` and `calibration_coefficient` preserve exactly 4 decimal places;
7. peak intensity, luminous flux, and voltage preserve exactly 1 decimal place;
8. current, power, and power factor preserve exactly 4 decimal places;
9. frequency is an integer and power factor is limited to 0 through 1;
10. scientific notation, excess scale, floats that lose canonical scale, negatives where physically invalid, and database-overflow values return validation errors;
11. forged `recorded_at`, `operator_id`, and `operator_name` are ignored, and ordinary edits preserve original audit identity/time;
12. list search, probe/date filters, pagination, and deterministic ordering are correct;
13. the global equipment ledger exposes exactly one row per used-device snapshot with `calibration_record_id`;
14. media type/count/size/ownership/view/download/retention/cleanup rules match the shared contract;
15. create/update/delete/download audit entries contain metadata but no private path or file contents;
16. migrations apply, roll back, and re-apply on scratch SQLite;
17. integrating-sphere calibration and both inspection backend suites remain green after the shared-service refactor.

### Frontend tests

The new frontend suite must prove:

1. scanner order is equipment, system, standard, then measurements;
2. near/far probe codes render Chinese labels and serialize the stable code;
3. standard retained/selected/orphaned states produce the correct update payload;
4. all measurement fields, units, scales, required rules, bounds, and multipart payload keys are exact;
5. coefficient is present in create/edit, record list, mobile card, detail, and payload;
6. power factor and frequency are present in create/edit, detail, and payload;
7. attachment selection, retained media, authenticated preview/download, and removal behavior work;
8. record and equipment views have independent filters, pagination, and query invalidation;
9. create/update/delete controls obey resource permissions;
10. desktop tables and mobile cards expose the required IDs and snapshot evidence;
11. routes/navigation are hidden or rejected without `read` permission;
12. all existing optical frontend suites remain green.

### Repository gates

All of these must pass from the exact implementation checkout:

1. new photometric-curve calibration backend tests;
2. existing integrating-sphere calibration backend tests;
3. existing integrating-sphere and photometric-curve inspection backend tests;
4. full backend suite using `/usr/local/bin/php artisan test`;
5. new feature and shared-component frontend tests;
6. full frontend suite using Node/npm v22.22.2;
7. exact production build: `/Users/luang/.nvm/versions/node/v22.22.2/bin/npm run build`;
8. ESLint with zero new errors or warnings;
9. Pint on touched PHP files;
10. scratch-SQLite `migrate:fresh --seed`, rollback of the new migrations, and re-apply;
11. `git diff --check`;
12. status proof that unrelated files were preserved and no generated/downloaded artifact entered the diff.

## Independent review gates

Review the actual diff, not the worker handoff. Findings-first review covers:

- table precision, indexes, FK deletion behavior, and rollback safety;
- canonical decimal validation versus database precision;
- coefficient naming and presence across migration/model/controller/API/schema/UI/tests;
- retained/replaced/orphaned standard, system, equipment, media, timestamp, and operator semantics;
- cross-parent child/media ownership checks;
- permission grants and literal-route ordering;
- media MIME/structure validation, private delivery, and failure cleanup;
- shared-standard-service behavior in both calibration modules;
- existing photometric inspection regression risk;
- responsive table/card behavior and useful validation/error copy;
- branch/diff scope and preservation of unrelated work.

Any actionable finding creates another work round. Chrome acceptance starts only after a complete review reports no actionable findings.

## Real Chrome acceptance criteria

Run against an isolated SQLite database and a real Chrome session.

### Equipment-manager desktop flow

Create one record with this exact representative dataset:

- used equipment: `XPD-S-001`, `XPD-S-002`;
- system: `sys-01`;
- standard: `XPD-L-030`;
- probe: `far_field` / `远场`;
- test distance: `26.2314` m;
- calibration coefficient: `1.0024`;
- peak luminous intensity: `221.0` cd;
- luminous flux: `1674.0` lm;
- voltage: `220.8` V;
- current: `0.1189` A;
- power: `14.2400` W;
- power factor: `0.5422`;
- frequency: `50` Hz;
- non-empty remark, one valid photo, and one valid document.

Acceptance requires:

1. the saved list/detail values preserve every exact scale and Chinese label;
2. the photo opens through the authenticated view endpoint;
3. the document downloads through the authenticated download endpoint;
4. the global used-equipment ledger contains exactly two rows with the new `calibration_record_id`;
5. list search, probe filter, date filter, pagination, and clear-filter behavior work;
6. changing only coefficient `1.0024 -> 1.0030` preserves the original timestamp, operator, standard, system, used equipment, and media;
7. an explicit standard replacement changes only the standard snapshot;
8. an explicit used-device/media replacement changes only the intended children;
9. create/update/delete/download audit entries are visible and correctly scoped.

### Role flow

As `sample_manager`:

- list/detail/create/edit are available and functional;
- delete is hidden in the UI and rejected by the API.

As a user without resource read permission:

- navigation is hidden;
- direct route/API access is rejected.

### Mobile flow

At `390x844`:

- record list uses readable cards;
- selected equipment uses readable cards;
- editor fields keep the required order and do not overflow the document;
- details and used-equipment ledger require no document-level horizontal scrolling;
- scanner, save, cancel, attachment, edit, and pagination controls remain reachable.

Capture desktop list, desktop detail, mobile editor, mobile record list, and mobile equipment-ledger screenshots. Bind the report to the exact branch, tracked HEAD, and working-tree diff.

## Completion definition

The feature is complete only when:

- every workbook field is represented with the documented precision and source;
- record and used-equipment ledgers are independently usable;
- permissions, snapshots, media, and audit behavior pass automated and real-Chrome acceptance;
- all four optical workflow regression suites and full repository gates pass;
- independent review has no actionable findings;
- the implementation report names exact commits/status and confirms unrelated files were preserved.

Commit, merge, push, and live deployment remain separate user-authorized actions.
