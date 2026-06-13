# Temperature Humidity Record Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the temperature and humidity record workflow from `example/` in the Laravel API and React SPA, including equipment QR/manual lookup, equipment-derived placement fields, complete list pagination/filtering, and acceptance permissions.

**Architecture:** Keep `temp_humidity_records` as the historical reading ledger with denormalized placement snapshots, and add a nullable canonical `equipment_id` link so new records can resolve current equipment data without losing legacy `equip_no` history. Laravel owns equipment lookup, enrichment, validation, pagination filters, permissions, and audit logs. React owns the scanner/manual-entry workflow, placement auto-fill, responsive list/table rendering, and permission-aware actions.

**Tech Stack:** Laravel 13, PHP 8.3+, Sanctum, Spatie permissions, MySQL/MariaDB, PHPUnit, React 19, TypeScript, Vite, TanStack Router, TanStack Query, React Hook Form, Zod, Tailwind CSS, lucide-react, existing `QrScannerPanel`, existing `PaginationControls`.

---

## Legacy Reference Summary

Use these `example/` files as behavior references only:

- `example/temp_humidity.php` and `example/temp_humidity-1.php`: authenticated management page with search, pagination, add/edit/delete, camera scan, manual equipment query, device info preview, and placement auto-fill.
- `example/post.php`: public device ingest endpoint that accepts `temperature`, `humidity`, and `equip_no` by GET or POST, then writes a reading with default `record_person = 设备自动`.

Legacy behavior to preserve:

- Manual records require placement site, placement room, and record person.
- Device lookup by equipment number returns equipment name, model, calibration date, expiration/next calibration date, status, placement site, and placement room.
- After scanning or manually entering an equipment number, the form fills `equip_no`, `location_site`, and `location_room`.
- List defaults to newest first, supports search, supports 30/50/100 rows per page, and shows total rows.
- Public ingest remains compatible with legacy sensors using GET or POST.

Legacy behavior to improve:

- Do not copy inline SQL or mixed PHP page handlers.
- Do not add a second location entity. Resolve placement through existing `equipment.location_id`, `equipment_locations`, and `equipment.legacy_placement`.
- Do not reproduce role-specific legacy quirks where `admin`/`superadmin` could be blocked from edit/delete. Use the current permission catalog.
- Remove debug output from the target UX.

## Current Gaps

- Current management page can CRUD readings but only accepts free-text `equip_no`; it cannot scan/lookup equipment or auto-fill placement.
- Current public ingest stores nullable placement fields even when the equipment exists.
- Current records link to equipment only through `equip_no`; historical strings are useful, but new writes should also store `equipment_id`.
- Current frontend ignores pagination metadata and shows only the default first page.
- Current list filters are limited to one search box.
- Current frontend schema accepts non-numeric temperature/humidity strings and leaves the backend to reject them.
- `CanonicalAcceptanceSeeder` grants equipment management permissions but not `temp_humidity_records.*` to `equipment_manager`.
- There is no frontend component test for the temperature/humidity page workflow.

## Target UX

```text
设备管理 / 温湿度记录
------------------------------------------------------------
[搜索: 设备/场所/房间/记录人] [时间开始] [时间结束] [每页 30 v]
[添加记录]

添加记录
------------------------------------------------------------
+-------------------------------+--------------------------+
| 扫码/输入设备编号              | 设备信息                 |
| [ XPD-S-041              ][查] | XPD-S-041 恒温恒湿箱     |
| [打开扫码]                    | 型号: A1 状态: active    |
|                               | 校准: 2026-01-01         |
|                               | 下次校准: 2027-01-01     |
+-------------------------------+--------------------------+
| 放置场所*  [曹一天宏]          | 放置房间* [样品室]       |
| 温度(℃)   [25.3]              | 湿度(%)  [65.0]          |
| 记录人*    [Alice]             | 记录时间 [2026-06-13...] |
| 备注       [                                      ]        |
|                                      [取消] [保存记录]     |
------------------------------------------------------------

列表
------------------------------------------------------------
设备编号    设备名称      温度   湿度   场所     房间    记录时间    操作
XPD-S-041   恒温恒湿箱    25.3   65.0   曹一天宏 样品室  ...         编辑 删除
------------------------------------------------------------
[上一页] 第 1 / 4 页，共 118 条 [下一页] [30 v]
```

## Domain Decisions

1. `temp_humidity_records.equip_no` remains a snapshot string. Existing records and legacy sensor payloads depend on it.
2. Add nullable `temp_humidity_records.equipment_id` for canonical new writes. Backfill it where `equip_no` matches `equipment.equipment_no`.
3. Manual lookup and manual save should prefer existing equipment. Public ingest stays backward-compatible: it accepts unknown `equip_no`, but when a matching equipment exists it fills `equipment_id`, `location_site`, `location_room`, and `equipment_name` in the response.
4. Placement snapshot derivation:
   - If equipment has a location with a parent: `location_site = parent.name`, `location_room = location.name`.
   - If equipment has a location without a parent: `location_site = location.name`, `location_room = equipment.legacy_placement` when present, otherwise leave `location_room` unchanged for manual correction.
   - If equipment has no location: preserve provided `location_site` and `location_room`.
   - If public device ingest has no equipment match and no provided placement, keep the legacy `example/post.php` fallback: `location_site = 曹一天宏`, `location_room = 样品室`.
5. `record_time` is required at the write contract. Create/ingest default it to `now()` when omitted; update must not overwrite it with null.
6. `next_calibration_date` is the target expiration field. Do not reimplement legacy date math from `calibration_date + calibration_cycle` because the new schema already stores `next_calibration_date`.
7. Legacy `created_by` and `updated_by` are covered by the current `audit_logs` flow for authenticated manual writes. Do not add duplicate string actor columns to `temp_humidity_records`.
8. The public ingest token/rate-limit hardening is not part of this migration. Both legacy and current endpoints are open, and token provisioning for existing sensors is a separate rollout.

