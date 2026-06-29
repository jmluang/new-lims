# Test Order Entrust PDF Adaptation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adapt the legacy entrust order form represented by `委托单.doc` into the current Laravel API, React SPA, and Java PDF renderer without copying the old `zs-lims` entity or UI architecture.

**Architecture:** Keep `test_orders` as the single source of truth for official entrust/test orders. Extend the current `test_orders` and `test_order_samples` schema only where the document has real missing data, then add a focused Laravel payload builder and PDF download endpoint that call the existing Java `POST /api/pdf/entrust-order` renderer. React adds fields and print/download actions inside the existing test order pages, with permission gating through the current catalog.

**Tech Stack:** Laravel 13, PHP 8.3+, Sanctum, Spatie permissions, MySQL/MariaDB, PHPUnit, Guzzle, React 19, TypeScript, Vite, TanStack Router, TanStack Query, React Hook Form, Zod, Tailwind CSS, lucide-react, Java Spring Boot, PDFBox.

---

## Evidence Summary

### Source Document

`委托单.doc` is a Word 97-2003 document with two pages. Text extraction shows these business sections:

- Base: entrust date, urgency, planned end date, entrust number, contract number.
- Parties: client, manufacturer, producer; each has company, contact, phone, address, email.
- Requirements: standards, qualification requirements, report language, report forms, sample return, report submission, subcontract permission, remarks.
- Samples: repeated sample blocks with name, model, rated voltage, rated current, rated power, rated frequency, quantity, condition, remarks.
- Logistics: laboratory name, address, contact, phone, special shipping notes.
- Confirmation: client declaration/signature and internal lab confirmations.

The converted `.docx` has no parseable Word tables, so implementation must treat the file as a field/layout reference, not as a fillable Word template.

### Legacy Reference

Use `zs-lims` as a behavior reference only:

- Legacy model/table: `/Users/luang/Downloads/zs-lims/app/Models/EntrustOrder.php`, `/Users/luang/Downloads/zs-lims/database/migrations/2025_09_20_180556_create_entrust_orders_table.php`.
- Legacy export flow: `/Users/luang/Downloads/zs-lims/app/Services/EntrustOrderPdfExporter.php` builds `base/client/manufacturer/producer/requirements/sample/logistics/signatures/meta` and posts JSON to Java `POST /api/pdf/entrust-order`.
- Legacy UI flow: Filament page + Blade + Vue + `/admin/api/entrust-orders/{id}/export`.

Do not copy these implementation details:

- Do not add a separate `entrust_orders` entity in `new-lims`.
- Do not add a new top-level menu.
- Do not copy Filament/Blade/Vue route patterns.
- Do not copy `protected $guarded = []`, frontend-generated order numbers, or old mini program routes.
- Do not keep the legacy ambiguous `client_signature_name` meaning "signature image URL" in new Laravel-facing code.
- Do not rely on Java-side hardcoded form fallbacks; defaults must come from Laravel payload mapping or remain blank.

### Current New-LIMS State

Existing pieces to reuse:

- `backend/app/Models/TestOrder.php`
- `backend/app/Models/TestOrderSample.php`
- `backend/app/Models/TestOrderStandard.php`
- `backend/app/Http/Controllers/TestOrderController.php`
- `backend/app/Services/TestOrders/SyncTestOrderChildren.php`
- `backend/app/Services/Pdf/PdfRendererClient.php::renderEntrustOrder()`
- `services/pdf-renderer-java/src/main/java/com/luang/pdfsigner/dto/EntrustOrderPayload.java`
- `services/pdf-renderer-java/src/main/java/com/luang/pdfsigner/service/EntrustOrderRenderer.java`
- `frontend/src/features/test-orders/TestOrderListPage.tsx`
- `frontend/src/features/test-orders/TestOrderDetailPage.tsx`
- `frontend/src/features/test-orders/TestOrderForm.tsx`
- `frontend/src/features/test-orders/testOrderSchema.ts`

## Field Mapping

| Document field | Current field | Required change |
| --- | --- | --- |
| 委托日期 | `test_orders.order_date` | Reuse |
| 紧急程度 | `test_orders.urgency` | Reuse; map `normal`, `urgent`, and existing `critical` to PDF checkboxes |
| 计划结束时间 | `test_orders.planned_end_date` | Reuse |
| 委托编号 | `test_orders.order_no` | Reuse |
| 合同编号 | `test_orders.contract_no` | Reuse |
| 委托单位 company/address/contact/phone | `test_orders.client_*` | Reuse |
| 委托单位 email | missing | Add `client_email` |
| 制造商 company/address/contact/phone | `test_orders.manufacturer_*` | Reuse |
| 制造商 email | missing | Add `manufacturer_email` |
| 生产厂 company/address/contact/phone | `test_orders.maker_*` | Reuse as producer |
| 生产厂 email | missing | Add `maker_email` |
| 标准号及版本 | `test_order_standards.standard_code` + `standard_name` | Reuse; combine for PDF |
| 资质要求 | `test_order_standards.qualifications` | Reuse; join with comma |
| 报告语言 | `test_order_standards.report_language` | Reuse; map `zh/en` to labels |
| 报告形式 | `test_orders.report_forms` | Reuse; add `paper_report`; payload builder must label both legacy `electronic`/`paper` and new `electronic_report`/`paper_report` values |
| 样品是否返还 | missing | Add `sample_return` |
| 报告提交 | `test_orders.delivery_method` | Reuse |
| 准许检测分包 | `test_orders.outsourcing_option` | Reuse |
| 备注 | `test_orders.remark` | Reuse |
| 样品名称 | `test_order_samples.sample_name` | Reuse |
| 型号 | `test_order_samples.model` | Reuse |
| 额定电压 | `test_order_samples.input_voltage` | Reuse |
| 额定电流 | missing | Add `rated_current` |
| 额定功率 | `test_order_samples.power` | Reuse |
| 额定频率 | missing | Add `rated_frequency` |
| 样品数量 | `test_order_samples.quantity` | Reuse |
| 样品单位 | missing | Add `quantity_unit` |
| 样品状态 | current `status` is workflow state | Add `sample_condition` and `sample_condition_note` for document condition; Java DTO may still expose these as `condition`/`condition_note` |
| 样品备注 | `test_order_samples.remark` | Reuse |
| 实验室名称 | `test_orders.address_lab_name` | Reuse |
| 实验室地址 | `test_orders.address_detail` | Reuse |
| 联系人 | `test_orders.address_contact` | Reuse |
| 联系电话 | `test_orders.address_phone` | Reuse |
| 特别说明 | missing | Add `shipping_notes` |
| 委托人签字 | `test_orders.client_signature` | Reuse as text/name initially |
| 客户签字日期 | `test_orders.client_sign_date` | Reuse |
| 综合部确认 | `test_orders.dept_confirm` | Reuse |
| 综合部确认日期 | `test_orders.dept_confirm_date` | Reuse |
| 检测部确认 | `test_orders.lab_confirm` | Reuse |
| 检测部确认日期 | `test_orders.lab_confirm_date` | Reuse |

