# Sample and Equipment Workflow Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Integrate the useful sample and equipment workflows from `example/` into the Laravel API and React SPA, including sample flow cards, scan-based sample operations, sample/equipment labels, equipment usage records, and device calibration records.

**Architecture:** Laravel owns state transitions, permissions, validation, audit logs, label/print preview data, and all database writes. React owns permission-aware navigation, scanning/manual-entry workflows, printable views, and ergonomic list/detail operations. Legacy PHP files are behavior references only; do not copy inline SQL, duplicated receive paths, debug leftovers, or mixed list/form page structure.

**Tech Stack:** Laravel 13, PHP 8.3+, Sanctum, Spatie permissions, MySQL/MariaDB, PHPUnit, React 19, TypeScript, Vite, TanStack Router, TanStack Query, Zod, Tailwind CSS, lucide-react, qrcode.react, html5-qrcode.

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
- `equipment_calibrations.field.attachments.read/update`
- `equipment_calibrations.field.photos.read/update`

Navigation rules:

- Add `扫码流转` only when `sample_flows.create` is granted.
- Keep `流转卡` as an action inside sample list/detail when `sample_flows.read` is granted.
- Add `设备定标记录` only when `equipment_calibrations.read` is granted.
- Add `定标项目` under system settings only when `calibration_projects.read` is granted.

---

## Task 1: Backend Sample Flow State Service

**Files:**

- Create: `backend/app/Services/Samples/SampleFlowService.php`
- Modify: `backend/app/Http/Controllers/SampleFlowController.php`
- Modify: `backend/tests/Feature/Samples/SampleFlowTest.php`

- [ ] **Step 1: Write backend tests for state transitions**

Add tests that prove each action updates the sample and writes an append-only flow.

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

- [ ] **Step 2: Add the service**

Implement one service that owns all sample state changes.

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
            $sample->refresh();
            $beforeHolder = $sample->current_holder;
            $beforeLocation = $sample->current_location;
            $updates = $this->updatesFor($sample, $data);

            $sample->update([
                ...$updates,
                'updated_by' => $user->id,
            ]);

            return $sample->flows()->create([
                'action_type' => $data['action_type'],
                'action_by' => $user->id,
                'action_time' => now(),
                'holder_from' => $beforeHolder,
                'holder_to' => $sample->fresh()->current_holder,
                'location_from' => $beforeLocation,
                'location_to' => $sample->fresh()->current_location,
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
            'lend' => $this->requirePendingInRoom($sample, [
                'status' => 'testing',
                'current_holder' => $this->requiredText($data['holder_to'] ?? null, 'holder_to'),
                'current_location' => $this->requiredText($data['location_to'] ?? null, 'location_to'),
            ]),
            'transfer' => $this->requireTestingOutsideRoom($sample, [
                'current_holder' => $this->requiredText($data['holder_to'] ?? null, 'holder_to'),
                'current_location' => $this->requiredText($data['location_to'] ?? null, 'location_to'),
            ]),
            'return_room' => $this->requireTestingOutsideRoom($sample, [
                'status' => 'pending',
                'current_holder' => '样品室',
                'current_location' => $this->requiredText($data['location_to'] ?? null, 'location_to'),
            ]),
            'send_out' => [
                'status' => 'outsourced',
                'current_holder' => $this->requiredText($data['holder_to'] ?? null, 'holder_to'),
                'current_location' => $data['location_to'] ?? $sample->current_location,
            ],
            'receive_back' => [
                'status' => 'outsource_returned',
                'current_holder' => '样品室',
                'current_location' => $this->requiredText($data['location_to'] ?? null, 'location_to'),
            ],
            'return_client' => [
                'status' => 'returned',
                'current_holder' => $data['holder_to'] ?? '客户',
                'current_location' => $data['location_to'] ?? $sample->current_location,
            ],
            'scrap' => [
                'status' => 'scrapped',
                'current_location' => $data['location_to'] ?? $sample->current_location,
            ],
            'position_change' => [
                'current_location' => $this->requiredText($data['location_to'] ?? null, 'location_to'),
            ],
            default => throw ValidationException::withMessages(['action_type' => ['invalid_sample_flow_action']]),
        };
    }

    /**
     * @param array<string, string|null> $updates
     * @return array<string, string|null>
     */
    private function requirePendingInRoom(Sample $sample, array $updates): array
    {
        if ($sample->status !== 'pending' || $sample->current_holder !== '样品室') {
            throw ValidationException::withMessages(['sample' => ['sample_not_available_for_lend']]);
        }

        return $updates;
    }

    /**
     * @param array<string, string|null> $updates
     * @return array<string, string|null>
     */
    private function requireTestingOutsideRoom(Sample $sample, array $updates): array
    {
        if ($sample->status !== 'testing' || $sample->current_holder === '样品室') {
            throw ValidationException::withMessages(['sample' => ['sample_not_available_for_transfer']]);
        }

        return $updates;
    }

    private function requiredText(?string $value, string $field): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            throw ValidationException::withMessages([$field => ["{$field}_required"]]);
        }

        return $text;
    }
}
```

- [ ] **Step 3: Refactor the controller to use the service**

In `SampleFlowController::store`, keep permission checks and validation, then call the service:

```php
$flow = $sampleFlowService->record($request->user(), $sample, $data);