---

## Task 1: Add Canonical Equipment Link And Placement Resolver

**Files:**

- Create: `backend/database/migrations/2026_06_13_000000_add_equipment_id_to_temp_humidity_records_table.php`
- Modify: `backend/app/Models/TempHumidityRecord.php`
- Modify: `backend/app/Models/Equipment.php`
- Modify: `backend/app/Models/EquipmentLocation.php`
- Create: `backend/app/Services/Equipment/EquipmentPlacementResolver.php`
- Modify: `backend/app/Http/Controllers/TempHumidityRecordController.php`
- Modify: `backend/tests/Feature/Equipment/TempHumidityIngestTest.php`

- [ ] **Step 1: Write failing backend tests for equipment backfill and placement resolution**

Add these tests to `backend/tests/Feature/Equipment/TempHumidityIngestTest.php`.

```php
public function test_ingest_links_known_equipment_and_uses_equipment_location_defaults(): void
{
    $site = \App\Models\EquipmentLocation::query()->create(['name' => '曹一天宏', 'code' => 'SITE']);
    $room = \App\Models\EquipmentLocation::query()->create(['parent_id' => $site->id, 'name' => '样品室', 'code' => 'ROOM']);
    $equipment = Equipment::query()->create([
        'equipment_no' => 'XPD-S-041',
        'name' => '恒温恒湿箱',
        'model' => 'A1',
        'location_id' => $room->id,
        'calibration_date' => '2026-01-01',
        'next_calibration_date' => '2027-01-01',
        'status' => 'active',
    ]);

    $this->postJson('/api/device/temp-humidity', [
        'temperature' => 25.6,
        'humidity' => 60.2,
        'equip_no' => 'XPD-S-041',
    ])->assertCreated()
        ->assertJsonPath('data.equipment_id', $equipment->id)
        ->assertJsonPath('data.location_site', '曹一天宏')
        ->assertJsonPath('data.location_room', '样品室')
        ->assertJsonPath('data.equipment.name', '恒温恒湿箱')
        ->assertJsonPath('data.equipment.next_calibration_date', '2027-01-01');

    $this->assertDatabaseHas('temp_humidity_records', [
        'equip_no' => 'XPD-S-041',
        'equipment_id' => $equipment->id,
        'location_site' => '曹一天宏',
        'location_room' => '样品室',
    ]);
}

public function test_ingest_preserves_unknown_legacy_equipment_code(): void
{
    $this->postJson('/api/device/temp-humidity', [
        'temperature' => 18.5,
        'humidity' => 44.1,
        'equip_no' => 'UNKNOWN-SENSOR',
    ])->assertCreated()
        ->assertJsonPath('data.equip_no', 'UNKNOWN-SENSOR')
        ->assertJsonPath('data.equipment_id', null)
        ->assertJsonPath('data.location_site', '曹一天宏')
        ->assertJsonPath('data.location_room', '样品室');

    $this->assertDatabaseHas('temp_humidity_records', [
        'equip_no' => 'UNKNOWN-SENSOR',
        'equipment_id' => null,
        'location_site' => '曹一天宏',
        'location_room' => '样品室',
    ]);
}

public function test_get_ingest_keeps_legacy_method_compatibility_for_known_equipment(): void
{
    $site = \App\Models\EquipmentLocation::query()->create(['name' => '曹一天宏', 'code' => 'SITE']);
    $room = \App\Models\EquipmentLocation::query()->create(['parent_id' => $site->id, 'name' => '样品室', 'code' => 'ROOM']);
    $equipment = Equipment::query()->create([
        'equipment_no' => 'XPD-S-043',
        'name' => '温湿度记录仪',
        'location_id' => $room->id,
        'status' => 'active',
    ]);

    $this->getJson('/api/device/temp-humidity?temperature=19.5&humidity=43.2&equip_no=XPD-S-043')
        ->assertCreated()
        ->assertJsonPath('data.equipment_id', $equipment->id)
        ->assertJsonPath('data.location_site', '曹一天宏')
        ->assertJsonPath('data.location_room', '样品室');
}

public function test_update_preserves_existing_room_when_equipment_has_top_level_location_without_legacy_room(): void
{
    $site = \App\Models\EquipmentLocation::query()->create(['name' => '曹一天宏', 'code' => 'SITE']);
    Equipment::query()->create([
        'equipment_no' => 'XPD-S-044',
        'name' => '温湿度记录仪',
        'location_id' => $site->id,
        'legacy_placement' => null,
        'status' => 'active',
    ]);
    $record = TempHumidityRecord::query()->create([
        'equip_no' => 'OLD',
        'temperature' => 20.0,
        'humidity' => 50.0,
        'location_site' => '旧场所',
        'location_room' => '原房间',
        'record_person' => 'Alice',
        'record_time' => now(),
    ]);

    $operator = $this->userWithPermissions(['temp_humidity_records.update']);
    Sanctum::actingAs($operator);

    $this->putJson("/api/temp-humidity-records/{$record->id}", [
        'equip_no' => 'XPD-S-044',
    ])->assertOk()
        ->assertJsonPath('data.location_site', '曹一天宏')
        ->assertJsonPath('data.location_room', '原房间');
}
```

Run:

```bash
cd backend
php artisan test tests/Feature/Equipment/TempHumidityIngestTest.php --filter='equipment_location_defaults|unknown_legacy_equipment_code|get_ingest_keeps_legacy_method_compatibility|preserves_existing_room'
```

