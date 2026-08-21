# Integrating Sphere Calibration Records Plan

Date: 2026-08-21
Branch: `feature/integrating-sphere-calibration`
Owner: Codex (architecture, review, Chrome acceptance, worker evaluation)
Implementer: `worker` through Herdr
Source: `/Users/luang/Library/Containers/com.tencent.xinWeChat/Data/Documents/xwechat_files/woxinlidenixinli_71dc/temp/drag/积分球定标记录(2).xlsx`

## Goal

Add the third of four optical workflows as a complete, independent New LIMS feature:

1. integrating-sphere inspection — existing
2. photometric-curve inspection — existing
3. integrating-sphere calibration — this plan
4. photometric-curve calibration — future

An operator records the equipment used, a system, one standard device, calibration mode and sensitivity, optical/electrical measurements, photos, files, a server-owned timestamp, and an immutable operator snapshot. The page exposes both the calibration ledger and the flattened used-equipment ledger defined by the workbook.

This feature must not mutate or overload the existing integrating-sphere inspection tables or the generic equipment-calibration workflow.

## Workbook authority

The workbook has three authoritative views:

1. `积分球定标记录表`: record-list layout and representative values.
2. `积分球定标-使用设备`: flattened record-to-equipment ledger.
3. `Sheet3`: field inclusion, precision, input method, and cardinality.

When the sheets disagree, `Sheet3` controls the data contract. Therefore power factor and frequency are required even though the representative main table omits those columns.

Known workbook copy inconsistencies are normalized as follows:

- `积分球点检记录` in the used-equipment header means `积分球定标记录`.
- `标准件编码` and `标准件编号` become `标准件编号`.
- The sample-shaped example `26010058874-1-1/1` is not authoritative; the standard comes from the equipment ledger, matching `XPD-L-028` in the main table and the note `数据来源设备台账编码`.
- `DATA` means `DATE`.
- Units are displayed as `K`, `lm`, `V`, `A`, `W`, and `Hz`; variable-symbol shorthand does not become an API field name.

## Repository baseline and boundaries

- Baseline tracked HEAD: `907611dd8a7f5f95b9aaf28b54e1db997aee5c31` on local `main`.
- Create `feature/integrating-sphere-calibration`; do not commit or push during worker implementation or review.
- Preserve these already accepted but uncommitted scanner-order changes exactly:
  - `docs/plans/2026-08-20-integrating-sphere-system-code-plan.md`
  - `docs/plans/2026-08-21-photometric-curve-inspection-plan.md`
  - `frontend/src/features/equipment/IntegratingSphereInspectionPage.tsx`
  - `frontend/src/features/equipment/PhotometricCurveInspectionPage.tsx`
  - `frontend/src/features/equipment/__tests__/integrating-sphere-inspection-page.test.tsx`
  - `frontend/src/features/equipment/__tests__/photometric-curve-inspection-page.test.tsx`
- Preserve the three unrelated untracked PDF-signing plans and `配光曲线点检记录表.xlsx` without staging or editing them.
- Do not touch deployment worktrees, production configuration, shared development data, or live systems.
- Do not change the existing generic `equipment_calibrations` API, models, routes, or UI.

## Architecture decisions

### 1. Dedicated typed aggregate

Create `integrating_sphere_calibration_records` with:

- `id`
- nullable `standard_equipment_id` FK to `equipment`, `nullOnDelete`
- immutable standard snapshots:
  - `standard_no`
  - `standard_name`
  - nullable `standard_manufacturer`
  - nullable `standard_model`
  - nullable `standard_serial_no`
  - nullable `standard_next_calibration_date`
- nullable `equipment_system_id` FK to `equipment_systems`, `nullOnDelete`
- immutable `system_code` and nullable `system_name` snapshots
- `mode_code` and `mode_label`
- `sensitivity_code` and `sensitivity_label`
- `color_temperature`: integer
- `color_rendering_index`: decimal, 1 place
- `luminous_flux`: decimal, 1 place
- `voltage`: decimal, 1 place
- `current`: decimal, 4 places
- `power`: decimal, 4 places
- `power_factor`: decimal, 4 places, range 0 through 1
- `frequency`: integer
- nullable `remark`, maximum 2000 characters
- `recorded_at`, indexed and server-owned
- nullable `operator_id` FK with `nullOnDelete`
- nullable immutable `operator_name`
- timestamps and practical sample/system/date indexes