## Target UX

```text
业务管理 / 委托试验单
----------------------------------------------------------------------
[搜索] [样品状态 v] [委托开始] [委托结束]              [导出] [新建委托单]

委托编号       委托单位              委托日期     样品状态       操作
26000015738    中山市铭宜镁...       2026-05-08  未接收         [查看] [编辑] [打印] [推送] [删除]
----------------------------------------------------------------------

委托单详情
----------------------------------------------------------------------
[返回列表]                                             [编辑] [打印委托单]

委托单信息
委托编号: 26000015738   合同编号: 26000015738   委托日期: 2026-05-08

样品信息
名称              型号       电压    电流    功率    频率    数量    状态
LED模组路灯头     MYM-300    220V    1.3A    300W    50Hz    1 个    完好
LED模组天花灯头   MYM-300    220V    1.3A    300W    50Hz    1 个    完好
```

## File Structure

### Backend

- Create `backend/database/migrations/2026_06_28_000200_add_entrust_print_fields_to_test_orders.php`
  - Adds email/sample return/shipping notes fields to `test_orders`.
  - Adds document-specific sample electrical fields and `sample_condition` fields to `test_order_samples`.
- Modify `backend/app/Models/TestOrder.php`
  - Add fillable/casts where needed.
- Modify `backend/app/Models/TestOrderSample.php`
  - Add fillable fields.
- Modify `backend/app/Http/Controllers/TestOrderController.php`
  - Accept, serialize, audit, and expose the new fields.
- Modify `backend/app/Services/TestOrders/SyncTestOrderChildren.php`
  - Persist new sample fields.
- Create `backend/app/Services/TestOrders/BuildEntrustOrderPdfPayload.php`
  - Owns all `TestOrder` to Java DTO mapping.
- Create `backend/app/Http/Controllers/TestOrderEntrustOrderController.php`
  - Owns the PDF download endpoint.
- Modify `backend/routes/api.php`
  - Add `GET /test-orders/{testOrder}/entrust-order.pdf`.
- Modify `backend/app/Services/Authorization/PermissionCatalog.php`
  - Add `test_orders.print`.
- Modify `backend/database/seeders/CanonicalAcceptanceSeeder.php`
  - Grant print permission to the test order manager role if that role already gets export/notify.
- Add/modify backend tests under `backend/tests/Feature/TestOrders/` and `backend/tests/Feature/System/`.

### Java PDF Renderer

- Modify `services/pdf-renderer-java/src/main/java/com/luang/pdfsigner/dto/EntrustOrderPayload.java`
  - Add `List<Sample> samples`.
  - Keep `Sample sample` temporarily for backward compatibility.
- Modify `services/pdf-renderer-java/src/main/java/com/luang/pdfsigner/service/EntrustOrderRenderer.java`
  - Render all samples.
  - Remove hardcoded product/model/voltage/frequency, standards, logistics, and signature-placeholder fallbacks.
  - Introduce a `PageCursor` or equivalent helper that owns the active page/content stream and page breaks; do not reassign a `PDPageContentStream` created by a try-with-resources block.
  - Treat non-URL client signatures as plain text instead of trying to fetch them as images.
- Modify `services/pdf-renderer-java/src/test/java/com/luang/pdfsigner/service/EntrustOrderRendererTest.java`
  - Cover multi-sample rendering and no fallback leakage.

### Frontend

- Modify `frontend/src/features/test-orders/TestOrderListPage.tsx`
  - Add fields to types.
  - Add row-level print button.
- Modify `frontend/src/features/test-orders/TestOrderDetailPage.tsx`
  - Add detail display for new fields.
  - Add detail-level print action.
- Modify `frontend/src/features/test-orders/TestOrderForm.tsx`
  - Add email, sample return, shipping notes, and sample document condition fields.
- Modify `frontend/src/features/test-orders/testOrderSchema.ts`
  - Validate/normalize new fields.
- Modify `frontend/src/lib/zh.ts`
  - Add labels/status text.
- Modify `frontend/src/features/system/groups/permissionNames.ts`
  - Add `print` label if not already globally covered and ensure `test_orders` remains understandable.
- Add/modify tests under `frontend/src/features/test-orders/__tests__/`.

---

## Task 1: Extend The Test Order Data Contract

**Files:**

- Create: `backend/database/migrations/2026_06_28_000200_add_entrust_print_fields_to_test_orders.php`
- Modify: `backend/app/Models/TestOrder.php`
- Modify: `backend/app/Models/TestOrderSample.php`
- Modify: `backend/app/Http/Controllers/TestOrderController.php`
- Modify: `backend/app/Services/TestOrders/SyncTestOrderChildren.php`
- Test: `backend/tests/Feature/TestOrders/TestOrderApiTest.php`

- [ ] **Step 1: Write failing backend coverage for new fields**

Add assertions to `TestOrderApiTest::test_can_create_show_filter_and_export_test_order_with_child_rows()` payload and response.