Expected: FAIL because `equipment_id` and nested `equipment` serialization do not exist yet.

- [ ] **Step 2: Add nullable `equipment_id` and backfill from `equip_no`**

Create `backend/database/migrations/2026_06_13_000000_add_equipment_id_to_temp_humidity_records_table.php`.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temp_humidity_records', function (Blueprint $table): void {
            $table->foreignId('equipment_id')->nullable()->after('id')->constrained('equipment')->nullOnDelete();
        });

        DB::table('temp_humidity_records')
            ->whereNull('equipment_id')
            ->whereNotNull('equip_no')
            ->orderBy('id')
            ->select(['id', 'equip_no'])
            ->chunkById(500, function ($records): void {
                $equipmentByNo = DB::table('equipment')
                    ->whereIn('equipment_no', $records->pluck('equip_no')->filter()->unique()->values())
                    ->pluck('id', 'equipment_no');

                foreach ($records as $record) {
                    $equipmentId = $equipmentByNo[$record->equip_no] ?? null;

                    if ($equipmentId) {
                        DB::table('temp_humidity_records')
                            ->where('id', $record->id)
                            ->update(['equipment_id' => $equipmentId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('temp_humidity_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('equipment_id');
        });
    }
};
```

- [ ] **Step 3: Update models**

Modify `backend/app/Models/TempHumidityRecord.php`.

```php
#[Fillable([
    'equipment_id',
    'equip_no',
    'temperature',
    'humidity',
    'location_site',
    'location_room',
    'record_person',
    'remark',
    'record_time',
])]
class TempHumidityRecord extends Model
{
    protected $table = 'temp_humidity_records';

