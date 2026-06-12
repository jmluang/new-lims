# Sample and Equipment Workflow Integration Implementation Plan (v2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **This is a revision of `2026-06-12-sample-equipment-workflow-integration-plan.md`.** It fixes concrete issues found when reviewing v1 against the real codebase. The original file is left untouched. See **Corrections incorporated in this revision** below for the full list of changes and why each one matters.

**Goal:** Integrate the useful sample and equipment workflows from `example/` into the Laravel API and React SPA, including sample flow cards, scan-based sample operations, sample/equipment labels, equipment usage records, and device calibration records.

**Architecture:** Laravel owns state transitions, permissions, validation, audit logs, label/print preview data, and all database writes. React owns permission-aware navigation, scanning/manual-entry workflows, printable views, and ergonomic list/detail operations. Legacy PHP files are behavior references only; do not copy inline SQL, duplicated receive paths, debug leftovers, or mixed list/form page structure.

**Tech Stack:** Laravel 13, PHP 8.3+, Sanctum, Spatie permissions, MySQL/MariaDB, PHPUnit, React 19, TypeScript, Vite, TanStack Router, TanStack Query, Zod, Tailwind CSS, lucide-react, qrcode.react, html5-qrcode.

---

## Corrections incorporated in this revision

These are the problems found in v1 and how v2 resolves them. Each is also threaded into the relevant task below.

1. **Sample flow service was not a pure refactor and broke the existing test.** v1's `SampleFlowService` (a) made `location_to` required for `lend`, and (b) restricted `return_room` to `status === 'testing'`. The existing, passing test `SampleFlowTest::test_sample_flow_actions_update_sample_and_append_flow_rows` calls `lend` with **no** `location_to`, and calls `return_room` from the `outsourced` state (after `send_out`). Both would have started failing.
   - **v2 fix:** Task 1 is now an explicit *behavior-preserving* refactor of the generic `/flows` endpoint. `location_to` stays optional (defaults to current location); `return_room` keeps no status precondition. Holder is required only for actions that semantically need a target (`lend`, `transfer`, `send_out`) — the existing test already supplies it, so it passes unchanged. The stricter scan-only preconditions (pending-in-room → `lend`, testing-outside-room → `transfer`/`return_room`, etc.) now live in `SampleScanController`, which is the guided entry point that needs them.

2. **`receivedSample()` helper does not exist.** v1's Task 1 and Task 2 tests call `$this->receivedSample([...])`, but there is no shared `TestCase` base — each test file defines its own helpers (`userWithPermissions`, `postJsonAs`, `getJsonAs`, and a sample factory). v1 never defined `receivedSample`.
   - **v2 fix:** Each task that needs it defines a `receivedSample(array $overrides = [])` helper inside its own test file, with the full body shown once and a note to replicate it per file. A **Test Infrastructure Notes** section documents the per-file-helper convention.

3. **`PermissionCatalogTest` pins an exact permission list.** It asserts `assertEqualsCanonicalizing($this->expectedPermissionNames(), …)`. v1 added new permissions in Tasks 7 and 8 but did not list `PermissionCatalogTest.php` as a file to modify, while still expecting it to pass.
   - **v2 fix:** Tasks 7 and 8 now explicitly modify `backend/tests/Feature/System/PermissionCatalogTest.php` (extend `expectedPermissionNames()`), and also run `CanonicalAcceptanceSeederTest` because the seeder derives grants from the catalog.

4. **Missing route-ordering note for `/samples/scan-lookup`.** It must be registered **before** `/samples/{sample}`, or implicit model binding resolves `"scan-lookup"` as a `Sample` and returns 404. v1 called this out for the equipment lookup but not the sample one.
   - **v2 fix:** Task 2 spells out the exact placement relative to the existing `/samples/{sample}` routes.

5. **`serializeEquipmentOption()` / `serializeSampleOption()` did not exist.** v1's Task 6 lookup called them, but that serialization is currently inlined inside `EquipmentUsageRecordController::formOptions()`.
   - **v2 fix:** Task 6 adds an explicit step to extract those two private serializers and reuse them from both `formOptions` and `lookup`.

6. **Task 2 file actions were mislabeled.** `SampleFlowCardTest.php` and `SampleScanTest.php` do not exist yet.
   - **v2 fix:** They are listed as **Create**, each with its own helpers.

7. **Field-permission naming mismatch.** v1's permission model used `equipment_calibrations.field.attachments` / `.photos`, but the DB columns and existing convention (e.g. `manual_files`) are `attachment_files` / `photo_files`.
   - **v2 fix:** Field permissions are named `attachment_files` / `photo_files`.

8. **Flow card showed `action_by` as a raw user id.** The legacy flow card shows the operator name.
   - **v2 fix:** Task 2 resolves and returns `action_by_name` alongside the id.

9. **`领样` one-click ripple.** Because lend no longer requires a location, the list quick action can stay a one-click that defaults to the sample's current location; this is now stated explicitly in Task 5.

---

## Test Infrastructure Notes

- There is **no shared base `TestCase` with HTTP/permission helpers**. Each feature test file declares its own `userWithPermissions(array)`, `postJsonAs(User, string, array)`, `getJsonAs(User, string)`, and a sample/entity factory method. When a task says "add a `receivedSample` helper," it means add it to that specific test file.
- `userWithPermissions` creates a fresh role, attaches the named permissions (via `Permission::findOrCreate`), assigns it to a new factory user, and forgets the cached permissions. Copy the existing implementation from `backend/tests/Feature/Samples/SampleFlowTest.php`.
- New permissions must be added in **three** synchronized places or tests fail:
  1. `backend/app/Services/Authorization/PermissionCatalog.php` (`resourceActions()` / `fieldActions()`).
  2. `backend/tests/Feature/System/PermissionCatalogTest.php` (`expectedPermissionNames()` — exact-list assertion).
  3. `frontend/src/features/system/groups/permissionNames.ts` (Chinese labels for the new resources/fields, so the matrix UI renders names instead of raw keys).
  The seeder (`CanonicalAcceptanceSeeder`) already derives its grants from `PermissionCatalog->permissionNames()`, so it picks new permissions up automatically — but `CanonicalAcceptanceSeederTest` should still be run after any catalog change.

---

## Legacy Reference Map

Use these `example/` files as behavioral references:

| Workflow | Legacy files | Target modules |
|---|---|---|
| Sample receiving | `sample_manage.php`, `sample_receive.php`, `ajax_get_order_samples.php` | Existing `ReceiveSamples`, `SampleController`, `SampleReceivePage` |
| Sample list and operations | `sample_list.php`, `sample_operate.php`, `sample_scan.php` | `SampleController`, `SampleFlowController`, new `SampleScanController`, `SampleListPage`, new `SampleScanPage` |
| Sample flow card | `sample_flow_card.php` | new `SampleFlowCardController`, `SampleDetailPage`, new `SampleFlowCardPrintArea` |
| Sample labels | `print_label.php`, `sample_label.php`, `autoprint_label.php` | Existing `SampleLabelController`, `SampleLabelPrintArea`, `SampleListPage` |
| Storage location | `warehouse_manage.php`, `get_children_warehouse.php` | Existing `EquipmentLocationController` and `equipment_locations` tree |
| Equipment ledger | `equipment.php`, `equipment - 副本.php`, `api_equipment.php` | Existing `EquipmentController`, `EquipmentListPage`, `EquipmentFormPage` |
| Equipment labels | `equipment_label.php` | Existing `EquipmentLabelController`, `EquipmentLabelPrintPage` |
| Equipment usage | `equipment_usage.php`, `equipment_usage_start.php`, `api_equipment_usage.php` | Existing `EquipmentUsageRecordController`, `EquipmentUsageRecordPage` |
| Device calibration | `device_calibration.php`, `device_calibration_form.php`, `device_calibration_view.php`, `ajax_delete_photo.php` | new calibration migrations, models, controllers, pages |
| Calibration projects | `calibration_projects.php`, `api_calibration_project.php`, `calibration_project_label.php` | new `CalibrationProjectController`, `CalibrationProjectPage`, label preview |
| General QR tools | `qrcode_generator.php`, `qrcode_label.php` | shared QR/print components only when used by business workflows |

Legacy issues to fix during integration:

1. `sample_manage.php` and `sample_receive.php` both receive samples. Keep the existing single `ReceiveSamples` service as the only receive writer.
2. `sample_info.php` uses old fields such as `name`; do not integrate it.
3. `equipment_usage.php` contains a stray `AAA` debug text; do not reproduce it.
4. Legacy `warehouses` should not become a second location entity. Use the existing `equipment_locations` tree and labels.
5. Sample and equipment usage scan flows must resolve records through backend APIs; frontend scanning is only an input method.
6. Device calibration is related to equipment but not the same workflow as equipment usage. Implement it as a separate equipment submodule.

## Target UX

```text
业务管理 / 样品信息
------------------------------------------------------------
[搜索] [状态] [持有人]                         [扫码流转] [接收样品] [样品标签]

样品编号       状态      持有人     位置        操作
S-001          待检      样品室     样品室      领样 / 流转卡 / 标签
S-002          在检      Alice      实验区A     流转 / 归还 / 流转卡 / 标签

样品流转卡
------------------------------------------------------------
[打印流转卡]
样品信息
流转历史 timeline

扫码流转
------------------------------------------------------------
[摄像头扫码区域]
[或输入样品编号] [查询]
样品信息
[领样 / 流转 / 归还] [位置名称] [备注] [确认]

设备使用记录
------------------------------------------------------------
[扫码/输入设备编号] [添加设备]       已选设备列表
[扫码/输入样品编号] [添加样品]       已选样品列表
[开始测试]

设备定标记录
------------------------------------------------------------
[新建定标记录]
定标名称 / 时间 / 操作人 / 结果
设备明细 / 标准件明细 / 附件 / 现场照片
```

## Permission Model

Use existing permissions where possible:

- `samples.read`: sample list, sample detail, sample profile in flow card.
- `samples.receive`: receive samples.
- `samples.update`: sample state mutation.
- `sample_labels.print`: sample label print preview.
- `sample_flows.read`: flow history and printable flow card.
- `sample_flows.create`: lend, transfer, return, outsource, scrap, position-change actions.
- `equipment.read`: equipment ledger and equipment lookup.
- `equipment_labels.print`: equipment label print preview.
- `equipment_usage_records.read/create/update/delete`: equipment usage list and actions.
- `equipment_locations.read`: location options for receive and flow operations.

Add permissions for calibration:

- `calibration_projects.read/create/update/delete`
- `calibration_project_labels.print`
- `equipment_calibrations.read/create/update/delete`
- `equipment_calibrations.field.attachment_files.read/update`
- `equipment_calibrations.field.photo_files.read/update`

> Field-permission keys match the actual DB columns (`attachment_files`, `photo_files`), consistent with the existing `equipment` field permissions (`serial_no`, `manual_files`, …). Add Chinese labels for them in `permissionNames.ts`.

Navigation rules:

- Add `扫码流转` only when `sample_flows.create` is granted.
- Keep `流转卡` as an action inside sample list/detail when `sample_flows.read` is granted.
- Add `设备定标记录` only when `equipment_calibrations.read` is granted.
- Add `定标项目` under system settings only when `calibration_projects.read` is granted.

---

## Task 1: Backend Sample Flow State Service (behavior-preserving refactor)

**Goal of this task:** Move the existing state-transition logic out of `SampleFlowController::store` into a reusable `SampleFlowService` **without changing the behavior of the `/flows` endpoint**, so the existing `SampleFlowTest` keeps passing unchanged and the service can be reused by the scan controller (Task 2).

**Files:**

- Create: `backend/app/Services/Samples/SampleFlowService.php`
- Modify: `backend/app/Http/Controllers/SampleFlowController.php`
- Modify: `backend/tests/Feature/Samples/SampleFlowTest.php` (add a helper + one new test; **do not** weaken the existing test)

- [ ] **Step 1: Keep the existing test green, add one happy-path test**

The existing `test_sample_flow_actions_update_sample_and_append_flow_rows` must continue to pass **unchanged**. It exercises `lend` (no `location_to`), `send_out`, then `return_room` from the `outsourced` state. The refactor must preserve all of that.

Add a `receivedSample` helper to this file (there is no shared base `TestCase`):

```php
private function receivedSample(array $overrides = []): Sample
{
    $order = TestOrder::query()->create([
        'order_no' => 'FLOW',
        'contract_no' => 'FLOW',
        'order_date' => '2026-05-29',
        'urgency' => 'normal',
        'client_company' => '中山市XXX有限公司',
        'sample_status' => 'received',
    ]);

    return Sample::query()->create([
        'test_order_id' => $order->id,
        'delivery_sequence' => 1,
        'sample_no' => 'FLOW-1-1/1',
        'sample_name' => '路灯',
        'specification' => 'LD',
        'model' => 'LD-100',
        'quantity' => 1,
        'status' => 'pending',
        'current_holder' => '样品室',
        'current_location' => '样品室',
        'received_date' => '2026-05-29',
        'sort_order' => 1,
        'delivery_received_count' => 1,
        ...$overrides,
    ]);
}
```

Then add:

```php
public function test_lend_transfer_and_return_room_update_sample_and_append_flows(): void
{
    $operator = $this->userWithPermissions(['samples.read', 'samples.update', 'sample_flows.read', 'sample_flows.create']);
    $sample = $this->receivedSample([
        'status' => 'pending',
        'current_holder' => '样品室',
        'current_location' => '样品室',
    ]);

    $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
        'action_type' => 'lend',
        'holder_to' => 'Alice',
        'location_to' => '实验区A',
        'remark' => 'Start test',
    ])->assertCreated();

    $this->assertDatabaseHas('samples', [
        'id' => $sample->id,
        'status' => 'testing',
        'current_holder' => 'Alice',
        'current_location' => '实验区A',
    ]);

    $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
        'action_type' => 'transfer',
        'holder_to' => 'Bob',
        'location_to' => '实验区B',
    ])->assertCreated();

    $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
        'action_type' => 'return_room',
        'location_to' => '样品室',
    ])->assertCreated();

    $this->assertDatabaseCount('sample_flows', 3);
    $this->assertDatabaseHas('samples', [
        'id' => $sample->id,
        'status' => 'pending',
        'current_holder' => '样品室',
        'current_location' => '样品室',
    ]);
}
```