```php
'client_email' => 'client@example.test',
'manufacturer_email' => 'manufacturer@example.test',
'maker_email' => 'maker@example.test',
'sample_return' => 'return',
'shipping_notes' => 'Please keep original packaging.',
```

Add these sample keys to the first sample payload:

```php
'rated_current' => '1.3A',
'rated_frequency' => '50Hz',
'quantity_unit' => '个',
'sample_condition' => 'good',
'sample_condition_note' => null,
```

Expected response assertions:

```php
->assertJsonPath('data.client_email', 'client@example.test')
->assertJsonPath('data.sample_return', 'return')
->assertJsonPath('data.shipping_notes', 'Please keep original packaging.')
->assertJsonPath('data.samples.0.rated_current', '1.3A')
->assertJsonPath('data.samples.0.rated_frequency', '50Hz')
->assertJsonPath('data.samples.0.quantity_unit', '个')
->assertJsonPath('data.samples.0.sample_condition', 'good');
```

Run:

```bash
cd backend
/usr/local/bin/php artisan test tests/Feature/TestOrders/TestOrderApiTest.php --filter=test_can_create_show_filter_and_export_test_order_with_child_rows
```

Expected: FAIL because columns/validation/serialization do not exist yet.

- [ ] **Step 2: Add migration**

Create `backend/database/migrations/2026_06_28_000200_add_entrust_print_fields_to_test_orders.php`.

Use `sample_condition` and `sample_condition_note` as physical column names. Do not name the column `condition`; it is too easy to confuse with raw SQL condition clauses and the existing workflow `status`. The Java DTO can still receive `condition` because that boundary is print-payload-only.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_orders', function (Blueprint $table): void {
            $table->string('client_email')->nullable()->after('client_phone');
            $table->string('manufacturer_email')->nullable()->after('manufacturer_phone');
            $table->string('maker_email')->nullable()->after('maker_phone');
            $table->string('sample_return')->nullable()->after('report_forms');
            $table->text('shipping_notes')->nullable()->after('address_phone');
        });

        Schema::table('test_order_samples', function (Blueprint $table): void {
            $table->string('rated_current')->nullable()->after('input_voltage');
            $table->string('rated_frequency')->nullable()->after('power');
            $table->string('quantity_unit')->nullable()->after('quantity');
            $table->string('sample_condition')->nullable()->after('status');
            $table->text('sample_condition_note')->nullable()->after('sample_condition');
        });
    }

    public function down(): void
    {
        Schema::table('test_order_samples', function (Blueprint $table): void {
            $table->dropColumn([
                'rated_current',
                'rated_frequency',
                'quantity_unit',
                'sample_condition',
                'sample_condition_note',
            ]);
        });

        Schema::table('test_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'client_email',
                'manufacturer_email',
                'maker_email',
                'sample_return',
                'shipping_notes',
            ]);
        });
    }
};
```

- [ ] **Step 3: Update models**

Add these fields to `TestOrder` fillable:

```php
'client_email',
'manufacturer_email',
'maker_email',
'sample_return',
'shipping_notes',
```

Add these fields to `TestOrderSample` fillable:

```php
'rated_current',
'rated_frequency',
'quantity_unit',
'sample_condition',
'sample_condition_note',
```

- [ ] **Step 4: Update controller validation and serialization**

In `TestOrderController::rules()` add:

```php
'client_email' => ['nullable', 'email', 'max:255'],
'manufacturer_email' => ['nullable', 'email', 'max:255'],
'maker_email' => ['nullable', 'email', 'max:255'],
'sample_return' => ['nullable', 'in:return,destroy'],
'shipping_notes' => ['nullable', 'string'],
'samples.*.rated_current' => ['nullable', 'string', 'max:255'],
'samples.*.rated_frequency' => ['nullable', 'string', 'max:255'],
'samples.*.quantity_unit' => ['nullable', 'string', 'max:32'],
'samples.*.sample_condition' => ['nullable', 'in:good,abnormal'],
'samples.*.sample_condition_note' => ['nullable', 'string'],
```

In `TestOrderController::serializeOrder()` include the same fields in the order and sample arrays.

In `TestOrderController::applyCustomerSnapshots()`, copy the customer email when an ID is selected:

```php
$payload["{$prefix}_email"] ??= $customer->email;
```

- [ ] **Step 5: Update child sync service**

In `SyncTestOrderChildren::syncSamples()`, include:

```php
'rated_current' => $row['rated_current'] ?? null,
'rated_frequency' => $row['rated_frequency'] ?? null,
'quantity_unit' => $row['quantity_unit'] ?? null,
'sample_condition' => $row['sample_condition'] ?? null,
'sample_condition_note' => $row['sample_condition_note'] ?? null,
```

- [ ] **Step 6: Run focused backend test**

```bash
cd backend
/usr/local/bin/php artisan test tests/Feature/TestOrders/TestOrderApiTest.php
```

Expected: PASS.

---

## Task 2: Add Permission And Backend PDF Endpoint

**Files:**

- Modify: `backend/app/Services/Authorization/PermissionCatalog.php`
- Modify: `backend/database/seeders/CanonicalAcceptanceSeeder.php`
- Modify: `backend/routes/api.php`
- Create: `backend/app/Services/TestOrders/BuildEntrustOrderPdfPayload.php`
- Create: `backend/app/Http/Controllers/TestOrderEntrustOrderController.php`
- Test: `backend/tests/Feature/TestOrders/TestOrderEntrustOrderPdfTest.php`
- Test: `backend/tests/Feature/System/PermissionCatalogTest.php`

- [ ] **Step 1: Write failing permission catalog assertion**

Update `PermissionCatalogTest` expected actions:

```php
->assertJsonPath('data.resources.test_orders.actions', ['read', 'create', 'update', 'delete', 'export', 'notify', 'print'])
```

Add `test_orders.print` to the expected permission names list.

Run:

```bash
cd backend
/usr/local/bin/php artisan test tests/Feature/System/PermissionCatalogTest.php
```

Expected: FAIL until catalog is updated.

- [ ] **Step 2: Add `test_orders.print` permission**

In `PermissionCatalog::resourceActions()` change:

```php
'test_orders' => ['read', 'create', 'update', 'delete', 'export', 'notify', 'print'],
```

In `CanonicalAcceptanceSeeder`, include `test_orders.print` wherever the test order manager receives `test_orders.export` and `test_orders.notify`.

- [ ] **Step 3: Add PDF payload builder tests**

Create `backend/tests/Feature/TestOrders/TestOrderEntrustOrderPdfTest.php` with these scenarios:

```php
public function test_pdf_endpoint_requires_print_permission(): void
{
    $viewer = $this->userWithPermissions(['test_orders.read']);
    $order = $this->createCompleteTestOrder();

    Sanctum::actingAs($viewer);

    $this->getJson("/api/test-orders/{$order->id}/entrust-order.pdf")
        ->assertForbidden();
}