    protected function casts(): array
    {
        return [
            'temperature' => 'decimal:2',
            'humidity' => 'decimal:2',
            'record_time' => 'datetime',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function legacyEquipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equip_no', 'equipment_no');
    }
}
```

Modify `backend/app/Models/Equipment.php` so the existing `legacy_placement` column can be used in resolver tests and future equipment imports. Do not add it to the frontend equipment table columns.

```php
'legacy_placement',
```

Modify `backend/app/Models/EquipmentLocation.php`.

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;

public function parent(): BelongsTo
{
    return $this->belongsTo(self::class, 'parent_id');
}
```

- [ ] **Step 4: Add placement resolver**

Create `backend/app/Services/Equipment/EquipmentPlacementResolver.php`.

```php
<?php

namespace App\Services\Equipment;

use App\Models\Equipment;

class EquipmentPlacementResolver
{
    public const LEGACY_DEVICE_SITE = '曹一天宏';
    public const LEGACY_DEVICE_ROOM = '样品室';

    /**
     * @return array{location_site: ?string, location_room: ?string}
     */
    public function resolve(Equipment $equipment, ?string $site = null, ?string $room = null): array
    {
        $equipment->loadMissing('location.parent');
        $location = $equipment->location;
        $parent = $location?->parent;

        return [
            'location_site' => $site ?: ($parent?->name ?? $location?->name),
            'location_room' => $room ?: ($parent ? $location?->name : $equipment->legacy_placement),
        ];
    }

    /**
     * @return array{location_site: string, location_room: string}
     */
    public function legacyDeviceDefaults(): array
    {
        return [
            'location_site' => self::LEGACY_DEVICE_SITE,
            'location_room' => self::LEGACY_DEVICE_ROOM,
        ];
    }
}
```

- [ ] **Step 5: Use the resolver on temp humidity writes**

Modify `backend/app/Http/Controllers/TempHumidityRecordController.php`.

```php
use App\Models\Equipment;
use App\Services\Equipment\EquipmentPlacementResolver;
```

Inject `EquipmentPlacementResolver $placementResolver` into `ingest`, `store`, and `update`.

Add helper methods:

```php
private function equipmentForCode(?string $equipNo): ?Equipment
{
    if ($equipNo === null || trim($equipNo) === '') {
        return null;
    }

    return Equipment::query()
        ->with('location.parent')
        ->where('equipment_no', trim($equipNo))
        ->first();
}

private function enrichPayloadWithEquipment(
    array $data,
    EquipmentPlacementResolver $placementResolver,
    ?TempHumidityRecord $existingRecord = null,
): array
{
    if (array_key_exists('equip_no', $data) && ($data['equip_no'] === null || trim((string) $data['equip_no']) === '')) {
        return [
            ...$data,
            'equipment_id' => null,
        ];
    }

    $equipment = $this->equipmentForCode($data['equip_no'] ?? null);

    if (! $equipment) {
        return array_key_exists('equip_no', $data)
            ? [...$data, 'equipment_id' => null]
            : $data;
    }

    $placement = $placementResolver->resolve(
        $equipment,
        $data['location_site'] ?? $existingRecord?->location_site,
        $data['location_room'] ?? $existingRecord?->location_room,
    );

    return [
        ...$data,
        'equipment_id' => $equipment->id,
        'equip_no' => $equipment->equipment_no,
        'location_site' => $placement['location_site'],
        'location_room' => $placement['location_room'],
    ];
}
```

Call `enrichPayloadWithEquipment()` before `TempHumidityRecord::query()->create(...)` in `ingest` and `store`, and before `$tempHumidityRecord->update(...)` in `update`. In `update`, pass the existing record as the third argument so omitted placement fields keep the saved snapshot when equipment has only a top-level location and no `legacy_placement`.

The explicit `equipment_id => null` branches prevent stale links when a user edits a record from a known `equip_no` to an empty or unknown code.

For `ingest`, detect whether the device code matched equipment before enrichment, then apply legacy placement defaults only when the client sent no placement and no equipment matched:

```php
$matchedEquipment = $this->equipmentForCode($data['equip_no'] ?? null) !== null;
$data = $this->enrichPayloadWithEquipment($data, $placementResolver);

if (! $matchedEquipment && ($data['location_site'] ?? null) === null && ($data['location_room'] ?? null) === null) {
    $data = [
        ...$data,
        ...$placementResolver->legacyDeviceDefaults(),
    ];
}
```

For `store`, keep the existing default:

```php
$data['record_time'] = $data['record_time'] ?? now();
```

For `update`, validate with a separate update rule so omitted fields are allowed but `record_time` cannot be set to null:

```php
private function updateRules(): array
{
    return [
        'location_site' => ['sometimes', 'required', 'string', 'max:255'],
        'location_room' => ['sometimes', 'required', 'string', 'max:255'],
        'equip_no' => ['sometimes', 'nullable', 'string', 'max:255'],
        'temperature' => ['sometimes', 'nullable', 'numeric'],
        'humidity' => ['sometimes', 'nullable', 'numeric'],
        'record_person' => ['sometimes', 'required', 'string', 'max:255'],
        'remark' => ['sometimes', 'nullable', 'string'],
        'record_time' => ['sometimes', 'required', 'date'],
    ];
}
```

- [ ] **Step 6: Extend serialization for linked equipment**

Update `serialize(TempHumidityRecord $record)` to include `equipment_id` and nested equipment metadata.

```php
$equipment = $record->equipment ?? $record->legacyEquipment;

'equipment_id' => $record->equipment_id,
'equipment_name' => $equipment?->name,
'equipment' => $equipment ? [
    'id' => $equipment->id,
    'equipment_no' => $equipment->equipment_no,
    'name' => $equipment->name,
    'model' => $equipment->model,
    'status' => $equipment->status,
    'calibration_date' => $equipment->calibration_date?->toDateString(),
    'next_calibration_date' => $equipment->next_calibration_date?->toDateString(),
] : null,
```

Keep `equipment_name` for existing frontend compatibility during the migration. The `legacyEquipment` fallback prevents old rows without `equipment_id` from losing device names when their `equip_no` matches `equipment.equipment_no`.

Update `auditValues()` to include the canonical link:

```php
'equipment_id' => $record->equipment_id,
```

Also replace all restricted eager loads in this controller. Do not keep `with('equipment:id,equipment_no,name')` or `load('equipment:id,equipment_no,name')`, because the new serializer needs `model`, `status`, `calibration_date`, and `next_calibration_date`.

Use:

```php
private const EQUIPMENT_COLUMNS = [
    'id',
    'equipment_no',
    'name',
    'model',
    'status',
    'calibration_date',
    'next_calibration_date',
];
```

Then eager load with:

```php
->with([
    'equipment' => fn ($query) => $query->select(self::EQUIPMENT_COLUMNS),
    'legacyEquipment' => fn ($query) => $query->select(self::EQUIPMENT_COLUMNS),
])
```

and:

```php
$record->load([
    'equipment' => fn ($query) => $query->select(self::EQUIPMENT_COLUMNS),
    'legacyEquipment' => fn ($query) => $query->select(self::EQUIPMENT_COLUMNS),
])
```

- [ ] **Step 7: Run the focused tests**

Run:

```bash
cd backend
php artisan test tests/Feature/Equipment/TempHumidityIngestTest.php --filter='equipment_location_defaults|unknown_legacy_equipment_code'
```

Expected: PASS.

---

## Task 2: Complete Backend Lookup, Enrichment, Filters, And Serialization

**Files:**

- Modify: `backend/routes/api.php`
- Modify: `backend/app/Http/Controllers/TempHumidityRecordController.php`
- Modify: `backend/tests/Feature/Equipment/TempHumidityIngestTest.php`

- [ ] **Step 1: Add failing tests for lookup and list filters**

Add these tests to `backend/tests/Feature/Equipment/TempHumidityIngestTest.php`.

```php
public function test_user_can_lookup_equipment_for_temperature_humidity_form(): void
{
    $site = \App\Models\EquipmentLocation::query()->create(['name' => '曹一天宏', 'code' => 'SITE']);
    $room = \App\Models\EquipmentLocation::query()->create(['parent_id' => $site->id, 'name' => '样品室', 'code' => 'ROOM']);
    Equipment::query()->create([
        'equipment_no' => 'XPD-S-041',
        'name' => '恒温恒湿箱',
        'model' => 'A1',
        'location_id' => $room->id,
        'calibration_date' => '2026-01-01',
        'next_calibration_date' => '2027-01-01',
        'status' => 'active',
    ]);

    $operator = $this->userWithPermissions(['temp_humidity_records.create']);
    Sanctum::actingAs($operator);

    $this->getJson('/api/temp-humidity-records/equipment-lookup?equip_no=XPD-S-041')
        ->assertOk()
        ->assertJsonPath('data.equipment_no', 'XPD-S-041')
        ->assertJsonPath('data.name', '恒温恒湿箱')
        ->assertJsonPath('data.location_site', '曹一天宏')
        ->assertJsonPath('data.location_room', '样品室')
        ->assertJsonPath('data.next_calibration_date', '2027-01-01');
}

public function test_lookup_returns_404_for_unknown_equipment(): void
{
    $operator = $this->userWithPermissions(['temp_humidity_records.create']);
    Sanctum::actingAs($operator);

    $this->getJson('/api/temp-humidity-records/equipment-lookup?equip_no=NOPE')
        ->assertNotFound();
}

public function test_update_only_user_can_lookup_equipment_for_temperature_humidity_form(): void
{
    Equipment::query()->create([
        'equipment_no' => 'XPD-S-042',
        'name' => '温湿度记录仪',
        'status' => 'active',
    ]);

    $operator = $this->userWithPermissions(['temp_humidity_records.update']);
    Sanctum::actingAs($operator);

    $this->getJson('/api/temp-humidity-records/equipment-lookup?equip_no=XPD-S-042')
        ->assertOk()
        ->assertJsonPath('data.equipment_no', 'XPD-S-042');
}

public function test_index_filters_by_time_and_temperature_ranges(): void
{
    TempHumidityRecord::query()->create([
        'equip_no' => 'A',
        'temperature' => 20.0,
        'humidity' => 40.0,
        'location_site' => '曹一天宏',
        'location_room' => '样品室',
        'record_person' => 'Alice',
        'record_time' => '2026-06-01 08:00:00',
    ]);
    TempHumidityRecord::query()->create([
        'equip_no' => 'B',
        'temperature' => 30.0,
        'humidity' => 70.0,
        'location_site' => '其他场所',
        'location_room' => '仓库',
        'record_person' => 'Bob',
        'record_time' => '2026-06-10 08:00:00',
    ]);

    $reader = $this->userWithPermissions(['temp_humidity_records.read']);
    Sanctum::actingAs($reader);

    $this->getJson('/api/temp-humidity-records?record_time_from=2026-06-02&temperature_min=25&humidity_max=80')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.equip_no', 'B');
}
```

Run:

```bash
cd backend
php artisan test tests/Feature/Equipment/TempHumidityIngestTest.php --filter='lookup_equipment|time_and_temperature_ranges'
```

Expected: FAIL because lookup route and range filters do not exist.

- [ ] **Step 2: Add route before parameterized temp humidity routes**

Modify `backend/routes/api.php` inside the authenticated business route group, before `/temp-humidity-records/{tempHumidityRecord}` routes.

```php
Route::get('/temp-humidity-records/equipment-lookup', [TempHumidityRecordController::class, 'equipmentLookup']);
Route::get('/temp-humidity-records', [TempHumidityRecordController::class, 'index']);
Route::post('/temp-humidity-records', [TempHumidityRecordController::class, 'store']);
Route::put('/temp-humidity-records/{tempHumidityRecord}', [TempHumidityRecordController::class, 'update']);
Route::delete('/temp-humidity-records/{tempHumidityRecord}', [TempHumidityRecordController::class, 'destroy']);
```

- [ ] **Step 3: Use resolver and canonical equipment in controller writes**

Modify `backend/app/Http/Controllers/TempHumidityRecordController.php`.

```php
use Illuminate\Database\Eloquent\ModelNotFoundException;
```

Reuse the `equipmentForCode()` helper from Task 1 inside the new lookup endpoint. No duplicate equipment query logic should be added.

- [ ] **Step 4: Add equipment lookup endpoint**

Add to `TempHumidityRecordController`.

```php
public function equipmentLookup(Request $request, EquipmentPlacementResolver $placementResolver): JsonResponse
{
    $this->authorizeLookupPermission($request);

    $data = $request->validate(['equip_no' => ['required', 'string', 'max:255']]);
    $equipment = $this->equipmentForCode($data['equip_no']);

    if (! $equipment) {
        throw (new ModelNotFoundException())->setModel(Equipment::class);
    }

    return response()->json(['data' => $this->serializeEquipmentLookup($equipment, $placementResolver)]);
}

private function authorizeLookupPermission(Request $request): void
{
    $user = $request->user();

    if ($user && ($user->can('temp_humidity_records.create') || $user->can('temp_humidity_records.update'))) {
        return;
    }

    $this->authorizePermission($request, 'temp_humidity_records.create', self::RESOURCE);
}

private function serializeEquipmentLookup(Equipment $equipment, EquipmentPlacementResolver $placementResolver): array
{
    $placement = $placementResolver->resolve($equipment);

    return [
        'id' => $equipment->id,
        'equipment_no' => $equipment->equipment_no,
        'name' => $equipment->name,
        'model' => $equipment->model,
        'status' => $equipment->status,
        'calibration_date' => $equipment->calibration_date?->toDateString(),
        'next_calibration_date' => $equipment->next_calibration_date?->toDateString(),
        'location_site' => $placement['location_site'],
        'location_room' => $placement['location_room'],
    ];
}
```

- [ ] **Step 5: Add range filters**

Extend `filteredQuery(Request $request)`.

```php
->when($request->filled('record_time_from'), fn (Builder $query): Builder => $query->whereDate('record_time', '>=', $request->date('record_time_from')))
->when($request->filled('record_time_to'), fn (Builder $query): Builder => $query->whereDate('record_time', '<=', $request->date('record_time_to')))
->when($request->filled('temperature_min'), fn (Builder $query): Builder => $query->where('temperature', '>=', $request->float('temperature_min')))
->when($request->filled('temperature_max'), fn (Builder $query): Builder => $query->where('temperature', '<=', $request->float('temperature_max')))
->when($request->filled('humidity_min'), fn (Builder $query): Builder => $query->where('humidity', '>=', $request->float('humidity_min')))
->when($request->filled('humidity_max'), fn (Builder $query): Builder => $query->where('humidity', '<=', $request->float('humidity_max')))
```

- [ ] **Step 6: Run focused backend tests**

Run:

```bash
cd backend
php artisan test tests/Feature/Equipment/TempHumidityIngestTest.php
```

Expected: PASS.

---

## Task 3: Complete Frontend Scan Lookup, Auto-Fill, Filters, And Pagination

**Files:**

- Modify: `frontend/src/features/equipment/TempHumidityListPage.tsx`
- Modify: `frontend/src/features/equipment/tempHumiditySchema.ts`
- Create: `frontend/src/features/equipment/tempHumidityTypes.ts`
- Create: `frontend/src/features/equipment/tempHumidityPreview.tsx`
- Create: `frontend/src/features/equipment/__tests__/temp-humidity-records.test.tsx`

- [ ] **Step 1: Add frontend test for lookup-driven form state**

Create `frontend/src/features/equipment/__tests__/temp-humidity-records.test.tsx`.

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { TempHumidityRecordFormPreview } from '../tempHumidityPreview'
import { tempHumiditySchema } from '../tempHumiditySchema'

describe('TempHumidityRecordFormPreview', () => {
  it('renders lookup equipment details and placement fields in the add form preview', () => {
    const client = new QueryClient()
    const html = renderToStaticMarkup(
      <QueryClientProvider client={client}>
        <TempHumidityRecordFormPreview
          defaultPerson="Alice"
          currentEquipNo="XPD-S-041"
          lookupEquipment={{
            id: 1,
            equipment_no: 'XPD-S-041',
            name: '恒温恒湿箱',
            model: 'A1',
            status: 'active',
            calibration_date: '2026-01-01',
            next_calibration_date: '2027-01-01',
            location_site: '曹一天宏',
            location_room: '样品室',
          }}
        />
      </QueryClientProvider>,
    )

    expect(html).toContain('XPD-S-041')
    expect(html).toContain('恒温恒湿箱')
    expect(html).toContain('曹一天宏')
    expect(html).toContain('样品室')
    expect(html).toContain('2027-01-01')
  })

  it('keeps an unknown scanned equipment code visible when lookup returns an error', () => {
    const html = renderToStaticMarkup(
      <TempHumidityRecordFormPreview
        defaultPerson="Alice"
        currentEquipNo="UNKNOWN-SENSOR"
        lookupEquipment={null}
      />,
    )

    expect(html).toContain('UNKNOWN-SENSOR')
  })

  it('rejects non-numeric temperature and humidity input before submit', () => {
    const base = {
      location_site: '曹一天宏',
      location_room: '样品室',
      equip_no: 'XPD-S-041',
      record_person: 'Alice',
      remark: '',
      record_time: '2026-06-13T09:30',
    }

    expect(tempHumiditySchema.safeParse({ ...base, temperature: 'abc', humidity: '65.0' }).success).toBe(false)
    expect(tempHumiditySchema.safeParse({ ...base, temperature: '25.3', humidity: 'bad' }).success).toBe(false)
    expect(tempHumiditySchema.safeParse({ ...base, temperature: '25.3', humidity: '65.0' }).success).toBe(true)
  })
})
```

Run:

```bash
cd frontend
npm test -- temp-humidity-records.test.tsx
```

Expected: FAIL until the preview export and UI are added.

- [ ] **Step 2: Tighten frontend schema numeric validation**

Modify `frontend/src/features/equipment/tempHumiditySchema.ts`.

```ts
import { z } from 'zod'

const optionalNumericString = (message: string) =>
  z.string().optional().refine((value) => {
    if (value === undefined || value.trim() === '') {
      return true
    }

    return Number.isFinite(Number(value))
  }, message)

export const tempHumiditySchema = z.object({
  location_site: z.string().min(1, '请填写放置场所'),
  location_room: z.string().min(1, '请填写放置房间'),
  equip_no: z.string().optional(),
  temperature: optionalNumericString('温度必须为数字'),
  humidity: optionalNumericString('湿度必须为数字'),
  record_person: z.string().min(1, '请填写记录人'),
  remark: z.string().optional(),
  record_time: z.string().optional(),
})

export type TempHumidityFormValues = z.infer<typeof tempHumiditySchema>
```

- [ ] **Step 3: Add shared lookup type and SSR-safe preview component**

Create `frontend/src/features/equipment/tempHumidityTypes.ts`.

```ts
export type TempHumidityEquipmentLookup = {
  id: number
  equipment_no: string
  name: string
  model?: string | null
  status: string
  calibration_date?: string | null
  next_calibration_date?: string | null
  location_site?: string | null
  location_room?: string | null
}
```

Create `frontend/src/features/equipment/tempHumidityPreview.tsx`.

```tsx
import type { TempHumidityEquipmentLookup } from './tempHumidityTypes'

export function TempHumidityRecordFormPreview({
  defaultPerson,
  currentEquipNo,
  lookupEquipment,
}: {
  defaultPerson: string
  currentEquipNo?: string
  lookupEquipment: TempHumidityEquipmentLookup | null
}) {
  return (
    <section>
      <h1>添加记录</h1>
      <div>{defaultPerson}</div>
      {currentEquipNo ? <div>{currentEquipNo}</div> : null}
      {lookupEquipment ? (
        <dl>
          <dt>设备编号</dt>
          <dd>{lookupEquipment.equipment_no}</dd>
          <dt>设备名称</dt>
          <dd>{lookupEquipment.name}</dd>
          <dt>放置场所</dt>
          <dd>{lookupEquipment.location_site ?? '-'}</dd>
          <dt>放置房间</dt>
          <dd>{lookupEquipment.location_room ?? '-'}</dd>
          <dt>下次校准</dt>
          <dd>{lookupEquipment.next_calibration_date ?? '-'}</dd>
        </dl>
      ) : null}
    </section>
  )
}
```

This preview keeps route page exports clean for `react-refresh/only-export-components`.

- [ ] **Step 4: Add lookup type, local params helper, and page filters**

In `TempHumidityListPage.tsx`, add:

```tsx
import { QrScannerPanel } from '../../components/app/QrScannerPanel'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, PaginationControls, Panel } from '../system/shared'
import { paginationParams } from '../system/utils'
import type { TempHumidityEquipmentLookup } from './tempHumidityTypes'
```

Add filter type and a local params helper:

```tsx
type TempHumidityFilters = {
  search: string
  record_time_from: string
  record_time_to: string
  temperature_min: string
  temperature_max: string
  humidity_min: string
  humidity_max: string
}

