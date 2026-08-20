# Integrating Sphere Inspection Records Plan

Date: 2026-08-20
Branch: `feature/integrating-sphere-inspection`
Owner: Codex (architecture, acceptance, review)
Implementer: Claude through Herdr

## Goal

Add a complete integrating-sphere inspection workflow that lets an operator scan equipment on a mobile device or type equipment numbers on a desktop, load canonical equipment data from the existing equipment ledger, select a sample, enter the measurement values shown in the supplied forms, and preserve a searchable historical record with the equipment used.

## Confirmed requirements

- Equipment is entered first and resolved from the existing `equipment` table.
- Equipment entry supports camera QR scanning and manual typing through the existing `QrScannerPanel` behavior.
- One inspection record can use multiple pieces of equipment.
- Sample numbers support QR scanning and manual typing and resolve to the existing `samples` table.
- The operator and record time default to the authenticated user and current time.
- The measurement form contains:
  - sample number: text, canonical sample number from the database
  - chromaticity X: 4 decimal places
  - chromaticity Y: 4 decimal places
  - dominant wavelength: 1 decimal place
  - peak wavelength: 1 decimal place
  - correlated color temperature: integer
  - color rendering index Ra: 1 decimal place
  - luminous flux: 1 decimal place
  - voltage: 1 decimal place
  - current: 4 decimal places
  - power: 4 decimal places
  - power factor: 4 decimal places
  - frequency: integer
  - remark: optional text
- Records are listed newest first and expose the date and operator.
- Record details expose every used device with equipment number, name, manufacturer, model, serial number, next calibration date, record date, and operator.

## Architecture decisions

### Dedicated aggregate

Create a dedicated parent record and child equipment snapshot table. Do not add integrating-sphere measurements to generic equipment usage records because those records model a start/end lifecycle and a cross-product between devices and samples, while an integrating-sphere inspection is one atomic measurement aggregate with one sample and multiple devices.

### Historical snapshots

Keep nullable foreign keys to the live sample/equipment rows and store immutable snapshots used for display and audit. Editing or deleting a ledger item must not rewrite historical inspection evidence.

Tables:

- `integrating_sphere_inspection_records`
  - `id`
  - nullable `sample_id` with `nullOnDelete`
  - `sample_no` snapshot
  - all measurement columns with database precision matching the form
  - nullable `remark`
  - `recorded_at`
  - nullable `operator_id` with `nullOnDelete`
  - nullable `operator_name` snapshot
  - timestamps and useful search indexes
- `integrating_sphere_inspection_equipment`
  - `id`
  - parent foreign key with `cascadeOnDelete`
  - nullable `equipment_id` with `nullOnDelete`
  - snapshots for equipment number, name, manufacturer, model, serial number, and next calibration date
  - timestamps
  - unique parent/equipment pairing and lookup indexes

### Authorization and audit

Add the resource `integrating_sphere_inspection_records` with `read`, `create`, `update`, and `delete` permissions. Every mutation must use the existing authorization and `AuditLogger` patterns. Grant the canonical equipment-manager acceptance role all actions and the sample-manager role read/create/update access, matching the existing equipment usage workflow.

### API

Add authenticated endpoints under `/api/integrating-sphere-inspection-records`:

- `GET /` for paginated filters and the record list
- `POST /` for atomic record and equipment-snapshot creation
- `GET /{record}` for full detail
- `PUT /{record}` for atomic record and equipment-snapshot replacement
- `DELETE /{record}` for authorized deletion
- `GET /lookup?type=equipment|sample&code=...` for permission-scoped scan/manual lookup

Creation and updates must reject duplicate equipment IDs, unknown sample/equipment IDs, invalid decimal precision, invalid integer fields, empty equipment selections, and incomplete measurement data. Values must be serialized without binary floating-point conversion.

## Frontend

Add one responsive page under `/equipment/integrating-sphere-inspections` and a navigation entry named `积分球点检记录`.

The list page owns filters, pagination, create/edit actions, and record detail. The create/edit modal keeps equipment selection first, then sample lookup, then measurements. Reuse shared components and the existing QR scanner rather than creating another scanner implementation.

Desktop sketch:

```text
+--------------------------------------------------------------------------------+
| 积分球点检记录                                     [ 新增点检记录 ]              |
+--------------------------------------------------------------------------------+
| 样品/设备 [________________]  日期 [____] - [____]               [重置]          |
+--------------------------------------------------------------------------------+
| 样品编号 | X | Y | 主波长 | 峰值波长 | 色温 | Ra | 光通量 | 日期 | 操作人 | 操作 |
| ...                                                                  [详情][编辑]|
+--------------------------------------------------------------------------------+

新增/编辑：
+---------------------------------------------------------------+
| 使用设备（先录入）                                             |
| [扫码/手输设备编号________________] [添加] [打开扫码]           |
| [XPD-S-001 ×] [XPD-S-002 ×]                                   |
| 设备详情：编号 / 名称 / 厂家 / 型号 / 序列号 / 下次校准         |
+---------------------------------------------------------------+
| 样品编号 [扫码/手输________________] [添加] [打开扫码]           |
+---------------------------------------------------------------+
| 色品坐标 X [0.0000]  色品坐标 Y [0.0000]                       |
| 主波长    [0.0]     峰值波长  [0.0]                            |
| 色温      [0]       显色指数  [0.0]                            |
| 光通量    [0.0]     电压      [0.0]                            |
| 电流      [0.0000]  功率      [0.0000]                         |
| 功率因数  [0.0000]  频率      [0]                              |
| 备注      [___________________________________________]         |
|                                      [取消] [保存点检记录]       |
+---------------------------------------------------------------+
```