public function test_pdf_endpoint_maps_test_order_to_renderer_payload_and_returns_pdf(): void
{
    $printer = $this->userWithPermissions(['test_orders.read', 'test_orders.print']);
    $order = $this->createCompleteTestOrder();

    $client = Mockery::mock(\App\Services\Pdf\PdfRendererClient::class);
    $client->shouldReceive('renderEntrustOrder')
        ->once()
        ->with(Mockery::on(function (array $payload) use ($order): bool {
            return $payload['base']['entrust_number'] === $order->order_no
                && $payload['client']['company_name'] === '中山市铭宜镁照明科技有限公司'
                && collect($payload['base']['urgency_options'])->contains(fn (array $option): bool => $option['value'] === 'critical' && $option['label'] === '特急')
                && $payload['requirements']['sample_return']['value'] === 'return'
                && collect($payload['requirements']['report_forms'])->contains(fn (array $option): bool => $option['value'] === 'electronic' && $option['label'] === '电子档')
                && collect($payload['requirements']['report_forms'])->contains(fn (array $option): bool => $option['value'] === 'paper' && $option['label'] === '纸本')
                && count($payload['samples']) === 2
                && $payload['samples'][0]['current'] === '1.3A'
                && $payload['samples'][0]['frequency'] === '50Hz';
        }))
        ->andReturn('%PDF-1.4 fake pdf');
    $this->app->instance(\App\Services\Pdf\PdfRendererClient::class, $client);

    Sanctum::actingAs($printer);

    $this->get("/api/test-orders/{$order->id}/entrust-order.pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'attachment; filename=entrust-order-'.$order->order_no.'.pdf')
        ->assertSee('%PDF-1.4 fake pdf', false);
}

public function test_pdf_endpoint_returns_bad_gateway_when_renderer_fails(): void
{
    $printer = $this->userWithPermissions(['test_orders.read', 'test_orders.print']);
    $order = $this->createCompleteTestOrder();

    $client = Mockery::mock(\App\Services\Pdf\PdfRendererClient::class);
    $client->shouldReceive('renderEntrustOrder')->andThrow(new RuntimeException('PDF service returned HTTP 500.'));
    $this->app->instance(\App\Services\Pdf\PdfRendererClient::class, $client);

    Sanctum::actingAs($printer);

    $this->getJson("/api/test-orders/{$order->id}/entrust-order.pdf")
        ->assertStatus(502)
        ->assertJsonPath('message', 'Unable to generate entrust order PDF.');
}
```

Add `createCompleteTestOrder()` in the same test file by following the explicit model creation pattern from `TestOrderApiTest::payload()`: create a `Customer`, create a `Standard`, then create a `TestOrder` through `TestOrder::query()->create([...])` and related `TestOrderStandard::query()->create([...])` / `TestOrderSample::query()->create([...])` rows. Do not use an Eloquent factory for `TestOrder` because this repository currently only has `UserFactory`.

- [ ] **Step 4: Implement payload builder**

Create `BuildEntrustOrderPdfPayload` with one public method:

```php
namespace App\Services\TestOrders;

use App\Models\TestOrder;

class BuildEntrustOrderPdfPayload
{
    public function build(TestOrder $order): array
    {
        $order->loadMissing(['standards', 'samples']);

        return [
            'base' => [
                'entrust_date' => $order->order_date?->toDateString(),
                'urgency' => $this->enumValue($order->urgency, $this->urgencyLabel($order->urgency)),
                'urgency_options' => [
                    $this->enumValue('normal', '常规'),
                    $this->enumValue('urgent', '加急'),
                    $this->enumValue('critical', '特急'),
                ],
                'planned_end_date' => $order->planned_end_date?->toDateString(),
                'entrust_number' => $order->order_no,
                'contract_number' => $order->contract_no,
            ],
            'client' => $this->party($order, 'client'),
            'manufacturer' => $this->party($order, 'manufacturer'),
            'producer' => $this->party($order, 'maker'),
            'requirements' => [
                'report_forms' => collect($order->report_forms ?? [])->map(fn (string $value): array => $this->enumValue($value, $this->reportFormLabel($value)))->values()->all(),
                'report_form_options' => [
                    $this->enumValue('electronic', '电子档'),
                    $this->enumValue('paper', '纸本'),
                    $this->enumValue('electronic_report', '电子档'),
                    $this->enumValue('paper_report', '纸本'),
                    $this->enumValue('formal_report', '正式报告'),
                    $this->enumValue('simple_report', '简版报告'),
                    $this->enumValue('english_report', '英文报告'),
                ],
                'sample_return' => $this->enumValue($order->sample_return, $this->sampleReturnLabel($order->sample_return)),
                'sample_return_options' => [
                    $this->enumValue('return', '是'),
                    $this->enumValue('destroy', '否（销毁处理）'),
                ],
                'report_submission' => $this->enumValue($order->delivery_method, $this->deliveryMethodLabel($order->delivery_method)),
                'report_submission_options' => [
                    $this->enumValue('self_pick', '自取'),
                    $this->enumValue('mail', '邮寄'),
                ],
                'allow_subcontract' => $this->enumValue($order->outsourcing_option, $this->outsourcingLabel($order->outsourcing_option)),
                'allow_subcontract_options' => [
                    $this->enumValue('allowed', '允许'),
                    $this->enumValue('not_allowed', '不允许'),
                ],
                'remarks' => $order->remark,
                'standards' => $order->standards->map(fn ($standard, int $index): array => [
                    'standard_code' => trim($standard->standard_code.' '.$standard->standard_name),
                    'qualification_requirement' => collect($standard->qualifications ?? [])->filter()->implode(','),
                    'report_language' => $this->reportLanguageLabel($standard->report_language),
                    'notes' => null,
                    'position' => $standard->sort_order ?? $index,
                ])->values()->all(),
            ],
            'samples' => $order->samples->map(fn ($sample): array => [
                'name' => $sample->sample_name,
                'model' => $sample->model,
                'voltage' => $sample->input_voltage,
                'current' => $sample->rated_current,
                'power' => $sample->power,
                'frequency' => $sample->rated_frequency,
                'quantity' => $sample->quantity,
                'quantity_unit' => $sample->quantity_unit,
                'condition' => $this->enumValue($sample->sample_condition, $this->sampleConditionLabel($sample->sample_condition)),
                'condition_note' => $sample->sample_condition_note,
                'remarks' => $sample->remark,
            ])->values()->all(),
            'logistics' => [
                'laboratory_name' => $order->address_lab_name,
                'laboratory_address' => $order->address_detail,
                'laboratory_contact' => $order->address_contact,
                'laboratory_phone' => $order->address_phone,
                'shipping_notes' => $order->shipping_notes,
            ],
            'signatures' => [
                'client_signature_name' => $order->client_signature,
                'client_signed_at' => $order->client_sign_date?->toDateString(),
                'lab_resource_confirmed_by' => $order->dept_confirm,
                'lab_resource_confirmed_at' => $order->dept_confirm_date?->toDateString(),
                'lab_reviewed_by' => $order->lab_confirm,
                'lab_reviewed_at' => $order->lab_confirm_date?->toDateString(),
            ],
            'meta' => [
                'status' => $this->enumValue($order->sample_status, $order->sample_status),
                'generated_at' => now()->toDateTimeString(),
            ],
        ];
    }

    private function party(TestOrder $order, string $prefix): array
    {
        return [
            'company_name' => $order->getAttribute("{$prefix}_company"),
            'contact' => $order->getAttribute("{$prefix}_contact"),
            'phone' => $order->getAttribute("{$prefix}_phone"),
            'address' => $order->getAttribute("{$prefix}_address"),
            'email' => $order->getAttribute("{$prefix}_email"),
        ];
    }

    private function enumValue(?string $value, ?string $label): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ['value' => $value, 'label' => $label ?: $value];
    }
}
```

Add private label helper methods in the same class for urgency, report form, delivery, outsourcing, language, return, and sample condition. `urgencyLabel()` must cover `normal`, `urgent`, and existing `critical`. `reportFormLabel()` must cover both legacy values (`electronic`, `paper`) and current frontend values (`electronic_report`, `paper_report`, `formal_report`, `simple_report`, `english_report`). Keep the label mapping local to this payload builder so the backend API contract remains stable and the Java renderer receives human-readable labels.

Do not pass `TestOrderStandard::requirement` into Java `notes` until the renderer has a separate wrapped requirement area. The current document cell is for standard number/name plus qualification/language; flattening multi-line requirements into `notes` would crowd the PDF and hide the stored requirement semantics.

- [ ] **Step 5: Implement controller and route**

Create `TestOrderEntrustOrderController`:

```php
namespace App\Http\Controllers;