return response()->json(['data' => $this->serializeFlow($flow)], 201);
```

- [ ] **Step 4: Run backend tests**

```bash
cd backend
php artisan test tests/Feature/Samples
```

Expected: all sample feature tests pass.

---

## Task 2: Backend Sample Flow Card and Scan APIs

**Files:**

- Create: `backend/app/Http/Controllers/SampleFlowCardController.php`
- Create: `backend/app/Http/Controllers/SampleScanController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/Samples/SampleFlowCardTest.php`
- Modify: `backend/tests/Feature/Samples/SampleScanTest.php`

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
        ->assertJsonPath('data.flows.0.action_type', 'receive');
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
```

- [ ] **Step 3: Implement `SampleFlowCardController`**

Return print-ready data. Use `samples.read` plus `sample_flows.read`.

```php
public function show(Request $request, Sample $sample): JsonResponse
{
    $this->authorizePermission($request, 'samples.read', 'samples', $sample);
    $this->authorizePermission($request, 'sample_flows.read', 'sample_flows', $sample);

    $sample->load('testOrder', 'flows');

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
                'action_time' => $flow->action_time?->format('Y-m-d H:i:s'),
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

`lookup` resolves a sample number and returns allowed actions. `store` reuses `SampleFlowService`.

```php
Route::get('/samples/scan-lookup', [SampleScanController::class, 'lookup']);
Route::post('/samples/{sample}/scan-flow', [SampleScanController::class, 'store']);
Route::get('/samples/{sample}/flow-card', [SampleFlowCardController::class, 'show']);
```

Allowed actions:

```php
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

- [ ] **Step 5: Run backend tests**

```bash
cd backend
php artisan test tests/Feature/Samples
```

Expected: sample flow, flow card, scan lookup, and receive tests pass.

---

## Task 3: Frontend Shared QR Scanner Component

**Files:**

- Modify: `frontend/package.json`
- Modify: `frontend/package-lock.json`
- Create: `frontend/src/components/app/QrScannerPanel.tsx`
- Create: `frontend/src/components/app/__tests__/qr-scanner-panel.test.tsx`

- [ ] **Step 1: Add dependency**

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
        flows: [{ id: 1, action_type: 'receive', action_time: '2026-06-12T00:00:00.000Z', holder_to: '样品室', location_to: '样品室' }],
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

Add a query for `/api/samples/{sampleId}/flow-card` and a `打印流转卡` button. The button must be wrapped in:

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

```ts
export const sampleScanFlowSchema = z.object({
  action_type: z.enum(['lend', 'transfer', 'return_room', 'receive_back']),
  holder_to: z.string().optional(),
  location_to: z.string().min(1, '请选择位置名称'),
  remark: z.string().optional(),
})
```

- [ ] **Step 3: Implement page**

`SampleScanPage` uses `QrScannerPanel`, calls `/api/samples/scan-lookup`, shows sample profile, renders allowed action buttons, location select, holder input when required, remark, and confirm button.

Manual and camera flow:

```text
scan/manual code
  -> GET /api/samples/scan-lookup?sample_no=...
  -> select action
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

Navigation:

```tsx
{ label: '扫码流转', to: '/samples/scan', icon: ScanLine, resource: 'sample_flows', action: 'create' }
```

- [ ] **Step 5: Add sample list quick actions**

In `SampleListPage`, add:

- `领样` when `sample.status === 'pending' && sample.current_holder === '样品室'`.
- `流转` and `归还` when `sample.status === 'testing' && sample.current_holder !== '样品室'`.
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

Place it before the `apiResource('/equipment-usage-records', ...)` route.

- [ ] **Step 3: Implement lookup**

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

- [ ] **Step 4: Replace multi-select-only UX**

In `EquipmentUsageRecordPage`, keep select boxes for mouse users and add scan/manual add panels:

```text
设备
[扫码/输入设备编号] [添加设备]
已选: EQ-001, EQ-002