function cleanParams(filters: Record<string, string | number>) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
}
```

Use page state:

```tsx
const emptyFilters: TempHumidityFilters = {
  search: '',
  record_time_from: '',
  record_time_to: '',
  temperature_min: '',
  temperature_max: '',
  humidity_min: '',
  humidity_max: '',
}

const [filters, setFilters] = useState(emptyFilters)
const [page, setPage] = useState(1)
const [perPage, setPerPage] = useState(30)
```

Change query to:

```tsx
queryKey: ['temp-humidity-records', filters, page, perPage],
queryFn: async () => {
  const response = await api.get<ApiCollection<TempHumidityRecord>>('/api/temp-humidity-records', {
    params: cleanParams({ ...filters, ...paginationParams(page, perPage) }),
  })

  return response.data
},
```

- [ ] **Step 5: Add equipment lookup mutation and pass it into the form**

Add state and mutation:

```tsx
const [lookupEquipment, setLookupEquipment] = useState<TempHumidityEquipmentLookup | null>(null)
const lookupEquipmentMutation = useMutation({
  mutationFn: async (equipNo: string) => {
    const response = await api.get<{ data: TempHumidityEquipmentLookup }>('/api/temp-humidity-records/equipment-lookup', {
      params: { equip_no: equipNo },
    })

    return response.data.data
  },
  onMutate: () => {
    setLookupEquipment(null)
  },
  onSuccess: (equipment) => {
    setLookupEquipment(equipment)
  },
})
```

Reset `lookupEquipment` when opening create/edit modals. Do not clear the form `equip_no` on lookup failure; unknown legacy equipment codes must remain manually saveable.

- [ ] **Step 6: Render scanner and equipment info inside the form**

Inside `TempHumidityForm`, add props:

```tsx
lookupEquipment: TempHumidityEquipmentLookup | null
lookupError: unknown
lookupPending: boolean
onLookupEquipment: (equipNo: string) => void
```

At the top of the form render:

```tsx
function handleEquipmentDetected(equipNo: string) {
  form.setValue('equip_no', equipNo, { shouldDirty: true, shouldValidate: true })
  onLookupEquipment(equipNo)
}