- [ ] **Step 2: Add the service (preserves current `sampleUpdates` semantics)**

Implement one service that owns all sample state changes. Compared with the controller's current `sampleUpdates`, the only deltas are: holder is *required* for `lend`/`transfer`/`send_out` (the existing test already provides it), `position_change` requires a location, and an explicit `default` arm. Location stays optional and defaults to the current location for every action — this is what keeps the existing test green.

```php
<?php

namespace App\Services\Samples;

use App\Models\Sample;
use App\Models\SampleFlow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SampleFlowService
{
    /**
     * @param array{action_type:string, holder_to?:?string, location_to?:?string, remark?:?string} $data
     */
    public function record(User $user, Sample $sample, array $data): SampleFlow
    {
        return DB::transaction(function () use ($user, $sample, $data): SampleFlow {
            $beforeHolder = $sample->current_holder;
            $beforeLocation = $sample->current_location;

            $sample->update([
                ...$this->updatesFor($sample, $data),
                'updated_by' => $user->id,
            ]);

            return $sample->flows()->create([
                'action_type' => $data['action_type'],
                'action_by' => $user->id,
                'action_time' => now(),
                'holder_from' => $beforeHolder,
                'holder_to' => $sample->current_holder,
                'location_from' => $beforeLocation,
                'location_to' => $sample->current_location,
                'remark' => $data['remark'] ?? null,
            ]);
        });
    }

    /**
     * @param array{action_type:string, holder_to?:?string, location_to?:?string} $data
     * @return array<string, string|null>
     */
    private function updatesFor(Sample $sample, array $data): array
    {
        return match ($data['action_type']) {
            'lend' => [
                'status' => 'testing',
                'current_holder' => $this->requiredText($data['holder_to'] ?? null, 'holder_to'),
                'current_location' => $this->text($data['location_to'] ?? null) ?? $sample->current_location,
            ],
            'transfer' => [
                'current_holder' => $this->requiredText($data['holder_to'] ?? null, 'holder_to'),
                'current_location' => $this->text($data['location_to'] ?? null) ?? $sample->current_location,
            ],
            'return_room' => [
                'status' => 'pending',
                'current_holder' => '样品室',
                'current_location' => $this->text($data['location_to'] ?? null) ?? $sample->current_location,
            ],
            'send_out' => [
                'status' => 'outsourced',
                'current_holder' => $this->requiredText($data['holder_to'] ?? null, 'holder_to'),
                'current_location' => $this->text($data['location_to'] ?? null) ?? $sample->current_location,
            ],
            'receive_back' => [
                'status' => 'outsource_returned',
                'current_holder' => '样品室',
                'current_location' => $this->text($data['location_to'] ?? null) ?? $sample->current_location,
            ],
            'return_client' => [
                'status' => 'returned',
                'current_holder' => $this->text($data['holder_to'] ?? null) ?? '客户',
                'current_location' => $this->text($data['location_to'] ?? null) ?? $sample->current_location,
            ],
            'scrap' => [
                'status' => 'scrapped',
                'current_location' => $this->text($data['location_to'] ?? null) ?? $sample->current_location,
            ],
            'position_change' => [
                'current_location' => $this->requiredText($data['location_to'] ?? null, 'location_to'),
            ],
            default => throw ValidationException::withMessages(['action_type' => ['invalid_sample_flow_action']]),
        };
    }

    private function requiredText(?string $value, string $field): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            throw ValidationException::withMessages([$field => ["{$field}_required"]]);
        }

        return $text;
    }

    private function text(?string $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
```

> **Note on preconditions:** state-availability checks (e.g. "only `lend` a pending sample that is in 样品室") deliberately live in the scan controller (Task 2), not here. The generic `/flows` endpoint stays permissive, matching current behavior and the existing test.

- [ ] **Step 3: Refactor the controller to use the service**

In `SampleFlowController::store`, keep the existing permission checks and the `validate(...)` rules (`action_type` `in:lend,transfer,return_room,send_out,receive_back,return_client,scrap,position_change`, plus `holder_to`/`location_to`/`remark`), delete the inline `sampleUpdates`/manual update block, and delegate to the service. Inject `SampleFlowService` as a method parameter:

```php
public function store(Request $request, Sample $sample, SampleFlowService $sampleFlowService): JsonResponse
{
    $this->authorizePermission($request, 'sample_flows.create', 'sample_flows', $sample);
    $this->authorizePermission($request, 'samples.update', 'samples', $sample);

    $data = $request->validate([
        'action_type' => ['required', 'in:lend,transfer,return_room,send_out,receive_back,return_client,scrap,position_change'],
        'holder_to' => ['nullable', 'string', 'max:255'],
        'location_to' => ['nullable', 'string', 'max:255'],
        'remark' => ['nullable', 'string'],
    ]);

    $flow = $sampleFlowService->record($request->user(), $sample, $data);

    return response()->json(['data' => $this->serializeFlow($flow)], 201);
}
```

Keep the private `serializeFlow` method as-is.

- [ ] **Step 4: Run backend tests**

```bash
cd backend
php artisan test tests/Feature/Samples
```

Expected: all sample feature tests pass, including the **unchanged** `test_sample_flow_actions_update_sample_and_append_flow_rows`.

---

## Task 2: Backend Sample Flow Card and Scan APIs

**Files:**

- Create: `backend/app/Http/Controllers/SampleFlowCardController.php`
- Create: `backend/app/Http/Controllers/SampleScanController.php`
- Modify: `backend/routes/api.php`
- Create: `backend/tests/Feature/Samples/SampleFlowCardTest.php`
- Create: `backend/tests/Feature/Samples/SampleScanTest.php`

> Both test files are **new**. Each needs its own copy of `userWithPermissions`, `postJsonAs`, `getJsonAs`, and the `receivedSample` helper from Task 1 (there is no shared base `TestCase`).

- [ ] **Step 1: Add flow-card preview tests**

```php
public function test_flow_card_preview_returns_sample_profile_and_flows(): void
{
    $viewer = $this->userWithPermissions(['samples.read', 'sample_flows.read']);
    $sample = $this->receivedSample(['sample_no' => 'S-FLOW-001']);
    $sample->flows()->create([
        'action_type' => 'receive',
        'action_by' => $viewer->id,
        'action_time' => now(),
        'holder_from' => null,
        'holder_to' => '样品室',
        'location_from' => null,
        'location_to' => '样品室',
        'remark' => '样品接收',
    ]);

    $this->getJsonAs($viewer, "/api/samples/{$sample->id}/flow-card")
        ->assertOk()
        ->assertJsonPath('data.sample.sample_no', 'S-FLOW-001')
        ->assertJsonPath('data.flows.0.action_type', 'receive')
        ->assertJsonPath('data.flows.0.action_by_name', $viewer->name);
}
```

- [ ] **Step 2: Add scan lookup tests**