样品
[扫码/输入样品编号] [添加样品]
已选: S-001, S-002
```

Use `QrScannerPanel` twice, one for equipment, one for sample.

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
- Modify: `backend/database/seeders/CanonicalAcceptanceSeeder.php`
- Modify: `backend/routes/api.php`
- Create: `backend/tests/Feature/Equipment/CalibrationProjectTest.php`
- Create: `frontend/src/features/equipment/CalibrationProjectPage.tsx`
- Create: `frontend/src/features/equipment/CalibrationProjectLabelPrintArea.tsx`
- Modify: `frontend/src/app/routes.tsx`
- Modify: `frontend/src/components/app/navigation.ts`
- Modify: `frontend/src/features/system/groups/permissionNames.ts`
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

- [ ] **Step 2: Add CRUD and label preview tests**

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

- [ ] **Step 3: Implement controller**

Implement `index`, `store`, `update`, `destroy` with permission checks and audit logs. `destroy` should disable by setting `status = disabled` instead of hard-deleting.

- [ ] **Step 4: Implement frontend page**

`CalibrationProjectPage` supports list, search, create/edit modal, disable, and label print preview.

- [ ] **Step 5: Run tests**

```bash
cd backend
php artisan test tests/Feature/Equipment/CalibrationProjectTest.php tests/Feature/System/PermissionCatalogTest.php

cd ../frontend
PATH=/Users/luang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:./node_modules/.bin:$PATH vitest run src/features/system/groups/__tests__/permission-matrix.test.tsx src/components/app/__tests__/navigation.test.ts
```

Expected: calibration project, permission catalog, and navigation tests pass.

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

- [ ] **Step 2: Add backend tests**

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

- [ ] **Step 3: Implement controller**

Controller methods:

- `index`: filters `search`, `result`, `date_from`, `date_to`, pagination.
- `store`: validates project, time, result, devices, standards, files; creates rows in one transaction.
- `show`: returns record with devices and standards.
- `update`: replaces child device/standard rows by submitted arrays in one transaction.
- `destroy`: hard delete is acceptable only when no downstream official document references it; otherwise disable with a `status` column. This project has no downstream references now, so use delete plus audit.

- [ ] **Step 4: Run backend tests**

```bash
cd backend
php artisan test tests/Feature/Equipment/EquipmentCalibrationTest.php tests/Feature/System/PermissionCatalogTest.php
```

Expected: calibration backend and permission catalog tests pass.

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
- Modify: `frontend/src/features/system/groups/permissionNames.ts`
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

Use `QrScannerPanel` for device and standard lookup.

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

Navigation:

```tsx
{ label: '设备定标记录', to: '/equipment/calibrations', icon: ClipboardCheck, resource: 'equipment_calibrations', action: 'read' }
```

- [ ] **Step 6: Run frontend tests**

```bash
cd frontend
PATH=/Users/luang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:./node_modules/.bin:$PATH vitest run src/features/equipment/__tests__/equipment-calibration.test.ts src/components/app/__tests__/navigation.test.ts
```

Expected: calibration schema and navigation tests pass.

---

## Task 10: End-to-End Verification and Build

**Files:**

- Modify only files changed by earlier tasks.

- [ ] **Step 1: Run backend targeted tests**

```bash
cd backend
php artisan test tests/Feature/Samples tests/Feature/Equipment tests/Feature/System/PermissionCatalogTest.php
```

Expected: all targeted feature tests pass.

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
- `/samples/scan` accepts manual sample number and shows allowed actions.
- `/samples/:id` prints a flow card with sample profile and timeline only.
- sample label print still prints only sample number and QR code.
- `/equipment/usage-records` can add selected equipment and samples by manual code.
- `/equipment/calibrations` can create and view a calibration record.
- navigation hides pages without the matching permission.

Expected: all smoke checks pass without console errors blocking the workflow.

---

## Implementation Order

Use this order to keep each commit independently useful:

1. Sample flow service and backend state tests.
2. Flow card and scan backend APIs.
3. Shared QR scanner component.
4. Sample flow-card print UI.
5. Sample scan page and sample list quick actions.
6. Equipment usage lookup and scan/manual selection.
7. Calibration projects and project labels.
8. Equipment calibration backend.
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
