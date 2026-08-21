# Photometric Curve Inspection Records Plan

Date: 2026-08-21
Branch: `feature/photometric-curve-inspection`
Owner: Codex (architecture, review, Chrome acceptance)
Implementer: Claude through Herdr
Source: `配光曲线点检记录表.xlsx`

## Goal

Implement the supplied photometric-curve inspection workbook as a complete New LIMS workflow. Operators must be able to scan or type equipment, sample, and system codes; record the curve and electrical measurements; attach photos and files; preserve immutable historical snapshots; and browse both the inspection record ledger and the flattened used-equipment ledger.

This is a new inspection aggregate, not an extension of the integrating-sphere record. The two workflows have different measurement contracts, but they share lookup, equipment-snapshot, attachment, and responsive presentation infrastructure.

## Current baseline and repository boundaries

- Start from exact `main` commit `7d1e2c8`.
- Create and work on `feature/photometric-curve-inspection`; do not commit or push until Codex review and acceptance are complete.
- Preserve these unrelated untracked files without staging or editing them:
  - `docs/plans/2026-08-15-pdf-handwritten-digital-signature-plan-final-v14.md`
  - `docs/plans/2026-08-15-pdf-handwritten-digital-signature-plan.md`
  - `docs/plans/2026-08-16-pdf-signing-flow-separation-plan.md`
  - `配光曲线点检记录表.xlsx`
- Do not modify deployment worktrees, production configuration, or live data.
- The existing integrating-sphere feature is behaviorally authoritative for snapshot retention, permissions, scanning, audit entries, pagination, and mobile presentation. Characterize it with tests before extracting shared code.

## Workbook contract

The workbook contains three logical views:

1. `配光曲线点检记录表`: the inspection record ledger.
2. `配光曲线点检记录-使用设备`: the flattened record-to-equipment ledger.
3. `Sheet3`: field precision and input-method notes.

The system contract is:

| Field | Source | Storage/API contract |
|---|---|---|
| Sample | Existing `samples` row | nullable live FK plus immutable `sample_no` snapshot; required for new records |
| Equipment system | Existing active `equipment_systems` row | nullable live FK plus immutable `system_code` and `system_name` snapshots; required for new records |
| Used equipment | One or more existing `equipment` rows | child rows with immutable ledger snapshots |
| `C0/180` | Operator input | non-negative decimal, 1 decimal place |
| `C30/210` | Operator input | non-negative decimal, 1 decimal place |
| `C60/240` | Operator input | non-negative decimal, 1 decimal place |
| `C90/270` | Operator input | non-negative decimal, 1 decimal place |
| Average angle | Derived | computed from the four angle inputs; never accepted as client input and never independently editable |
| Probe | Operator selection | enum `near_field` / `far_field` |
| Test distance | Operator input | non-negative decimal, 4 decimal places, unit `m` |
| Peak luminous intensity | Operator input | non-negative decimal, 1 decimal place, unit `cd` |
| Luminous flux | Operator input | non-negative decimal, 1 decimal place, unit `lm` |
| Voltage | Operator input | non-negative decimal, 1 decimal place, unit `V` |
| Current | Operator input | non-negative decimal, 4 decimal places, unit `A` |
| Power | Operator input | non-negative decimal, 4 decimal places, unit `W` |
| Power factor | Operator input | decimal from 0 through 1, 4 decimal places |
| Frequency | Operator input | non-negative integer, unit `Hz` |
| Recorded time | Server-owned audit value | static timestamp; default server time, never `NOW()`-style volatile data |
| Operator | Authenticated user | nullable live FK plus immutable name snapshot; never accepted from the client |
| Remark | Operator input | optional text, maximum 2000 characters |
| Photos | Uploaded media | image collection, private storage |
| Files | Uploaded media | document collection, private storage |

The workbook's `DATA`, `I`, `F`, and `Φ` headings are treated as presentation inconsistencies, not API names. The system must display unambiguous labels and units: date, `A`, `Hz`, and `lm`.

## Architecture decisions

### 1. Dedicated aggregate, shared infrastructure

Create dedicated photometric-curve parent and used-equipment snapshot tables. Do not add nullable photometric columns to integrating-sphere records and do not create a generic polymorphic measurement table. Dedicated typed columns keep validation, indexing, migrations, and audit evidence understandable.

Before adding the second workflow, extract only the stable duplication from the integrating-sphere implementation:

- backend lookup of equipment, sample, and active system;
- retained-versus-selected sample/system behavior;
- equipment snapshot resolution, duplicate prevention, retention, and serialization;
- flattened used-equipment ledger filtering and serialization where table names can be safely parameterized;
- frontend shared subject/equipment types and retained-selection helpers;
- frontend equipment/sample/system scanner blocks;
- frontend selected-equipment snapshot list;
- frontend used-equipment desktop table, mobile cards, and detail snapshot table.

Keep measurement definitions, record list columns, validation schemas, API resource names, query keys, and record editors domain-specific. Do not build a runtime-configured mega form.

The extraction must leave the existing integrating-sphere API payloads, routes, UI text, permission behavior, and tests unchanged.

### 2. Historical evidence model

Create `photometric_curve_inspection_records`:

- `id`
- nullable `sample_id` with `nullOnDelete`
- `sample_no` snapshot and index
- nullable `equipment_system_id` with `nullOnDelete`
- `system_code` snapshot and index
- nullable `system_name` snapshot
- four angle decimals
- `probe` string enum value
- `test_distance`
- `peak_luminous_intensity`
- `luminous_flux`
- `voltage`
- `current`
- `power`
- `power_factor`
- `frequency`
- nullable `remark`
- `recorded_at` plus index
- nullable `operator_id` with `nullOnDelete`
- nullable `operator_name` snapshot
- timestamps
- compound indexes for sample/date and system/date filtering

Create `photometric_curve_inspection_equipment` with the same evidence contract as the integrating-sphere child table:

- parent FK with `cascadeOnDelete`
- nullable live equipment FK with `nullOnDelete`
- immutable equipment number, name, manufacturer, model, serial number, and next calibration date snapshots
- unique parent/live-equipment pairing and explicit short index names compatible with MySQL

Do not store `average_angle`. Compute it from the four persisted one-decimal values using exact decimal/tenths arithmetic and serialize one decimal place. This removes the workbook's stale-manual-average failure mode.

Editing follows the existing retained/selected contract:

- omitted retained sample/system values keep their snapshots;
- explicit rescans replace the snapshot from a currently selectable live row;
- retained used-equipment child IDs preserve their snapshots;
- explicitly removed child IDs are deleted;
- newly scanned equipment creates new snapshots;
- deleted or renamed ledger rows never rewrite historical values.

### 3. Private photo and file storage

Use the already-installed Spatie Media Library and existing `media` table; do not add another attachment entity.

- Add a private `inspection_media` filesystem disk under `storage/app/private/inspection-media`.
- Make `PhotometricCurveInspectionRecord` implement `HasMedia` and use `InteractsWithMedia`.
- Register `photos` and `files` media collections on the private disk.
- Photos: JPEG, PNG, or WebP; maximum 10 MB each; maximum 10 items.
- Files: PDF, XLS/XLSX, CSV, DOC/DOCX, or ZIP; maximum 20 MB each; maximum 10 items.
- Reject executable/script content and mismatched extension/MIME combinations.
- Store original file name, detected MIME type, byte size, and SHA-256 in media metadata.
- Never expose disk paths or public URLs.
- Add authenticated parent-scoped media endpoints for inline photo viewing and forced file download. Both require parent `read` permission and verify media ownership.
- Log media downloads through `AuditLogger` without logging file contents.

Create and update accept multipart form data. For update, use POST plus `_method=PUT` so PHP receives file bodies reliably. Existing media is preserved by default; when `retained_media_ids` is present it is authoritative and may only contain media owned by the record. New files are added and removed files are deleted only after all validation passes. On any database or storage failure, remove newly written files and leave the prior record/media set intact.

### 4. Authorization and audit

Add `photometric_curve_inspection_records` with `read`, `create`, `update`, and `delete` actions to `PermissionCatalog`.

Canonical role grants:

- `super_admin`: all actions
- `equipment_manager`: all actions
- `sample_manager`: read, create, update

Every create, update, and delete uses the existing controller authorization and `AuditLogger` patterns. Audit payloads include subject snapshots, measurements, derived average, retained equipment snapshots, and media metadata, but never file bytes or private paths.

### 5. API surface

Add literal routes before resource model-binding routes:

- `GET /api/photometric-curve-inspection-records/lookup?type=equipment|sample|system&code=...`
- `GET /api/photometric-curve-inspection-records/equipment`
- `GET /api/photometric-curve-inspection-records/{record}/media/{media}/view`
- `GET /api/photometric-curve-inspection-records/{record}/media/{media}/download`

Add resource routes:

- `GET /api/photometric-curve-inspection-records`
- `POST /api/photometric-curve-inspection-records`
- `GET /api/photometric-curve-inspection-records/{record}`
- `PUT /api/photometric-curve-inspection-records/{record}`
- `DELETE /api/photometric-curve-inspection-records/{record}`

Record filters:

- free text across sample number, system code, system name, and used-equipment snapshot fields;
- exact probe;
- date range against `recorded_at`;
- newest `recorded_at`, then newest ID.

The flattened used-equipment ledger mirrors the workbook and exposes child ID, parent record ID, nullable live equipment ID, all equipment snapshot columns, parent recorded time, and parent operator. It supports free text, exact record ID, exact equipment ID, and date range filters.

Lookup authorization follows the integrating-sphere boundary: users with create or update permission may resolve the three codes without receiving broad ledger-read access. A new system selection must be active. Equipment and sample lookups must resolve exact codes.

### 6. Frontend

Add one navigation item under Equipment:

- label: `配光曲线点检记录`
- path: `/equipment/photometric-curve-inspections`
- icon: reuse an appropriate existing Lucide icon
- permission resource: `photometric_curve_inspection_records.read`

Keep one route and two page-level views: record ledger and used-equipment ledger. Use the existing page shell, panels, tables, cards, modals, pagination, permission gates, and QR scanner.

Desktop layout:

```text
+------------------------------------------------------------------------------------------------+
| 配光曲线点检记录                                                     [ 新增点检记录 ]             |
| [ 点检记录总表 ] [ 使用设备总表 ]                                                               |
+------------------------------------------------------------------------------------------------+
| 搜索 [样品/系统/设备________]  探头 [全部▼]  日期 [____] - [____]  [重置]                         |
+------------------------------------------------------------------------------------------------+
| ID | 样品编号 | 系统编码 | 平均角度 | 探头 | 距离(m) | 峰值(cd) | 光通量(lm) | 日期 | 操作人 | 操作 |
| .. | .........| sys-01   | 60.2     | 远场 | 26.0000 | 221.0    | 1674.0     | ...  | ...    | ...  |
+------------------------------------------------------------------------------------------------+

新增/编辑：
+--------------------------------------------------------------------------------+
| 使用设备： [扫码/手输________________] [添加] [打开扫码]                        |
| [XPD-S-001 · 智能交流测试专用电源 ×] [XPD-S-004 · 数字功率计 ×]                 |
+--------------------------------------------------------------------------------+
| 样品编号： [扫码/手输________________] [添加] [打开扫码]                        |
| 系统编码： [扫码/手输________________] [添加] [打开扫码]  sys-01 · 系统1         |
+--------------------------------------------------------------------------------+
| C0/180 [60.2]  C30/210 [60.2]  C60/240 [60.2]  C90/270 [60.2]                  |
| 平均角度 [60.2 自动计算，只读]            探头 [远场▼]                          |
| 测试距离(m) [26.0000]    峰值光强(cd) [221.0]    光通量(lm) [1674.0]            |
| 电压(V) [220.8] 电流(A) [0.1189] 功率(W) [14.2400] PF [0.5422] 频率(Hz) [50]    |
| 备注 [_______________________________________________________________]         |
| 照片 [选择文件] [缩略图 ×]              文件 [选择文件] [文件名 ×]              |
|                                                       [取消] [保存点检记录]      |
+--------------------------------------------------------------------------------+
```

Mobile layout:

```text
+----------------------------------+
| 配光曲线点检记录          [新增]   |
| [记录总表] [设备总表]             |
| [样品/系统/设备____________]       |
| +------------------------------+ |
| | 26010058874-1-1/1 · sys-01   | |
| | 远场 · 平均角度 60.2          | |
| | 26.0000 m · 221.0 cd          | |
| | 2026/08/21 10:29 · 操作人      | |
| | [详情] [编辑] [删除]           | |
| +------------------------------+ |
| 新增时扫码、附件选择和输入均不横溢  |
+----------------------------------+
```

UI rules:

- Average angle updates immediately from the four angle inputs and remains read-only.
- Probe is a select, not arbitrary text.
- Numeric inputs expose unit labels, decimal hints, and inline validation errors.
- Existing retained sample/system/equipment/media remain visible during edit, including orphaned live references.
- Photo thumbnails use authenticated blob loading; revoke object URLs during cleanup.
- File entries show name and formatted size; downloads preserve the original name.
- Desktop tables must not squeeze every workbook column into one row. Keep the list scannable and put complete measurements, attachments, and equipment snapshots in detail.
- Mobile uses cards and never relies on horizontal scrolling for primary actions or fields.