Mobile sketch:

```text
+------------------------------+
| 积分球点检记录      [新增]     |
| [样品/设备搜索____________]    |
| +--------------------------+  |
| | 样品 26010058874-1-1/1   |  |
| | X 0.3633  Y 0.3549       |  |
| | 色温 4360  Ra 88.4       |  |
| | 2026/8/20 12:27 操作人    |  |
| | [详情] [编辑]             |  |
| +--------------------------+  |
| 新增时使用后置摄像头扫码      |
+------------------------------+
```

The desktop table must have a mobile card equivalent. The full equipment snapshot table belongs in the detail modal so the main table remains usable at common laptop widths.

## Tests and acceptance gates

### Backend

- Feature tests cover permission denial, equipment/sample lookup, create, validation precision, list filters/order, show, update with child replacement, delete, audit entries, and snapshot stability after ledger edits.
- Focused backend tests pass.
- Full backend test suite passes.
- Fresh migrations succeed against the test database.

### Frontend

- Unit tests cover payload normalization, numeric precision rules, duplicate-device prevention, navigation/route permissions, desktop table content, mobile cards, detail equipment snapshots, and scanner selection behavior.
- Focused frontend tests pass.
- Full frontend tests pass.
- Production build passes.

### Visual acceptance

Use real Chrome, not unit-test markup, to verify:

1. Desktop create flow with two equipment entries and one sample.
2. Database equipment details appear after lookup.
3. Decimal inputs preserve the required scales.
4. The saved list row matches the entered data.
5. Detail view shows both equipment snapshots.
6. Edit persists correctly and does not duplicate child devices.
7. Mobile viewport exposes camera scanning and usable record cards without horizontal clipping.
8. Permission-gated actions are hidden when unavailable.

### Repository quality

- Preserve the unrelated untracked PDF signing plan files.
- `git diff --check` passes.
- Review the actual final diff after Claude finishes; pre-existing green tests are not acceptance evidence.
- Do not commit or push until review and acceptance are complete.

## Addendum: global used-equipment ledger

The supplied `积分球点检记录-使用设备` sheet is a global association ledger, not only the equipment section of one record detail. Add this view without introducing another persistence entity or duplicating parent metadata.

### Data contract

Use `integrating_sphere_inspection_equipment` as the association source and join its parent `integrating_sphere_inspection_records` row.

Each flattened row must expose:

- `id`: equipment snapshot child row ID
- `inspection_record_id`: parent integrating-sphere inspection record ID
- `equipment_id`: nullable live equipment-ledger ID
- `equipment_no`
- `equipment_name`
- `manufacturer`
- `model`
- `serial_no`
- `next_calibration_date`
- `recorded_at`: derived from the parent record
- `operator_name`: derived from the parent record

Do not add duplicated date or operator columns to the child table. Historical equipment fields continue to come from the immutable child snapshots; record date and operator continue to come from the immutable parent snapshots.

### API and filtering

Add a literal authenticated read endpoint before resource model-binding routes:

- `GET /api/integrating-sphere-inspection-records/equipment`

It reuses `integrating_sphere_inspection_records.read`, returns paginated flattened rows newest first, and supports:

- search across parent record ID, equipment-ledger ID, equipment number, equipment name, manufacturer, model, and serial number
- exact `inspection_record_id`
- exact `equipment_id`
- `date_from` and `date_to` against parent `recorded_at`

Deleted live equipment remains visible with `equipment_id = null` because the child snapshot is the historical evidence.

### UI

Keep one navigation entry and one route. Add two page-level views:

```text
+--------------------------------------------------------------------------------+
| 积分球点检记录                                      [ 新增点检记录 ]             |
| [ 点检记录总表 ] [ 使用设备总表 ]                                                |
+--------------------------------------------------------------------------------+

使用设备总表：
+--------------------------------------------------------------------------------+
| 搜索 [____________] 点检记录ID [____] 设备台账ID [____] 日期 [____] - [____]     |
+--------------------------------------------------------------------------------+
| ID | 点检记录ID | 设备台账ID | 设备编号 | 名称 | 制造商 | 型号 | 序列号          |
|    | 下次校准 | 日期 | 操作人                                                   |
+--------------------------------------------------------------------------------+
```

Desktop uses the complete table. Mobile uses one card per association row and must show all three IDs plus date/operator without horizontal clipping. The existing per-record detail snapshot table remains unchanged.

### Additional acceptance gates

- Backend tests cover read permission, global ordering, all flattened fields, filters, and deleted-equipment snapshots.
- Frontend tests cover view switching, all requested columns, mobile cards, pagination/filter request parameters, and no duplicated navigation entry.
- Real Chrome verifies the global table after one record is created with two devices: exactly two association rows share one inspection record ID and have distinct child/equipment IDs.
- Real Chrome verifies the mobile association cards and the sample-manager read view.