```php
public function test_scan_lookup_returns_available_operations_for_pending_sample(): void
{
    $operator = $this->userWithPermissions(['samples.read', 'sample_flows.create']);
    $sample = $this->receivedSample([
        'sample_no' => 'S-SCAN-001',
        'status' => 'pending',
        'current_holder' => '样品室',
        'current_location' => '样品室',
    ]);

    $this->getJsonAs($operator, '/api/samples/scan-lookup?sample_no=S-SCAN-001')
        ->assertOk()
        ->assertJsonPath('data.sample.id', $sample->id)
        ->assertJsonPath('data.available_actions.0', 'lend');
}

public function test_scan_flow_rejects_action_not_available_for_current_state(): void
{
    $operator = $this->userWithPermissions(['samples.read', 'samples.update', 'sample_flows.create']);
    $sample = $this->receivedSample([
        'status' => 'pending',
        'current_holder' => '样品室',
        'current_location' => '样品室',
    ]);

    // return_room is not an available action for a pending in-room sample.
    $this->postJsonAs($operator, "/api/samples/{$sample->id}/scan-flow", [
        'action_type' => 'return_room',
        'location_to' => '样品室',
    ])->assertStatus(422);
}
```

- [ ] **Step 3: Implement `SampleFlowCardController`**

Return print-ready data. Use `samples.read` plus `sample_flows.read`. Resolve operator names so the printed card shows a person, not a raw id (fixes v1's `action_by`-only output). Build a `users` id→name map once to avoid N+1.

```php
public function show(Request $request, Sample $sample): JsonResponse
{
    $this->authorizePermission($request, 'samples.read', 'samples', $sample);
    $this->authorizePermission($request, 'sample_flows.read', 'sample_flows', $sample);

    $sample->load('testOrder', 'flows');

    $operatorNames = User::query()
        ->whereIn('id', $sample->flows->pluck('action_by')->filter()->unique())
        ->pluck('name', 'id');

    return response()->json([
        'data' => [
            'sample' => [
                'id' => $sample->id,
                'sample_no' => $sample->sample_no,
                'sample_name' => $sample->sample_name,
                'specification' => $sample->specification,
                'model' => $sample->model,
                'order_no' => $sample->testOrder?->order_no,
                'client_company' => $sample->testOrder?->client_company,
                'status' => $sample->status,
                'current_holder' => $sample->current_holder,
                'current_location' => $sample->current_location,
                'received_date' => $sample->received_date?->toDateString(),
                'storage_condition' => $sample->storage_condition,
                'batch_no' => $sample->batch_no,
            ],
            'flows' => $sample->flows->map(fn (SampleFlow $flow): array => [
                'id' => $flow->id,
                'action_type' => $flow->action_type,
                'action_by' => $flow->action_by,
                'action_by_name' => $operatorNames[$flow->action_by] ?? null,
                'action_time' => $flow->action_time?->toISOString(),
                'holder_from' => $flow->holder_from,
                'holder_to' => $flow->holder_to,
                'location_from' => $flow->location_from,
                'location_to' => $flow->location_to,
                'remark' => $flow->remark,
            ])->values(),
        ],
    ]);
}
```

- [ ] **Step 4: Implement `SampleScanController`**

`lookup` resolves a sample number and returns the sample profile plus allowed actions. `store` guards the requested action against `availableActions` (this is where the state preconditions live), then reuses `SampleFlowService`.

```php
public function lookup(Request $request): JsonResponse
{
    $this->authorizePermission($request, 'sample_flows.create', 'sample_flows');

    $data = $request->validate(['sample_no' => ['required', 'string', 'max:255']]);
    $sample = Sample::query()->where('sample_no', $data['sample_no'])->firstOrFail();

    return response()->json([
        'data' => [
            'sample' => $this->serializeSample($sample),
            'available_actions' => $this->availableActions($sample),
        ],
    ]);
}

public function store(Request $request, Sample $sample, SampleFlowService $sampleFlowService): JsonResponse
{
    $this->authorizePermission($request, 'sample_flows.create', 'sample_flows', $sample);
    $this->authorizePermission($request, 'samples.update', 'samples', $sample);

    $data = $request->validate([
        'action_type' => ['required', 'in:lend,transfer,return_room,receive_back'],
        'holder_to' => ['nullable', 'string', 'max:255'],
        'location_to' => ['nullable', 'string', 'max:255'],
        'remark' => ['nullable', 'string'],
    ]);

    if (! in_array($data['action_type'], $this->availableActions($sample), true)) {
        throw ValidationException::withMessages(['action_type' => ['sample_flow_action_not_available']]);
    }

    $flow = $sampleFlowService->record($request->user(), $sample, $data);

    return response()->json(['data' => $this->serializeFlow($flow)], 201);
}

private function availableActions(Sample $sample): array
{
    if ($sample->status === 'pending' && $sample->current_holder === '样品室') {
        return ['lend'];
    }

    if ($sample->status === 'testing' && $sample->current_holder !== '样品室') {
        return ['transfer', 'return_room'];
    }

    if ($sample->status === 'outsourced') {
        return ['receive_back'];
    }

    return [];
}
```

Routes — **ordering matters.** `/samples/scan-lookup` must be registered **before** the existing `/samples/{sample}` route (currently `backend/routes/api.php:110`), otherwise implicit model binding tries to resolve `"scan-lookup"` as a `Sample` and returns 404. The `{sample}/...` routes have a static suffix so they are safe after `/samples/{sample}`. Place the literal route first:

```php
// BEFORE Route::get('/samples/{sample}', ...)
Route::get('/samples/scan-lookup', [SampleScanController::class, 'lookup']);

// AFTER the existing /samples/{sample} and /samples/{sample}/flows routes
Route::get('/samples/{sample}/flow-card', [SampleFlowCardController::class, 'show']);
Route::post('/samples/{sample}/scan-flow', [SampleScanController::class, 'store']);
```

- [ ] **Step 5: Run backend tests**

```bash
cd backend
php artisan test tests/Feature/Samples
```

Expected: sample flow, flow card, scan lookup, scan-guard, and receive tests pass.

---

## Task 3: Frontend Shared QR Scanner Component

**Files:**

- Modify: `frontend/package.json`
- Modify: `frontend/package-lock.json`
- Create: `frontend/src/components/app/QrScannerPanel.tsx`
- Create: `frontend/src/components/app/__tests__/qr-scanner-panel.test.tsx`

- [ ] **Step 1: Add dependency**

`qrcode.react` is already present; `html5-qrcode` is not. Add it:

```bash
cd frontend
npm install html5-qrcode
```

- [ ] **Step 2: Create scanner component**

The component must support camera scan and manual entry. Camera errors must not block manual entry.

```tsx
type QrScannerPanelProps = {
  title: string
  placeholder: string
  onDetected: (text: string) => void
}

export function QrScannerPanel({ title, placeholder, onDetected }: QrScannerPanelProps) {
  const [manualValue, setManualValue] = useState('')
  const [cameraEnabled, setCameraEnabled] = useState(false)
  const readerId = useId().replace(/:/g, '')

  function submitManual() {
    const text = manualValue.trim()

    if (text !== '') {
      onDetected(text)
      setManualValue('')
    }
  }

  return (
    <Panel title={title}>
      <div className="grid gap-3 md:grid-cols-[1fr_auto]">
        <input className={inputClass} value={manualValue} onChange={(event) => setManualValue(event.target.value)} placeholder={placeholder} />
        <Button variant="secondary" onClick={submitManual}>
          添加
        </Button>
      </div>
      <div className="mt-3 flex gap-2">
        <Button variant="secondary" onClick={() => setCameraEnabled((value) => !value)}>
          {cameraEnabled ? '关闭扫码' : '打开扫码'}
        </Button>
      </div>
      {cameraEnabled ? <QrCamera readerId={readerId} onDetected={onDetected} /> : null}
    </Panel>
  )
}
```

- [ ] **Step 3: Test manual entry behavior**

```tsx
it('submits manual QR text and clears the input', () => {
  const detected = vi.fn()
  render(<QrScannerPanel title="扫码" placeholder="编号" onDetected={detected} />)

  fireEvent.change(screen.getByPlaceholderText('编号'), { target: { value: 'S-001' } })
  fireEvent.click(screen.getByText('添加'))

  expect(detected).toHaveBeenCalledWith('S-001')
  expect(screen.getByPlaceholderText('编号')).toHaveValue('')
})
```

- [ ] **Step 4: Run frontend tests**

```bash
cd frontend
PATH=/Users/luang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:./node_modules/.bin:$PATH vitest run src/components/app/__tests__/qr-scanner-panel.test.tsx
```

Expected: scanner component tests pass.

---

## Task 4: Frontend Sample Flow Card Print

**Files:**

- Create: `frontend/src/features/samples/SampleFlowCardPrintArea.tsx`
- Modify: `frontend/src/features/samples/SampleDetailPage.tsx`
- Modify: `frontend/src/lib/zh.ts`
- Create: `frontend/src/features/samples/__tests__/sample-flow-card-print.test.tsx`

- [ ] **Step 1: Add print component tests**

```tsx
it('renders printable sample profile and flow timeline', () => {
  render(
    <SampleFlowCardPrintArea
      card={{
        sample: { sample_no: 'S-001', sample_name: '灯具', status: 'pending', current_holder: '样品室', current_location: '样品室' },
        flows: [{ id: 1, action_type: 'receive', action_time: '2026-06-12T00:00:00.000Z', holder_to: '样品室', location_to: '样品室', action_by_name: '张三' }],
      }}
    />,
  )

  expect(screen.getByText('S-001')).toBeInTheDocument()
  expect(screen.getByText('receive')).toBeInTheDocument()
})
```

- [ ] **Step 2: Implement print isolation**

Use `@media print` to hide app layout and print only `.sample-flow-card-print-area`.

```tsx
export function SampleFlowCardPrintStyles() {
  return (
    <style>
      {`
        @media print {
          body * { visibility: hidden !important; }
          .sample-flow-card-print-area, .sample-flow-card-print-area * { visibility: visible !important; }
          .sample-flow-card-print-area { position: absolute; inset: 0; width: 100%; padding: 16mm; background: white; }
        }
      `}
    </style>
  )
}
```

- [ ] **Step 3: Wire `SampleDetailPage`**

Add a query for `/api/samples/{sampleId}/flow-card` and a `打印流转卡` button. Render the operator name (`action_by_name`) in the timeline. The button must be wrapped in:

```tsx
<PermissionGate resource="sample_flows" action="read">
  <Button variant="secondary" onClick={() => window.print()}>
    <Printer className="size-4" aria-hidden="true" />
    打印流转卡
  </Button>
</PermissionGate>
```

- [ ] **Step 4: Run frontend tests**

```bash
cd frontend
PATH=/Users/luang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:./node_modules/.bin:$PATH vitest run src/features/samples/__tests__/sample-flow-card-print.test.tsx
```

Expected: print component tests pass.

---

## Task 5: Frontend Sample Scan Operations

**Files:**

- Create: `frontend/src/features/samples/SampleScanPage.tsx`
- Create: `frontend/src/features/samples/sampleScanSchema.ts`
- Modify: `frontend/src/app/routes.tsx`
- Modify: `frontend/src/components/app/navigation.ts`
- Modify: `frontend/src/features/samples/SampleListPage.tsx`
- Modify: `frontend/src/lib/zh.ts`
- Create: `frontend/src/features/samples/__tests__/sample-scan.test.ts`

- [ ] **Step 1: Add payload builder tests**

```ts
it('builds a scan flow payload with required location for return_room', () => {
  expect(
    buildSampleScanFlowPayload({
      action_type: 'return_room',
      location_to: '样品室',
      remark: 'return',
    }),
  ).toEqual({
    action_type: 'return_room',
    location_to: '样品室',
    remark: 'return',
  })
})
```

- [ ] **Step 2: Implement schema**

The scan UI requires a location for every action (the backend allows it to be optional, but the guided scan flow always captures one):

```ts
export const sampleScanFlowSchema = z.object({
  action_type: z.enum(['lend', 'transfer', 'return_room', 'receive_back']),
  holder_to: z.string().optional(),
  location_to: z.string().min(1, '请选择位置名称'),
  remark: z.string().optional(),
})
```

- [ ] **Step 3: Implement page**

`SampleScanPage` uses `QrScannerPanel`, calls `/api/samples/scan-lookup`, shows the sample profile, renders allowed action buttons (from `available_actions`), a location select, a holder input when the action needs one (`lend`/`transfer`), remark, and a confirm button. Handle a 404 from `scan-lookup` (unknown sample number) and a 422 from `scan-flow` (action not available for current state) with inline messages rather than crashing.

Manual and camera flow:

```text
scan/manual code
  -> GET /api/samples/scan-lookup?sample_no=...
  -> select action (only those in available_actions)
  -> POST /api/samples/{id}/scan-flow
  -> invalidate ['samples'] and ['sample-flows', id]
```

- [ ] **Step 4: Add route and navigation**

Route:

```tsx
const sampleScanRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/samples/scan',
  beforeLoad: () => requireRoutePermission('sample_flows', 'create'),
  component: SampleScanPage,
})
```

Navigation (add under 业务管理, matching the existing `{ label, to, icon, resource, action }` shape):

```tsx
{ label: '扫码流转', to: '/samples/scan', icon: ScanLine, resource: 'sample_flows', action: 'create' }
```

- [ ] **Step 5: Add sample list quick actions**

In `SampleListPage`, add:

- `领样` when `sample.status === 'pending' && sample.current_holder === '样品室'`. Because `lend` no longer requires a location, this can be a one-click that posts `{ action_type: 'lend', holder_to: <current user/operator> }` and lets the backend default the location to the sample's current location — or it can route to `/samples/scan` prefilled. Pick the one-click for ergonomics; document which one you implement.
- `流转` and `归还` when `sample.status === 'testing' && sample.current_holder !== '样品室'` (these route to the scan/flow dialog so a location can be entered).
- `流转卡` when `sample_flows.read` is granted.
- Keep existing `标签` action when `sample_labels.print` is granted.

- [ ] **Step 6: Run frontend tests**

```bash
cd frontend
PATH=/Users/luang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:./node_modules/.bin:$PATH vitest run src/features/samples/__tests__ src/components/app/__tests__/navigation.test.ts
```

Expected: sample scan and navigation tests pass.

---

## Task 6: Equipment Usage Lookup and Scan-Based Selection

**Files:**

- Modify: `backend/app/Http/Controllers/EquipmentUsageRecordController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/Equipment/EquipmentUsageRecordTest.php`
- Modify: `frontend/src/features/equipment/EquipmentUsageRecordPage.tsx`
- Modify: `frontend/src/features/equipment/equipmentUsageSchema.ts`
- Modify: `frontend/src/features/equipment/__tests__/equipment-usage-records.test.ts`

- [ ] **Step 1: Add lookup backend tests**

`EquipmentUsageRecordTest` already has `sample(string $sampleNo)`, `userWithPermissions`, and `getJsonAs` helpers — reuse them.

```php
public function test_usage_lookup_resolves_equipment_and_samples_by_number(): void
{
    $operator = $this->userWithPermissions(['equipment_usage_records.create']);
    $equipment = Equipment::query()->create(['equipment_no' => 'EQ-SCAN-001', 'name' => '积分球', 'status' => 'active']);
    $sample = $this->sample('S-SCAN-001');

    $this->getJsonAs($operator, '/api/equipment-usage-records/lookup?type=equipment&code=EQ-SCAN-001')
        ->assertOk()
        ->assertJsonPath('data.id', $equipment->id)
        ->assertJsonPath('data.equipment_no', 'EQ-SCAN-001');

    $this->getJsonAs($operator, '/api/equipment-usage-records/lookup?type=sample&code=S-SCAN-001')
        ->assertOk()
        ->assertJsonPath('data.id', $sample->id)
        ->assertJsonPath('data.sample_no', 'S-SCAN-001');
}
```

- [ ] **Step 2: Add backend route**

```php
Route::get('/equipment-usage-records/lookup', [EquipmentUsageRecordController::class, 'lookup']);
```

Place it among the existing literal routes (`form-options`, `start`, `batch-end`) **before** the `apiResource('/equipment-usage-records', ...)` registration.

- [ ] **Step 3: Extract serializers, then implement lookup**

The controller currently inlines equipment/sample option shapes inside `formOptions()`. Extract two private serializers and reuse them in both `formOptions` and `lookup` (v1 referenced these methods but they did not exist):

```php
private function serializeEquipmentOption(Equipment $equipment): array
{
    return [
        'id' => $equipment->id,
        'equipment_no' => $equipment->equipment_no,
        'name' => $equipment->name,
        'model' => $equipment->model,
        'status' => $equipment->status,
        'calibration_date' => $equipment->calibration_date?->toDateString(),
    ];
}

private function serializeSampleOption(Sample $sample): array
{
    return [
        'id' => $sample->id,
        'sample_no' => $sample->sample_no,
        'sample_name' => $sample->sample_name,
        'model' => $sample->model,
        'status' => $sample->status,
    ];
}
```

Update `formOptions()` to map through these helpers, then add `lookup`:

```php
public function lookup(Request $request): JsonResponse
{
    $this->authorizePermission($request, 'equipment_usage_records.create', self::RESOURCE);

    $payload = $request->validate([
        'type' => ['required', 'in:equipment,sample'],
        'code' => ['required', 'string', 'max:255'],
    ]);

    if ($payload['type'] === 'equipment') {
        $equipment = Equipment::query()->where('equipment_no', $payload['code'])->firstOrFail();

        return response()->json(['data' => $this->serializeEquipmentOption($equipment)]);
    }

    $sample = Sample::query()->where('sample_no', $payload['code'])->firstOrFail();

    return response()->json(['data' => $this->serializeSampleOption($sample)]);
}
```

`firstOrFail()` returns 404 for an unknown code — the frontend must surface this as "未找到设备/样品" rather than crashing.

- [ ] **Step 4: Replace multi-select-only UX**

In `EquipmentUsageRecordPage`, keep the select boxes for mouse users and add scan/manual add panels:

```text
设备
[扫码/输入设备编号] [添加设备]
已选: EQ-001, EQ-002

样品
[扫码/输入样品编号] [添加样品]
已选: S-001, S-002
```

Use `QrScannerPanel` twice (one for equipment, one for sample). On add, call `/api/equipment-usage-records/lookup`, then append the resolved id to the selection; handle the 404 case inline.

- [ ] **Step 5: Prevent duplicate selected items**

In `equipmentUsageSchema.ts`, add:

```ts
export function uniqueNumberList(values: number[]) {
  return Array.from(new Set(values.filter((value) => Number.isFinite(value) && value > 0)))
}
```

- [ ] **Step 6: Run verification**

```bash
cd backend
php artisan test tests/Feature/Equipment/EquipmentUsageRecordTest.php

cd ../frontend
PATH=/Users/luang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:./node_modules/.bin:$PATH vitest run src/features/equipment/__tests__/equipment-usage-records.test.ts
```

Expected: equipment usage backend and frontend tests pass.

---

## Task 7: Calibration Projects

**Files:**

- Create: `backend/database/migrations/2026_06_12_010000_create_calibration_projects_table.php`
- Create: `backend/app/Models/CalibrationProject.php`
- Create: `backend/app/Http/Controllers/CalibrationProjectController.php`
- Create: `backend/app/Http/Controllers/CalibrationProjectLabelController.php`
- Modify: `backend/app/Services/Authorization/PermissionCatalog.php`
- Modify: `backend/tests/Feature/System/PermissionCatalogTest.php` (**extend `expectedPermissionNames()`** — exact-list assertion)
- Modify: `backend/database/seeders/CanonicalAcceptanceSeeder.php`
- Modify: `backend/routes/api.php`
- Create: `backend/tests/Feature/Equipment/CalibrationProjectTest.php`
- Create: `frontend/src/features/equipment/CalibrationProjectPage.tsx`
- Create: `frontend/src/features/equipment/CalibrationProjectLabelPrintArea.tsx`
- Modify: `frontend/src/app/routes.tsx`
- Modify: `frontend/src/components/app/navigation.ts`
- Modify: `frontend/src/features/system/groups/permissionNames.ts` (labels for `calibration_projects`, `calibration_project_labels`)
- Modify: `frontend/src/lib/zh.ts`

- [ ] **Step 1: Add migration**

```php
Schema::create('calibration_projects', function (Blueprint $table): void {
    $table->id();
    $table->string('project_no')->unique();
    $table->string('project_name');
    $table->string('status')->default('active');
    $table->unsignedInteger('sort_order')->default(0);
    $table->text('remark')->nullable();
    $table->timestamps();

    $table->index('status');
});
```

- [ ] **Step 2: Register permissions (catalog + exact-list test + labels)**

Add to `PermissionCatalog::resourceActions()`:

```php
'calibration_projects' => ['read', 'create', 'update', 'delete'],
'calibration_project_labels' => ['print'],
```

Add the matching strings to `PermissionCatalogTest::expectedPermissionNames()`:

```php
'calibration_projects.read',
'calibration_projects.create',
'calibration_projects.update',
'calibration_projects.delete',
'calibration_project_labels.print',
```

Add Chinese labels to `permissionNames.ts` (`calibration_projects` → '定标项目', `calibration_project_labels` → '定标项目标签'). The seeder picks the new permissions up automatically via `permissionNames()`.

- [ ] **Step 3: Add CRUD and label preview tests**

```php
public function test_manager_can_manage_calibration_projects_and_preview_labels(): void
{
    $manager = $this->userWithPermissions([
        'calibration_projects.read',
        'calibration_projects.create',
        'calibration_projects.update',
        'calibration_projects.delete',
        'calibration_project_labels.print',
    ]);

    $projectId = $this->postJsonAs($manager, '/api/calibration-projects', [
        'project_no' => 'CP-001',
        'project_name' => '积分球定标',
        'status' => 'active',
    ])->assertCreated()->json('data.id');

    $this->postJsonAs($manager, '/api/calibration-project-labels/preview', [
        'project_ids' => [$projectId],
        'label_width_mm' => 40,
        'label_height_mm' => 60,
    ])->assertOk()
        ->assertJsonPath('data.0.project_no', 'CP-001')
        ->assertJsonPath('data.0.qr_text', 'CP-001');
}
```

- [ ] **Step 4: Implement controllers**

Implement `index`, `store`, `update`, `destroy` with permission checks and audit logs. `destroy` should disable by setting `status = disabled` instead of hard-deleting. Implement `CalibrationProjectLabelController::preview` returning `project_no`, `project_name`, and `qr_text` per requested id with the label dimensions.

- [ ] **Step 5: Implement frontend page**

`CalibrationProjectPage` supports list, search, create/edit modal, disable, and label print preview. Add the nav item under 系统管理 gated on `calibration_projects.read`:

```tsx
{ label: '定标项目', to: '/system/calibration-projects', icon: Ruler, resource: 'calibration_projects', action: 'read' }
```

- [ ] **Step 6: Run tests**

```bash
cd backend
php artisan test tests/Feature/Equipment/CalibrationProjectTest.php tests/Feature/System/PermissionCatalogTest.php tests/Feature/Smoke/CanonicalAcceptanceSeederTest.php

cd ../frontend
PATH=/Users/luang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:./node_modules/.bin:$PATH vitest run src/features/system/groups/__tests__/permission-matrix.test.tsx src/components/app/__tests__/navigation.test.ts
```

Expected: calibration project, permission catalog, seeder, and navigation tests pass.

---

## Task 8: Device Calibration Backend

**Files:**

- Create: `backend/database/migrations/2026_06_12_020000_create_equipment_calibration_tables.php`
- Create: `backend/app/Models/EquipmentCalibration.php`
- Create: `backend/app/Models/EquipmentCalibrationDevice.php`
- Create: `backend/app/Models/EquipmentCalibrationStandard.php`
- Create: `backend/app/Http/Controllers/EquipmentCalibrationController.php`
- Create: `backend/tests/Feature/Equipment/EquipmentCalibrationTest.php`
- Modify: `backend/app/Services/Authorization/PermissionCatalog.php`
- Modify: `backend/tests/Feature/System/PermissionCatalogTest.php` (**extend `expectedPermissionNames()`**)
- Modify: `backend/database/seeders/CanonicalAcceptanceSeeder.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 1: Add migrations**

```php
Schema::create('equipment_calibrations', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('calibration_project_id')->nullable()->constrained('calibration_projects')->nullOnDelete();
    $table->string('calibration_name');
    $table->timestamp('calibration_time');
    $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('operator_name')->nullable();
    $table->string('result')->default('qualified');
    $table->text('remark')->nullable();
    $table->json('attachment_files')->nullable();
    $table->json('photo_files')->nullable();
    $table->timestamps();

    $table->index('calibration_time');
    $table->index('result');
});