use App\Models\TestOrder;
use App\Services\Pdf\PdfRendererClient;
use App\Services\TestOrders\BuildEntrustOrderPdfPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TestOrderEntrustOrderController extends Controller
{
    public function show(
        Request $request,
        TestOrder $testOrder,
        BuildEntrustOrderPdfPayload $payloadBuilder,
        PdfRendererClient $pdfRendererClient,
    ) {
        $this->authorizePermission($request, 'test_orders.print', 'test_orders', $testOrder);

        try {
            $pdf = $pdfRendererClient->renderEntrustOrder($payloadBuilder->build($testOrder));
        } catch (RuntimeException $exception) {
            Log::error('Unable to generate entrust order PDF.', [
                'test_order_id' => $testOrder->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Unable to generate entrust order PDF.'], 502);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename=entrust-order-'.$testOrder->order_no.'.pdf',
        ]);
    }
}
```

Add route before `Route::apiResource('/test-orders', TestOrderController::class);`:

```php
use App\Http\Controllers\TestOrderEntrustOrderController;

Route::get('/test-orders/{testOrder}/entrust-order.pdf', [TestOrderEntrustOrderController::class, 'show']);
```

- [ ] **Step 6: Run focused backend tests**

```bash
cd backend
/usr/local/bin/php artisan test tests/Feature/TestOrders/TestOrderEntrustOrderPdfTest.php tests/Feature/System/PermissionCatalogTest.php
```

Expected: PASS.

---

## Task 3: Make The Java Entrust Renderer Match Multi-Sample Test Orders

**Files:**

- Modify: `services/pdf-renderer-java/src/main/java/com/luang/pdfsigner/dto/EntrustOrderPayload.java`
- Modify: `services/pdf-renderer-java/src/main/java/com/luang/pdfsigner/service/EntrustOrderRenderer.java`
- Test: `services/pdf-renderer-java/src/test/java/com/luang/pdfsigner/service/EntrustOrderRendererTest.java`

- [ ] **Step 1: Write failing Java tests**

Add tests that build payload with two `samples` and assert the rendered PDF text contains both sample names and does not contain old sample, standards, or logistics fallback strings:

```java
@Test
void rendersAllSamplesWithoutHardcodedFallbacks() throws Exception {
    String payload = """
            {
              "base": {
                "entrust_date": "2026-05-08",
                "urgency": {"value":"normal","label":"常规"},
                "urgency_options": [{"value":"normal","label":"常规"},{"value":"urgent","label":"加急"},{"value":"critical","label":"特急"}],
                "planned_end_date": "2026-05-11",
                "entrust_number": "2026050001",
                "contract_number": "2026050001"
              },
              "client": {"company_name":"中山市铭宜镁照明科技有限公司","address":"中山古镇曹兴西路117号"},
              "requirements": {
                "standards": [
                  {"standard_code":"GB/T 9468-2008 灯具分布光度测量的一般要求","qualification_requirement":"CNAS,CMA","report_language":"中文","position":0}
                ],
                "sample_return": {"value":"return","label":"是"},
                "report_submission": {"value":"mail","label":"邮寄"},
                "allow_subcontract": {"value":"not_allowed","label":"不允许"}
              },
              "samples": [
                {"name":"LED模组路灯头","model":"MYM-300","voltage":"220V","current":"1.3A","power":"300W","frequency":"50Hz","quantity":1,"quantity_unit":"个","condition":{"value":"good","label":"完好"}},
                {"name":"LED模组天花灯头","model":"MYM-300","voltage":"220V","current":"1.3A","power":"300W","frequency":"50Hz","quantity":1,"quantity_unit":"个","condition":{"value":"good","label":"完好"}}
              ]
            }
            """;

    EntrustOrderPayload data = mapper.readValue(payload, EntrustOrderPayload.class);
    byte[] pdf = new EntrustOrderRenderer().render(data);
    String text = extractText(pdf);

    assertThat(text).contains("LED模组路灯头");
    assertThat(text).contains("LED模组天花灯头");
    assertThat(text).doesNotContain("物联网节能感应灯管");
    assertThat(text).doesNotContain("LK-ZMT8-180");
    assertThat(text).doesNotContain("中山市鑫达普检测服务有限公司");
    assertThat(text).doesNotContain("张丁浪");
}
```

Add a page-break regression test with enough standards and samples to force a second page. It should verify both page count and tail sample text, so the renderer cannot silently drop overflow content:

```java
@Test
void createsAdditionalPagesWhenSamplesExceedFirstPage() throws Exception {
    EntrustOrderPayload data = mapper.readValue(largePayloadWithTwelveSamples(), EntrustOrderPayload.class);
    byte[] pdf = new EntrustOrderRenderer().render(data);

    try (PDDocument document = Loader.loadPDF(pdf)) {
        assertThat(document.getNumberOfPages()).isGreaterThan(1);
    }

    String text = extractText(pdf);
    assertThat(text).contains("Sample-12");
}
```

Add a signature test so plain text signatures do not go through the legacy image URL path:

```java
@Test
void rendersPlainTextClientSignatureWithoutUrlFetchPlaceholder() throws Exception {
    String payload = """
            {
              "base": {"entrust_number": "2026050001"},
              "samples": [{"name":"LED模组路灯头"}],
              "signatures": {"client_signature_name": "张三"}
            }
            """;

    EntrustOrderPayload data = mapper.readValue(payload, EntrustOrderPayload.class);
    byte[] pdf = new EntrustOrderRenderer().render(data);
    String text = extractText(pdf);

    assertThat(text).contains("张三");
    assertThat(text).doesNotContain("[签名地址无效]");
    assertThat(text).doesNotContain("[签名加载失败]");
}
```

Run:

```bash
cd services/pdf-renderer-java
/usr/local/bin/mvn test -Dtest=EntrustOrderRendererTest#rendersAllSamplesWithoutHardcodedFallbacks
```

Expected: FAIL until DTO/renderer supports `samples`.

- [ ] **Step 2: Extend DTO**

Change `EntrustOrderPayload`:

```java
public record EntrustOrderPayload(
        Base base,
        Party client,
        Party manufacturer,
        Party producer,
        Requirements requirements,
        Sample sample,
        List<Sample> samples,
        Logistics logistics,
        Signatures signatures,
        Meta meta
) {
    public List<Sample> effectiveSamples() {
        if (samples != null && !samples.isEmpty()) {
            return samples;
        }
        return sample == null ? List.of() : List.of(sample);
    }
}
```

- [ ] **Step 3: Render all samples**

Replace `drawSampleSection(...)` with a loop over `payload.effectiveSamples()`. Each sample should render the same four-row block currently used by one sample:

```java
List<EntrustOrderPayload.Sample> samples = payload.effectiveSamples();
if (samples.isEmpty()) {
    samples = List.of(new EntrustOrderPayload.Sample("", "", "", "", "", "", null, "", null, "", ""));
}

for (int index = 0; index < samples.size(); index++) {
    EntrustOrderPayload.Sample sample = samples.get(index);
    cursor.ensureSpace(SAMPLE_BLOCK_HEIGHT);
    drawSingleSampleBlock(cursor, sample);
}
```

The new `drawSingleSampleBlock` must use blank fallbacks:

```java
sampleField(sample, EntrustOrderPayload.Sample::name, "")
sampleField(sample, EntrustOrderPayload.Sample::model, "")
sampleField(sample, EntrustOrderPayload.Sample::voltage, "")
sampleField(sample, EntrustOrderPayload.Sample::frequency, "")
```

Apply the same rule to standards and logistics helpers: blank input renders blank output. Do not keep the current hardcoded standards rows, default lab company/address/contact/phone, or signature error placeholders as business data.

- [ ] **Step 4: Refactor renderer around a page cursor**

The current `render()` method creates `PDPageContentStream content` inside try-with-resources and passes it down as an immutable local reference. Do not use a snippet that reassigns `content`; that cannot work reliably with the existing method shape and will not compile if `content` remains try-with-resources scoped.

Introduce a small nested `PageCursor implements AutoCloseable` in `EntrustOrderRenderer` or a package-private helper in the same package. It owns:

- `PDDocument document`
- `PDFont font`
- `float margin`
- `float contentWidth`
- current `PDPage page`
- current `PDPageContentStream content`
- current `float y`

Required helper methods:

```java
PDPageContentStream content()
float y()
void moveTo(float nextY)
void ensureSpace(float requiredHeight) throws IOException
void newPage() throws IOException
@Override public void close() throws IOException
```

Refactor draw methods to either accept `PageCursor cursor` and update `cursor.moveTo(...)`, or return the cursor after updating it. Page breaks must be requested before drawing variable-height sections: standards, each sample block, logistics, and signatures. `render()` should own a single try-with-resources block for the document and cursor:

```java
try (PDDocument document = new PDDocument()) {
    PDFont font = loadFont(document);
    try (PageCursor cursor = new PageCursor(document, font, MARGIN)) {
        drawHeader(cursor, payload);
        drawMainContent(cursor, payload);
        drawFooter(cursor, payload);
    }
    ...
}
```

The page-break test from Step 1 must fail before this refactor and pass after it.

- [ ] **Step 5: Fix signature rendering boundary**

In `drawSignatureSection(...)`, only fetch an image when `client_signature_name` is an absolute `http` or `https` URL. For every other non-empty value, draw it as plain text in the signature area.

```java
String signature = payload.signatures() != null ? payload.signatures().clientSignatureName() : null;
if (signature != null && !signature.isBlank()) {
    if (signature.startsWith("http://") || signature.startsWith("https://")) {
        drawSignatureImage(document, content, signature, signatureAreaX, signatureAreaY, signatureAreaWidth, signatureAreaHeight);
    } else {
        drawCenteredText(content, font, NORMAL_FONT_SIZE, signatureAreaX, signatureAreaY, signatureAreaWidth, signatureAreaHeight, signature);
    }
}
```

Extract the existing URL image-fetch code into `drawSignatureImage(...)`. Do not keep the old behavior where arbitrary text is parsed as a URL.

- [ ] **Step 6: Run Java renderer tests**

```bash
cd services/pdf-renderer-java
/usr/local/bin/mvn test -Dtest=EntrustOrderRendererTest
```

Expected: PASS.

---

## Task 4: Add Frontend Fields And PDF Download Actions

**Files:**

- Modify: `frontend/src/features/test-orders/TestOrderListPage.tsx`
- Modify: `frontend/src/features/test-orders/TestOrderDetailPage.tsx`
- Modify: `frontend/src/features/test-orders/TestOrderForm.tsx`
- Modify: `frontend/src/features/test-orders/testOrderSchema.ts`
- Modify: `frontend/src/lib/zh.ts`
- Test: `frontend/src/features/test-orders/__tests__/test-order-form.test.ts`
- Create or modify: `frontend/src/features/test-orders/__tests__/test-order-print.test.tsx`

- [ ] **Step 1: Write failing frontend tests**

Add test coverage that:

- `normalizeTestOrderPayload` includes new fields.
- `TestOrderForm` renders `Client email`, `Sample return`, `Rated current`, `Rated frequency`, `Quantity unit`, `Sample condition`, and `Shipping notes`.
- list/detail print button calls `/api/test-orders/{id}/entrust-order.pdf` with `responseType: 'blob'`.
- print action is hidden without `test_orders.print`.
- `paper_report` is available as a report form option and normalizes into `report_forms`.

- [ ] **Step 2: Extend TypeScript types**

In `TestOrderListPage.tsx`:

```ts
client_email?: string | null
manufacturer_email?: string | null
maker_email?: string | null
sample_return?: 'return' | 'destroy' | null
shipping_notes?: string | null
```

In `TestOrderSample`:

```ts
rated_current?: string | null
rated_frequency?: string | null
quantity_unit?: string | null
sample_condition?: 'good' | 'abnormal' | null
sample_condition_note?: string | null
```

- [ ] **Step 3: Extend schema and normalizer**

In `testOrderSchema.ts` update/add:

```ts
export const reportFormOptions = ['formal_report', 'simple_report', 'electronic_report', 'paper_report', 'english_report'] as const
export const sampleReturnOptions = ['return', 'destroy'] as const
export const sampleConditionOptions = ['good', 'abnormal'] as const
```

Extend schemas:

```ts
client_email: z.string().email('请输入有效邮箱').or(z.literal('')).optional(),
manufacturer_email: z.string().email('请输入有效邮箱').or(z.literal('')).optional(),
maker_email: z.string().email('请输入有效邮箱').or(z.literal('')).optional(),
sample_return: z.enum(sampleReturnOptions).optional().or(z.literal('')),
shipping_notes: z.string().optional(),
rated_current: z.string().optional(),
rated_frequency: z.string().optional(),
quantity_unit: z.string().optional(),
sample_condition: z.enum(sampleConditionOptions).optional().or(z.literal('')),
sample_condition_note: z.string().optional(),
```

Extend sample payload mapping with the same keys.

- [ ] **Step 4: Extend form UI**

Add email fields to `PartyFields`:

```tsx
<Field label={`${title} email`}>
  <input className={inputClass} readOnly={synced} {...form.register(`${prefix}_email`)} />
</Field>
```

Add sample fields to the sample row grid:

```tsx
<Field label="Rated current">
  <input className={inputClass} placeholder="1.3A" {...form.register(`samples.${index}.rated_current`)} />
</Field>
<Field label="Rated frequency">
  <input className={inputClass} placeholder="50Hz" {...form.register(`samples.${index}.rated_frequency`)} />
</Field>
<Field label="Quantity unit">
  <input className={inputClass} placeholder="个" {...form.register(`samples.${index}.quantity_unit`)} />
</Field>
<Field label="Sample condition">
  <select className={inputClass} {...form.register(`samples.${index}.sample_condition`)}>
    <option value="">{zhText('Unset')}</option>
    <option value="good">{zhText('good')}</option>
    <option value="abnormal">{zhText('abnormal')}</option>
  </select>
</Field>
<Field label="Condition note">
  <input className={inputClass} {...form.register(`samples.${index}.sample_condition_note`)} />
</Field>
```

Add `Sample return` and `Shipping notes` under report/logistics panels.

- [ ] **Step 5: Add PDF download helper**

Add a local helper in `TestOrderListPage.tsx` or extract to `frontend/src/features/test-orders/testOrderPrint.ts` if both list and detail need it:

```ts
export async function downloadEntrustOrderPdf(order: Pick<TestOrder, 'id' | 'order_no'>) {
  const response = await api.get<Blob>(`/api/test-orders/${order.id}/entrust-order.pdf`, {
    responseType: 'blob',
  })
  const blob = new Blob([response.data], { type: 'application/pdf' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = url
  link.download = `entrust-order-${order.order_no}.pdf`
  link.click()
  URL.revokeObjectURL(url)
}
```

Use `PermissionGate resource="test_orders" action="print"` and a `Printer` icon for list/detail buttons.

- [ ] **Step 6: Add translations**

Add labels to `zh.ts`:

```ts
'Client email': '委托方邮箱',
'Manufacturer email': '制造商邮箱',
'Maker email': '生产厂邮箱',
'Sample return': '样品是否返还',
'Shipping notes': '特别说明',
'Rated current': '额定电流',
'Rated frequency': '额定频率',
'Quantity unit': '数量单位',
'Sample condition': '样品状态',
'Condition note': '异常说明',
'Print entrust order': '打印委托单',
paper_report: '纸本报告',
return: '返还',
destroy: '销毁处理',
good: '完好',
abnormal: '异常',
```

- [ ] **Step 7: Run focused frontend tests**

```bash
cd frontend
/Users/luang/.nvm/versions/node/v22.22.2/bin/npm test -- test-order-form test-order-print
```

Expected: PASS.

---

## Task 5: End-To-End Validation And Integration

**Files:**

- Modify as needed from Tasks 1-4 only.

- [ ] **Step 1: Backend focused validation**

```bash
cd backend
/usr/local/bin/php artisan test tests/Feature/TestOrders/TestOrderApiTest.php tests/Feature/TestOrders/TestOrderEntrustOrderPdfTest.php tests/Feature/System/PermissionCatalogTest.php
```

Expected: PASS.

- [ ] **Step 2: Java renderer validation**

```bash
cd services/pdf-renderer-java
/usr/local/bin/mvn test -Dtest=EntrustOrderRendererTest
```

Expected: PASS.

- [ ] **Step 3: Frontend focused validation**

```bash
cd frontend
/Users/luang/.nvm/versions/node/v22.22.2/bin/npm test -- test-order-form test-order-print
```

Expected: PASS.

- [ ] **Step 4: Build checks**

```bash
cd frontend
/Users/luang/.nvm/versions/node/v22.22.2/bin/npm run build
```

Expected: PASS.

```bash
cd backend
/usr/local/bin/php artisan test
```

Expected: PASS, unless the repo has unrelated known failures. If unrelated failures exist, record exact failing tests and rerun the focused suite above.

- [ ] **Step 5: Static diff check**

```bash
git diff --check
```

Expected: no whitespace errors.

## Self-Review

### Coverage Against Request

- `委托单.doc` reviewed and converted into a field/layout reference.
- Old `zs-lims` reviewed as behavior reference only.
- New backend adaptation lands in `test_orders`, not a copied `entrust_orders` entity.
- New frontend adaptation lands in existing test order list/detail/form, not a new menu.
- Java print API is reused through existing `PdfRendererClient`.
- Missing fields from the document are accounted for.
- Multi-sample document behavior is explicitly covered.
- Permission, UI action, backend endpoint, PDF renderer, and tests are all included.

### Known Non-Goals

- No direct Word template filling.
- No import of old Filament/Vue pages.
- No mini program API port.
- No new top-level "Entrust Order" menu.
- No client-side generation of order numbers.
- No signature image upload redesign in this plan. Existing `client_signature` remains text/name unless a separate signature-file requirement is defined.

### Risk Checks

- **Risk:** The current frontend lacks the document's `纸本` report-form option.
  - **Decision:** Add `paper_report` to frontend options and translation while keeping backend storage as the existing `report_forms` array. The backend print payload must also recognize legacy `paper` and `electronic` values already used by existing tests/data.
- **Risk:** Java renderer currently uses fixed heights and may overflow with many samples or standards.
  - **Decision:** Refactor around a `PageCursor` that owns the current stream/page lifecycle. Do not patch page breaks by reassigning a try-with-resources `PDPageContentStream`.
- **Risk:** Java renderer contains old hardcoded defaults for standards, sample values, logistics, and invalid signature placeholders.
  - **Decision:** Remove these renderer defaults. Renderer input should come from Laravel payload mapping; missing payload values render blank unless product explicitly defines a backend default.
- **Risk:** `condition` is ambiguous as a database/API field and can be mistaken for query-condition semantics.
  - **Decision:** Use `sample_condition` and `sample_condition_note` in Laravel and React. Map them to Java DTO `condition` and `condition_note` only at the print payload boundary.
- **Risk:** Existing `critical` urgency values would be silently omitted from PDF checkbox options if only `normal`/`urgent` are mapped.
  - **Decision:** Include `critical` as `特急` in `urgency_options` and label helpers.
- **Risk:** Standard requirement text can flatten/crowd the standards cell if mapped into Java `notes`.
  - **Decision:** Keep `standard_code + standard_name`, qualification, and language in the current print layout. Leave detailed `requirement` stored in the test-order model until the renderer has a dedicated wrapped requirement area.
- **Risk:** Old renderer uses `client_signature_name` as image URL.
  - **Decision:** Java must draw non-URL signatures as text and only fetch explicit `http`/`https` image URLs.
- **Risk:** Permissions may not be granted to existing roles after catalog update.
  - **Decision:** Update `CanonicalAcceptanceSeeder` and permission catalog tests, and verify effective permissions in browser during implementation.
- **Risk:** Report form and option labels differ between old and new systems.
  - **Decision:** Centralize print label mapping in `BuildEntrustOrderPdfPayload`; keep API storage values stable.

### Placeholder Scan

No placeholder markers or open-ended handling steps remain. Every task identifies concrete files, expected fields, endpoint shape, and verification commands.