<div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_20rem]">
  <QrScannerPanel title="扫码/输入设备编号" placeholder="设备编号" onDetected={handleEquipmentDetected} />
  <EquipmentLookupSummary equipment={lookupEquipment} pending={lookupPending} error={lookupError} />
</div>
```

Add `EquipmentLookupSummary`:

```tsx
function EquipmentLookupSummary({
  equipment,
  pending,
  error,
}: {
  equipment: TempHumidityEquipmentLookup | null
  pending: boolean
  error: unknown
}) {
  if (pending) {
    return <Panel title="设备信息"><LoadingState label="正在查询设备" /></Panel>
  }

  if (error) {
    return <Panel title="设备信息"><ErrorNotice error={error} fallback="未找到设备" /></Panel>
  }

  if (!equipment) {
    return <Panel title="设备信息"><p className="text-sm text-slate-500">扫码或输入设备编号后显示设备信息。</p></Panel>
  }

  return (
    <Panel title="设备信息">
      <dl className="grid gap-2 text-sm">
        <div><dt className="text-slate-500">设备编号</dt><dd className="font-medium text-slate-900">{equipment.equipment_no}</dd></div>
        <div><dt className="text-slate-500">设备名称</dt><dd className="font-medium text-slate-900">{equipment.name}</dd></div>
        <div><dt className="text-slate-500">型号</dt><dd>{equipment.model ?? '-'}</dd></div>
        <div><dt className="text-slate-500">状态</dt><dd>{equipment.status}</dd></div>
        <div><dt className="text-slate-500">校准日期</dt><dd>{equipment.calibration_date ?? '-'}</dd></div>
        <div><dt className="text-slate-500">下次校准</dt><dd>{equipment.next_calibration_date ?? '-'}</dd></div>
      </dl>
    </Panel>
  )
}
```

- [ ] **Step 7: Auto-fill fields when lookup succeeds**

Inside `TempHumidityForm`, after `useEffect(() => form.reset(...))`, add:

```tsx
useEffect(() => {
  if (!lookupEquipment || record) {
    return
  }

  form.setValue('equip_no', lookupEquipment.equipment_no, { shouldDirty: true, shouldValidate: true })
  form.setValue('location_site', lookupEquipment.location_site ?? '', { shouldDirty: true, shouldValidate: true })
  form.setValue('location_room', lookupEquipment.location_room ?? '', { shouldDirty: true, shouldValidate: true })
}, [form, lookupEquipment, record])
```

This keeps edit mode stable: looking at an existing record must not silently overwrite its saved placement snapshot.

- [ ] **Step 8: Add pagination controls and expanded filters**

Replace the one-box filter panel with fields for search, time range, temperature range, and humidity range. Use existing `PaginationControls`.

```tsx
<PaginationControls
  meta={recordsQuery.data?.meta}
  page={page}
  perPage={perPage}
  onPageChange={setPage}
  onPerPageChange={(nextPerPage) => {
    setPerPage(nextPerPage)
    setPage(1)
  }}