Create `integrating_sphere_calibration_equipment` as the used-equipment child snapshot table with the same columns and retention contract as the two existing inspection child tables.

Each workbook data row is one calibration record. `mode` and `sensitivity` belong to that record; do not introduce a session parent or measurement-detail entity.

### 2. Standard device lifecycle

The standard is one existing equipment-ledger row selected independently from the used-equipment list.

Creation:

- scan or type an exact equipment number;
- resolve a live equipment row;
- store the nullable FK and the complete immutable standard snapshot;
- require exactly one standard.

Editing follows the established retained/selected pattern:

- a retained standard is omitted from the update payload and keeps its snapshot;
- an explicit re-scan sends `standard_equipment_id` and replaces every standard snapshot field;
- a deleted live equipment row remains readable/editable through the stored snapshot with `standard_equipment_id = null`;
- an unrelated measurement edit never re-snapshots or destroys the standard evidence.

The standard may also appear in the used-equipment list. The two roles are explicit and are not deduplicated across roles.

### 3. Extensible mode and sensitivity catalog

Current values:

- mode: `precise` / `精准`, `fast` / `快速`
- sensitivity: `high` / `高`, `low` / `低`

Future values are expected. Do not use database enums, database check constraints, or duplicated frontend-only arrays.

Add one backend-owned catalog, preferably `config/calibration.php`, and expose the active options from:

- `GET /api/integrating-sphere-calibration-records/form-options`

The API returns stable codes plus labels. Creation resolves the selected code and snapshots both code and label. Update omits retained values by default and only re-snapshots an explicitly changed option. Existing records remain readable/editable if an option is later removed from the active catalog.

Do not impose a mode/sensitivity combination matrix. All currently exposed combinations are allowed.

### 4. Shared infrastructure, not shared business tables

Reuse:

- `InspectionSubjectLookup` for equipment and active system resolution;
- `InspectionEquipmentSnapshots` for used-equipment retention;
- `InspectionEquipmentLedger` for the flattened equipment ledger;
- `InspectionMediaLibrary` and private `inspection_media` storage;
- shared frontend scanner, snapshot, ledger, pagination, permission, and responsive components.

Add a reusable single-standard scanner/helper for this feature and the future photometric-curve calibration feature. Keep standard state distinct from sample state.

Extract the existing photometric attachment editor/gallery/download UI into shared frontend inspection-media components instead of copying it into the calibration page. Preserve all existing photometric behavior and tests, including the accepted scanner order `equipment -> system -> sample -> measurements`.

Do not create a runtime-configured generic measurement form or a polymorphic inspection/calibration database table.

### 5. Private media

Use the existing private media infrastructure unchanged:

- `photos`: JPEG/PNG/WebP, maximum 10 items, 10 MB each;
- `files`: PDF/XLS/XLSX/CSV/DOC/DOCX/ZIP, maximum 10 items, 20 MB each;
- extension-to-content validation and OOXML/OLE structure checks;
- parent-scoped authenticated photo view and file download;
- retained-media editing, request-scoped failure cleanup, metadata-only audit entries;
- no storage path or public URL in API payloads.

The new model implements `HasMedia`, registers `photos` and `files` on `inspection_media`, and uses the shared serializer/access endpoints.

### 6. Server-owned audit identity

- `recorded_at` is always set by the backend during creation and never accepted from the client.
- Updates preserve the original timestamp.
- `operator_id` and `operator_name` always come from the authenticated user and are never accepted from the client.
- Every create/update/delete and file download uses the existing authorization and `AuditLogger` patterns.

## API

Resource: `integrating_sphere_calibration_records`

Literal authenticated routes must precede resource binding:

- `GET /api/integrating-sphere-calibration-records/form-options`
- `GET /api/integrating-sphere-calibration-records/lookup?type=equipment|standard|system&code=...`
- `GET /api/integrating-sphere-calibration-records/equipment`
- `GET /api/integrating-sphere-calibration-records/{record}/media/{media}/view`
- `GET /api/integrating-sphere-calibration-records/{record}/media/{media}/download`

Resource routes:

- `GET /api/integrating-sphere-calibration-records`
- `POST /api/integrating-sphere-calibration-records`
- `GET /api/integrating-sphere-calibration-records/{record}`
- `PUT /api/integrating-sphere-calibration-records/{record}`
- `DELETE /api/integrating-sphere-calibration-records/{record}`

List filters:

- free text across standard number/name, system code/name, mode/sensitivity labels, and used-equipment snapshots;
- exact mode code;
- exact sensitivity code;
- date range against `recorded_at`;
- deterministic newest-first ordering by `recorded_at`, then ID.

The global used-equipment endpoint exposes child ID, calibration-record ID, nullable equipment-ledger ID, every equipment snapshot field, parent date, and parent operator. It keeps the same filters and pagination behavior as the existing inspection ledgers.

Lookup authorization is granted to users who may create or update calibration records without granting broad equipment/system ledger read access.

## Authorization

Add `read`, `create`, `update`, and `delete` actions for `integrating_sphere_calibration_records`.

Canonical grants:

- `super_admin`: all actions
- `equipment_manager`: all actions
- `sample_manager`: read, create, update

Update the permission catalog, permission migration, canonical acceptance seeder, and focused permission tests. Do not modify generic calibration permissions.

## Frontend

Add one independent navigation entry:

- label: `积分球定标记录`
- path: `/equipment/integrating-sphere-calibrations`
- permission: `integrating_sphere_calibration_records.read`

Keep one route with `定标记录总表` and `使用设备总表` views.

Desktop editor:

```text
+--------------------------------------------------------------------------------+
| 使用设备（先录入） [扫码/手输________________] [添加] [打开扫码]                |
| [XPD-S-001 ×] [XPD-S-004 ×]                                                    |
+--------------------------------------------------------------------------------+
| 系统编码 [扫码/手输________________] [添加] [打开扫码]  sys-01 · 系统1          |
+--------------------------------------------------------------------------------+
| 标准件编号 [扫码/手输______________] [添加] [打开扫码]  XPD-L-028 · 标准灯      |
+--------------------------------------------------------------------------------+
| 模式 [精准▼]        灵敏度 [高▼]                                                |
| 色温(K) [4360]      显色指数 Ra [88.4]      光通量(lm) [1674.0]                |
| 电压(V) [220.8]     电流(A) [0.1189]       功率(W) [14.2400]                  |
| 功率因数 [0.5422]   频率(Hz) [50]                                             |
| 备注 [_______________________________________________________________]         |
| 照片 [选择文件] [缩略图 ×]              文件 [选择文件] [文件名 ×]              |
|                                                       [取消] [保存定标记录]      |
+--------------------------------------------------------------------------------+
```

Desktop list:

```text
ID | 标准件编号 | 系统编码 | 模式 | 灵敏度 | 色温 | Ra | 光通量 | 日期 | 操作人 | 操作
```

Mobile uses record cards and used-equipment cards. Primary workflows must not require horizontal scrolling. The editor keeps the order `equipment -> system -> standard -> mode/sensitivity -> measurements -> attachments`.

Detail shows every stored field, standard snapshot, used-equipment snapshots, media, date, and operator.

## Backend tests

- permission denial for record CRUD, lookup, form options, and media access;
- mode/sensitivity options returned from the backend catalog;
- creation requires current catalog codes and snapshots their labels;
- arbitrary client values are rejected;
- existing records remain editable when a catalog option is later unavailable;
- standard lookup, required standard, retained/replaced/orphaned standard snapshots;
- used-equipment duplicate prevention, retention, replacement, and orphaned snapshots;
- exact decimal/string serialization and integer bounds;
- power factor range 0 through 1 and frequency inclusion;
- server-owned timestamp/operator ignore forged payload values;
- list/detail/filter/order/global equipment ledger;
- media type/count/size/ownership/view/download/retention/cleanup;
- create/update/delete/download audit entries;
- existing integrating-sphere and photometric-curve inspection backend tests remain green.