Schema::create('equipment_calibration_devices', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('equipment_calibration_id')->constrained('equipment_calibrations')->cascadeOnDelete();
    $table->foreignId('equipment_id')->nullable()->constrained('equipment')->nullOnDelete();
    $table->string('equipment_no');
    $table->string('equipment_name');
    $table->string('equipment_model')->nullable();
    $table->date('calibration_date')->nullable();
    $table->text('remark')->nullable();
    $table->timestamps();
});

Schema::create('equipment_calibration_standards', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('equipment_calibration_id')->constrained('equipment_calibrations')->cascadeOnDelete();
    $table->foreignId('equipment_id')->nullable()->constrained('equipment')->nullOnDelete();
    $table->string('standard_no');
    $table->string('standard_name');
    $table->string('standard_model')->nullable();
    $table->date('calibration_date')->nullable();
    $table->text('remark')->nullable();
    $table->timestamps();
});
```

> The `equipment` table is singular (Laravel treats "equipment" as uncountable, and the existing `exists:equipment,id` rule confirms it), so `constrained('equipment')` is correct.

- [ ] **Step 2: Register permissions (catalog + field perms + exact-list test)**

Add to `PermissionCatalog::resourceActions()`:

```php
'equipment_calibrations' => ['read', 'create', 'update', 'delete'],
```

Add to `PermissionCatalog::fieldActions()` (column-matching keys):

```php
'equipment_calibrations' => [
    'attachment_files' => ['read', 'update'],
    'photo_files' => ['read', 'update'],
],
```

Add the corresponding strings to `PermissionCatalogTest::expectedPermissionNames()`:

```php
'equipment_calibrations.read',
'equipment_calibrations.create',
'equipment_calibrations.update',
'equipment_calibrations.delete',
'equipment_calibrations.field.attachment_files.read',
'equipment_calibrations.field.attachment_files.update',
'equipment_calibrations.field.photo_files.read',
'equipment_calibrations.field.photo_files.update',
```

(Chinese labels for `equipment_calibrations` and the two file fields are added in Task 9's `permissionNames.ts` change.)

- [ ] **Step 3: Add backend tests**

```php
public function test_manager_can_create_update_view_and_delete_equipment_calibration(): void
{
    $manager = $this->userWithPermissions([
        'equipment_calibrations.read',
        'equipment_calibrations.create',
        'equipment_calibrations.update',
        'equipment_calibrations.delete',
    ]);
    $equipment = Equipment::query()->create(['equipment_no' => 'EQ-CAL-001', 'name' => '积分球', 'model' => 'A1', 'status' => 'active']);
    $standard = Equipment::query()->create(['equipment_no' => 'STD-CAL-001', 'name' => '标准灯', 'model' => 'S1', 'status' => 'active']);

    $id = $this->postJsonAs($manager, '/api/equipment-calibrations', [
        'calibration_name' => '积分球定标',
        'calibration_time' => '2026-06-12 09:00:00',
        'result' => 'qualified',
        'devices' => [['equipment_id' => $equipment->id]],
        'standards' => [['equipment_id' => $standard->id]],
    ])->assertCreated()->json('data.id');

    $this->getJsonAs($manager, "/api/equipment-calibrations/{$id}")
        ->assertOk()
        ->assertJsonPath('data.devices.0.equipment_no', 'EQ-CAL-001')
        ->assertJsonPath('data.standards.0.standard_no', 'STD-CAL-001');
}
```

> `EquipmentCalibrationTest` is a new file — give it its own `userWithPermissions`/`postJsonAs`/`getJsonAs` helpers (copy from `EquipmentApiTest`).

- [ ] **Step 4: Implement controller**

Controller methods:

- `index`: filters `search`, `result`, `date_from`, `date_to`, pagination.
- `store`: validates project, time, result, devices, standards, files; snapshots `equipment_no`/`equipment_name`/`equipment_model` from the referenced equipment; creates rows in one transaction; writes an audit log.
- `show`: returns record with devices and standards.
- `update`: replaces child device/standard rows by submitted arrays in one transaction; writes an audit log.
- `destroy`: hard delete is acceptable only when no downstream official document references it; this project has none today, so delete plus audit log.

- [ ] **Step 5: Run backend tests**

```bash
cd backend
php artisan test tests/Feature/Equipment/EquipmentCalibrationTest.php tests/Feature/System/PermissionCatalogTest.php tests/Feature/Smoke/CanonicalAcceptanceSeederTest.php
```

Expected: calibration backend, permission catalog, and seeder tests pass.

---

## Task 9: Device Calibration Frontend

**Files:**

- Create: `frontend/src/features/equipment/EquipmentCalibrationListPage.tsx`
- Create: `frontend/src/features/equipment/EquipmentCalibrationFormPage.tsx`
- Create: `frontend/src/features/equipment/EquipmentCalibrationDetailPage.tsx`
- Create: `frontend/src/features/equipment/equipmentCalibrationSchema.ts`
- Create: `frontend/src/features/equipment/__tests__/equipment-calibration.test.ts`
- Modify: `frontend/src/app/routes.tsx`
- Modify: `frontend/src/components/app/navigation.ts`
- Modify: `frontend/src/features/system/groups/permissionNames.ts` (labels for `equipment_calibrations`, `attachment_files`, `photo_files`)
- Modify: `frontend/src/lib/zh.ts`

- [ ] **Step 1: Add schema tests**

```ts
it('builds an equipment calibration payload with device and standard rows', () => {
  expect(
    buildEquipmentCalibrationPayload({
      calibration_name: '积分球定标',
      calibration_time: '2026-06-12T09:00',
      result: 'qualified',
      devices: [{ equipment_id: 1, remark: 'device' }],
      standards: [{ equipment_id: 2, remark: 'standard' }],
      remark: '',
    }),
  ).toMatchObject({
    calibration_name: '积分球定标',
    result: 'qualified',
    devices: [{ equipment_id: 1, remark: 'device' }],
    standards: [{ equipment_id: 2, remark: 'standard' }],
  })
})
```

- [ ] **Step 2: Implement list page**

List columns:

- calibration time
- calibration name
- result
- operator
- device count
- standard count
- actions: view, edit, delete

- [ ] **Step 3: Implement form page**

Form sections:

```text
Basic
  calibration project, calibration name, calibration time, result, remark