/>
```

- [ ] **Step 9: Run frontend focused verification**

Run:

```bash
cd frontend
npm test -- temp-humidity-records.test.tsx
npm run lint
```

Expected: PASS.

---

## Task 4: Acceptance Permissions And Documentation

**Files:**

- Modify: `backend/database/seeders/CanonicalAcceptanceSeeder.php`
- Modify: `backend/tests/Feature/Smoke/CanonicalAcceptanceSeederTest.php`
- Modify: `backend/tests/Feature/System/EffectivePermissionTest.php`
- Modify: `docs/example-migration-notes.md`

- [ ] **Step 1: Add failing seeder assertion**

In `backend/tests/Feature/Smoke/CanonicalAcceptanceSeederTest.php`, add assertions for the equipment manager role.

```php
$equipmentManager = Role::query()->where('name', 'equipment_manager')->firstOrFail();

$this->assertTrue($equipmentManager->hasPermissionTo('temp_humidity_records.read'));
$this->assertTrue($equipmentManager->hasPermissionTo('temp_humidity_records.create'));
$this->assertTrue($equipmentManager->hasPermissionTo('temp_humidity_records.update'));
$this->assertTrue($equipmentManager->hasPermissionTo('temp_humidity_records.delete'));
```

Run:

```bash
cd backend
php artisan test tests/Feature/Smoke/CanonicalAcceptanceSeederTest.php
```

Expected: FAIL until the seeder grants are added.

- [ ] **Step 2: Grant temp humidity permissions to `equipment_manager`**

Modify the `equipment_manager` permission list in `backend/database/seeders/CanonicalAcceptanceSeeder.php`.

```php
'temp_humidity_records.read',
'temp_humidity_records.create',
'temp_humidity_records.update',
'temp_humidity_records.delete',
```

- [ ] **Step 3: Add effective permission API assertion**

Add an effective permission API assertion in `backend/tests/Feature/System/EffectivePermissionTest.php` so the seeded role is verified through the same contract used by navigation and protected routes.

```php
public function test_seeded_equipment_manager_receives_temp_humidity_effective_permissions(): void
{
    $this->seed(\Database\Seeders\CanonicalAcceptanceSeeder::class);
    $equipmentManager = User::query()->where('email', 'equipment_manager@example.test')->firstOrFail();
    Sanctum::actingAs($equipmentManager);

    $this->getJson('/api/permissions/effective')
        ->assertOk()
        ->assertJsonPath('data.resources.temp_humidity_records.actions.read', true)
        ->assertJsonPath('data.resources.temp_humidity_records.actions.create', true)
        ->assertJsonPath('data.resources.temp_humidity_records.actions.update', true)
        ->assertJsonPath('data.resources.temp_humidity_records.actions.delete', true);
}
```

- [ ] **Step 4: Document the migration mapping**

Append a temperature/humidity section to `docs/example-migration-notes.md`.

```markdown
## Temperature And Humidity Records