## Frontend tests

- backend-driven mode and sensitivity options, labels, and selection;
- scanner order: equipment, system, standard, measurements;
- standard retained/selected/orphaned state and payload behavior;
- exact field scales, units, range validation, and multipart payload;
- power factor and frequency present in form, detail, and payload;
- attachment selection, retained media, authenticated preview/download, and cleanup;
- desktop list/detail and mobile cards;
- used-equipment view, filters, pagination, and all three IDs;
- route/navigation permission gates and mutation invalidation;
- existing inspection pages keep their current scanner order and media behavior.

## Verification gates

Worker must run and report:

1. new calibration backend tests;
2. existing integrating-sphere and photometric-curve inspection backend regression tests;
3. focused frontend calibration and shared-component tests;
4. full backend suite;
5. full frontend suite;
6. frontend TypeScript, ESLint, and production build;
7. Pint on touched PHP files;
8. scratch-SQLite fresh migration, rollback, and re-apply;
9. `git diff --check`;
10. exact branch/HEAD/status and the preserved unrelated files.

Worker must not run Chrome acceptance, commit, push, or modify production/live state. Codex owns independent review and Chrome acceptance.

## Codex review gates

Review the actual diff, not the worker summary. Findings-first review must cover:

- database precision, indexes, FK deletion behavior, and rollback;
- retained standard/system/equipment/media semantics;
- server-owned audit identity;
- catalog extensibility without database enums or frontend drift;
- media authorization, MIME/structure validation reuse, and scoped cleanup;
- permission/route binding order;
- shared-component regression risk and current dirty-file preservation;
- desktop/mobile rendering and error-state behavior.

Every actionable finding returns to `worker` as another work round. A review with no actionable findings advances to Chrome acceptance.

## Real Chrome acceptance

Use an isolated SQLite database and real Chrome:

1. equipment-manager desktop creation with two used devices, one system, and one standard;
2. mode/sensitivity options show the four current labels and save the selected codes;
3. all optical/electrical values preserve exact precision, including power factor and frequency;
4. photo preview and document download work through authenticated endpoints;
5. list/detail show the standard, system, mode, sensitivity, date, and operator;
6. global equipment ledger has exactly two rows for the two used devices;
7. measurement-only edit preserves standard/system/equipment/media snapshots and original timestamp;
8. explicit standard/equipment/media replacement changes only intended children;
9. sample-manager can read/create/update but cannot delete;
10. 390x844 mobile record/editor/equipment cards have no horizontal overflow.

Capture key screenshots and bind the evidence to exact tracked HEAD plus working-tree diff.

## Worker quality evaluation protocol

Track these metrics from the first Herdr prompt until final acceptance:

- **Work rounds**: every implementation or findings-fix prompt sent to `worker` counts as one round.
- **Review rounds**: every complete independent Codex diff review counts once.
- **Worker elapsed time**: wall-clock time from each worker prompt to the corresponding settled state; report per round and total.
- **Total goal time**: `/goal` elapsed time from creation to completion.
- **First-pass quality**: number and severity of findings after work round 1.
- **Regression discipline**: whether new tests demonstrably fail against the broken behavior and protect existing workflows.
- **Architecture quality**: reuse without over-generalization, evidence snapshots, extensible options, and no unsafe duplication.
- **Scope discipline**: unrelated files preserved, no commit/push/live mutation, and no hidden formatting churn.
- **Handoff quality**: exact changed files, tests, remaining gates, branch/HEAD/status, and honest limitations.

Initial counters:

- Work rounds: 0
- Review rounds: 0
- Chrome acceptance rounds: 0
- Worker elapsed time: 0

## Completion definition

Complete only when the implementation plan, worker implementation, all review/fix rounds, focused and full tests, production build, scratch migration cycle, `git diff --check`, real Chrome desktop/mobile acceptance, and worker quality report all pass. Do not commit or push without a separate user instruction.