## Implementation sequence

### Phase 1: characterize and extract shared inspection infrastructure

1. Add/retain focused tests that lock the current integrating-sphere API and UI behavior.
2. Extract backend lookup and equipment snapshot services without changing routes or payloads.
3. Extract frontend retained-selection helpers, scanner blocks, equipment snapshot presentation, and used-equipment ledger presentation.
4. Run integrating-sphere focused backend/frontend tests before adding the new domain.

### Phase 2: backend aggregate and permissions

1. Add migrations, models, relations, decimal casts, and permission migration.
2. Add controller/API routes using shared infrastructure.
3. Implement exact validation, derived average, immutable snapshots, filters, audit, and global equipment ledger.
4. Add private media collections and parent-scoped media access.
5. Update canonical acceptance seeding and permission/catalog tests.

### Phase 3: frontend workflow

1. Add domain schema/types and multipart payload builder.
2. Add record/equipment queries and mutation invalidation.
3. Add responsive page, filters, list/cards, editor, detail, attachments, and permissions.
4. Add route, navigation entry, translation/error mappings, and tests.

### Phase 4: verification and handoff

1. Run focused backend tests for both inspection domains.
2. Run focused frontend tests for both inspection domains.
3. Run Pint, frontend lint, full backend tests, full frontend tests, production build, fresh migrations, and `git diff --check`.
4. Inspect the actual final diff and working tree; do not stage unrelated files.
5. Hand the exact branch/head and test evidence to Codex for independent review and Chrome acceptance.

## Backend test requirements

- Permission denial for read/create/update/delete and media access.
- Equipment, sample, and active-system lookup; disabled/unknown system rejection.
- Create with multiple equipment snapshots and exact decimal serialization.
- Server-owned operator and static recorded time.
- Average angle derived correctly, including rounding, and absent from writable payload rules.
- Probe enum, required fields, precision, range, duplicate equipment, and power-factor validation.
- New-record sample/system requirements and retained legacy edit behavior.
- Snapshot stability after sample/system/equipment rename, disable, or delete.
- Safe update retention/removal/replacement of equipment children and media.
- Photo/file count, size, MIME/extension, ownership, private view/download, and cleanup validation.
- Record filters, deterministic ordering, show, delete, and audit entries.
- Flattened equipment ledger fields, filters, ordering, and orphaned live-equipment rows.
- Integrating-sphere regression tests remain green after extraction.

## Frontend test requirements

- Exact field scales, units, normalization, and payload/FormData encoding.
- Average angle is derived/read-only and reacts to all four inputs.
- Probe select and invalid numeric values report field-specific errors.
- Duplicate equipment is not added; retained orphan snapshots survive unrelated edits.
- Sample/system retained-versus-selected payload behavior.
- Scanner order and independent equipment/sample/system lookup.
- Media selection limits, retained-media IDs, remove behavior, thumbnail cleanup, and download names.
- Desktop list column order and complete detail measurements.
- Record and equipment mobile cards contain the required IDs and audit fields.
- View switching, filters, pagination, query invalidation, navigation, and route permissions.
- Integrating-sphere frontend tests remain green after extraction.

## Real Chrome acceptance

Use real Chrome with the actual application and test database. Unit markup and a production build are not visual acceptance.

1. Desktop create: scan/type two equipment numbers, one sample number, and one system code.
2. Enter workbook-equivalent near-field and far-field records and verify exact displayed precision.
3. Confirm average angle changes when any source angle changes and cannot be edited directly.
4. Upload one photo and one document; save, reopen detail, preview/download, and verify names/content.
5. Confirm one record with two devices creates exactly two rows in the global used-equipment ledger.
6. Edit only a measurement and verify sample/system/equipment/media snapshots remain unchanged.
7. Deliberately replace/remove one device and one attachment and verify only the intended children change.
8. Verify list/detail filters, date/operator, permission-gated actions, and no volatile timestamp change after reload.
9. Repeat create/detail/edit on a mobile viewport; camera scan, numeric fields, attachments, cards, and actions must not clip or overflow.
10. Capture screenshots and report the exact tested Git head.

## Completion definition

The feature is complete only when implementation, focused/full automated validation, production build, fresh migration validation, real Chrome desktop/mobile acceptance, and an independent Codex diff review all pass on the same exact Git head. A green focused test suite alone is not completion.