Legacy references:

- `example/temp_humidity.php`
- `example/temp_humidity-1.php`
- `example/post.php`

Target routes:

- `GET|POST /api/device/temp-humidity`
- `GET /api/temp-humidity-records/equipment-lookup`
- `GET /api/temp-humidity-records`
- `POST /api/temp-humidity-records`
- `PUT /api/temp-humidity-records/{tempHumidityRecord}`
- `DELETE /api/temp-humidity-records/{tempHumidityRecord}`

Key adaptations:

- New records keep `equip_no` as a legacy/device snapshot and use nullable `equipment_id` when the equipment exists.
- Equipment lookup resolves placement from `equipment.location_id`, `equipment_locations.parent_id`, and `equipment.legacy_placement`.
- Public device ingest remains GET/POST compatible and enriches known equipment automatically.
- React uses the shared `QrScannerPanel` instead of inline page JavaScript.
```

- [ ] **Step 5: Run acceptance-focused backend verification**

Run:

```bash
cd backend
php artisan test tests/Feature/Smoke/CanonicalAcceptanceSeederTest.php tests/Feature/System/EffectivePermissionTest.php tests/Feature/System/PermissionCatalogTest.php
```

Expected: PASS.

---

## Task 5: Full Verification

**Files:**

- No new files. This task verifies the integrated migration.

- [ ] **Step 1: Run backend focused tests**

Run:

```bash
cd backend
php artisan test tests/Feature/Equipment/TempHumidityIngestTest.php tests/Feature/Smoke/CanonicalAcceptanceSeederTest.php tests/Feature/System/EffectivePermissionTest.php tests/Feature/System/PermissionCatalogTest.php
```

Expected: PASS.

- [ ] **Step 2: Run frontend focused tests and lint**

Run:

```bash
cd frontend
npm test -- temp-humidity-records.test.tsx
npm run lint
```

Expected: PASS.

- [ ] **Step 3: Run migration smoke check**

Run:

```bash
cd backend
php artisan migrate:fresh --seed
```

Expected: PASS. The seeded `equipment_manager` user can access `temp_humidity_records.*` permissions through the effective permission API.

- [ ] **Step 4: Manual browser check**

Start the local app using the existing project commands, log in as an equipment-capable user, and verify:

```text
/equipment/temp-humidity
  - menu item is visible for equipment_manager
  - scanner/manual equipment lookup shows equipment info
  - lookup fills equipment number, placement site, and placement room
  - save creates a record
  - newest records appear first
  - pagination changes page and per-page size
  - edit/delete still obey PermissionGate controls
```

## Self-Review

- Spec coverage: legacy add/edit/delete, search, pagination, scan/manual lookup, device info preview, placement auto-fill, public GET/POST ingest, and equipment manager access are covered.
- Placeholder scan: this plan contains concrete files, routes, tests, code snippets, and verification commands.
- Type consistency: backend uses `equipment_id`, `equip_no`, `location_site`, `location_room`; frontend uses `TempHumidityEquipmentLookup` with the same API field names.
- Boundary: no new business entity is added. The only schema addition is a nullable FK that links readings to existing equipment.