Devices
  scan/manual equipment no, add row, remove row, row remark

Standards
  scan/manual equipment no, add row, remove row, row remark

Files
  attachment files, photo files
```

Use `QrScannerPanel` for device and standard lookup (resolve via the equipment usage lookup endpoint `type=equipment`, or an equipment lookup of your choice — reuse one endpoint, do not add a second).

- [ ] **Step 4: Implement detail page**

Detail sections:

- basic information
- devices
- standards
- attachments
- photos
- audit-friendly created/updated timestamps

- [ ] **Step 5: Add routes and navigation**

Routes:

```tsx
/equipment/calibrations
/equipment/calibrations/new
/equipment/calibrations/$calibrationId
/equipment/calibrations/$calibrationId/edit
```

Navigation (under 设备管理):

```tsx
{ label: '设备定标记录', to: '/equipment/calibrations', icon: ClipboardCheck, resource: 'equipment_calibrations', action: 'read' }
```

- [ ] **Step 6: Run frontend tests**

```bash
cd frontend
PATH=/Users/luang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:./node_modules/.bin:$PATH vitest run src/features/equipment/__tests__/equipment-calibration.test.ts src/components/app/__tests__/navigation.test.ts src/features/system/groups/__tests__/permission-matrix.test.tsx
```

Expected: calibration schema, navigation, and permission-matrix tests pass.

---

## Task 10: End-to-End Verification and Build

**Files:**

- Modify only files changed by earlier tasks.

- [ ] **Step 1: Run the full backend suite**

Because permission additions ripple into `PermissionCatalogTest` and `CanonicalAcceptanceSeederTest`, run the whole backend suite once, not just the touched files:

```bash
cd backend
php artisan test
```

Expected: the full suite is green. If you must scope down for speed during development, at minimum run:

```bash
php artisan test tests/Feature/Samples tests/Feature/Equipment tests/Feature/System tests/Feature/Smoke
```

- [ ] **Step 2: Run frontend targeted tests**

```bash
cd frontend
PATH=/Users/luang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:./node_modules/.bin:$PATH vitest run src/features/samples/__tests__ src/features/equipment/__tests__ src/components/app/__tests__/navigation.test.ts src/features/system/groups/__tests__/permission-matrix.test.tsx
```

Expected: all targeted frontend tests pass.

- [ ] **Step 3: Run static checks and production build**

```bash
cd frontend
PATH=/Users/luang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:./node_modules/.bin:$PATH tsc -b
PATH=/Users/luang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:./node_modules/.bin:$PATH vite build
PATH=/Users/luang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:./node_modules/.bin:$PATH vite build --config vite.backend.config.ts
```

Expected: TypeScript and both Vite builds succeed. A Vite large chunk warning is acceptable if the build exits 0.

- [ ] **Step 4: Run whitespace check**

```bash
cd /Users/luang/Downloads/new-lims
git diff --check
```

Expected: no whitespace errors.

- [ ] **Step 5: Manual browser smoke checks**

Run the app and verify:

```bash
cd frontend
PATH=/Users/luang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:./node_modules/.bin:$PATH npm run dev -- --host 127.0.0.1
```

Smoke checklist:

- `/samples` shows quick actions according to sample state and permissions.
- `/samples/scan` accepts a manual sample number, shows only the allowed actions, and surfaces a clear message for an unknown number or an unavailable action.
- `/samples/:id` prints a flow card with sample profile and timeline only, showing operator names.
- sample label print still prints only the sample number and QR code.
- `/equipment/usage-records` can add selected equipment and samples by manual code, with a clear "not found" message for unknown codes.
- `/equipment/calibrations` can create and view a calibration record.
- navigation hides pages without the matching permission.

Expected: all smoke checks pass without console errors blocking the workflow.

---

## Implementation Order

Use this order to keep each commit independently useful:

1. Sample flow service and backend state tests (behavior-preserving refactor).
2. Flow card and scan backend APIs (mind the `/samples/scan-lookup` route ordering).
3. Shared QR scanner component.
4. Sample flow-card print UI.
5. Sample scan page and sample list quick actions.
6. Equipment usage lookup (extract serializers) and scan/manual selection.
7. Calibration projects and project labels (catalog + exact-list test + labels).
8. Equipment calibration backend (catalog + field perms + exact-list test).
9. Equipment calibration frontend.
10. Full verification and build.

## Commit Plan

Use focused English commit messages:

```bash
git commit -m "Centralize sample flow state transitions"
git commit -m "Add sample flow card and scan APIs"
git commit -m "Add reusable QR scanner panel"
git commit -m "Add sample flow card printing"
git commit -m "Add sample scan operations"
git commit -m "Add equipment usage lookup selection"
git commit -m "Add calibration project management"
git commit -m "Add equipment calibration backend"
git commit -m "Add equipment calibration frontend"
```

Do not squash unrelated phases into one commit when intermediate tests pass.
