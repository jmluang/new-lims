# Example LIMS Domain Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the useful business behavior from `example/` for standards, test orders, order samples, execution standards, received samples, and sample flows into the Laravel API and React SPA without copying legacy PHP structure.

**Architecture:** Laravel owns the normalized domain model, permissions, transactions, audit logs, numbering rules, exports, field-level authorization, and sample state changes. React owns list/detail/form flows, dynamic child rows, responsive views, and permission-aware UI rendering. The legacy PHP files are only a behavior reference; duplicated receive flows, inline SQL, mixed list/form screens, and inconsistent columns must be replaced with focused services, controllers, tests, and pages.

**Tech Stack:** Laravel 13, Sanctum, Spatie permissions, MySQL/MariaDB, PHPUnit, React 19, TypeScript, TanStack Router, TanStack Query, React Hook Form, Zod, Tailwind CSS, shadcn-style local UI primitives.

---

## Legacy Reference Summary

Use these `example/` files as behavior references only:

- `example/standard_info.php`: standard library CRUD backed by `standards`.
- `example/standard_catalog.php`: standard directory tree backed by `standard_catalogs`.
- `example/standard_items.php`: standard test item CRUD backed by `standard_items`.
- `example/order_list.php`: test order listing and delete flow backed by `test_orders`.
- `example/order_form.php`: create/edit test order, multiple execution standards, and multiple order sample detail rows.
- `example/order_view.php`: read-only test order detail with execution standard and sample detail tables.
- `example/ajax_get_order_samples.php`: returns order sample details for sample receiving.
- `example/sample_receive.php`: receives multiple samples for one order and writes physical `samples`.
- `example/sample_manage.php`: duplicate sample receiving plus sample flow actions.
- `example/sample_list.php`, `example/sample_flow_card.php`, `example/sample_operate.php`: sample list, labels, flow card, and operations.

Legacy table intent:

```text
standards
  ├─ standard_catalogs
  └─ standard_items

test_orders
  ├─ order_standards
  ├─ order_samples
  └─ samples
      └─ sample_flows
```

Critical legacy issues to fix during migration:

1. `sample_receive.php` and `sample_manage.php` both create received samples, but they use different numbering formats and different payload shapes. Replace both with one backend service.
2. `order_form.php` edits child rows by deleting all `order_standards` and `order_samples`, then reinserting. Replace with keyed sync so audit logs and IDs remain meaningful.
3. `standard_catalogs` SQL defines `code` as `NOT NULL`, but `standard_catalog.php` inserts no `code`. Make `code` explicit or nullable by product decision; this plan uses explicit `code`.
4. `standard_catalogs.parent_id` uses `0` while the backup SQL also declares a self foreign key. Use nullable `parent_id` in Laravel.
5. `sample_info.php` writes `samples.name`, but the newer sample flow uses `samples.sample_name`. Do not migrate the old `name` path.
6. `order_standards.standard_id` is treated as a selected standard but not fully constrained in the backup. Add a nullable FK plus immutable snapshot fields.
7. Legacy PHP mixes list pages and large forms. In React, large order/standard/sample forms use standalone pages; small supporting forms may use modals.

## Target UX Shape

```text
+------------------------------------------------------------+
|  业务管理                                                   |
|  [客户管理] [检测标准库] [委托试验单] [样品管理]             |
+------------------------------------------------------------+

检测标准库
  /standards
  ├─ list + filters
  ├─ /standards/new
  ├─ /standards/:standardId/edit
  └─ /standards/:standardId
       ├─ 目录树
       └─ 子项目

委托试验单
  /test-orders
  ├─ list + filters
  ├─ /test-orders/new
  ├─ /test-orders/:orderId/edit
  └─ /test-orders/:orderId
       ├─ 基本信息
       ├─ 多个执行标准
       └─ 多个委托样品明细

样品管理
  /samples
  ├─ list + status filters
  ├─ /samples/receive?orderNo=...
  └─ /samples/:sampleId/flow
```

## Domain Decisions

### Standards

`standards` is the master library. It should keep the legacy fields:

- `std_no`
- `chinese_name`
- `publish_date`
- `implement_date`
- `status`
- `abolish_date`
- `replaced_by`
- `corresponding_std`
- `category`
- `language`
- `operator_id`

Standard status values:

- `active`: current standard.
- `pending`: published but not yet effective.
- `abolished`: no longer valid.
- `replaced`: replaced by another standard.
- `disabled`: soft-deleted from normal business use.

`standard_catalogs` is a tree under one standard:

- `standard_id`
- `parent_id` nullable
- `code`
- `name`
- `content`
- `sort_order`

`standard_items` is a flat list under one standard:

- `standard_id`
- `item_no`
- `item_name`
- `requirement`
- `unit`
- `method`
- `remark`

### Test Orders

`test_orders` is the委托试验单主表. Keep customer snapshots from the order because submitted values must not drift when customer master data changes. Also keep nullable links to `customers` so order filters and analytics can use master data when the user selected an existing customer.

- order identity: `order_no`, `contract_no`
- schedule: `order_date`, `planned_end_date`, `urgency`
- client reference and snapshot: `client_customer_id`, `client_company`, `client_address`, `client_contact`, `client_phone`
- manufacturer reference and snapshot: `manufacturer_customer_id`, `manufacturer_company`, `manufacturer_address`, `manufacturer_contact`, `manufacturer_phone`
- maker reference and snapshot: `maker_customer_id`, `maker_company`, `maker_address`, `maker_contact`, `maker_phone`
- report requirements: `report_forms`, `delivery_method_id`, `outsourcing_id`, `remark`
- sample delivery address snapshot: `address_lab_name`, `address_contact`, `address_detail`, `address_phone`
- confirmation fields: `client_signature`, `client_sign_date`, `dept_confirm`, `dept_confirm_date`, `lab_confirm`, `lab_confirm_date`
- workflow: `sample_status`

Customer reference rule:

- If the user selects an existing customer, save its `id` in the relevant FK and copy the current customer/contact fields into snapshot columns.
- If the user types a free-form company name, save only snapshot columns and keep the FK null.
- Filters should support both `client_customer_id` and fuzzy `client_company` search.
- Do not join live customer fields into order detail responses except as optional metadata; the order document uses snapshots.

`test_order_standards` replaces legacy `order_standards`. It belongs to a test order and stores a standard snapshot:

- `test_order_id`
- `standard_id` nullable FK to `standards`
- `standard_code`
- `standard_name`
- `report_language`
- `qualifications`
- `requirement`
- `sort_order`

`test_order_samples` replaces legacy `order_samples`. It belongs to a test order and stores expected sample rows:

- `test_order_id`
- `sample_name`
- `specification`
- `model`
- `status`
- `quantity`
- `detail_content`
- `remark`
- `sort_order`

### Received Samples

`samples` is for physical received samples. It belongs to a test order and may reference one expected order sample row:

- `test_order_id`
- `test_order_sample_id` nullable
- `delivery_sequence`
- `sample_no`
- `sample_name`
- `specification`
- `model`
- `quantity`
- `status`
- `current_holder`
- `current_location`
- `storage_condition`
- `received_date`
- `appearance_check`
- `batch_no`
- `sort_order`
- `delivery_received_count`

Use one sample numbering service:

```text
{order_no}-{delivery_sequence}-{sample_index}/{delivery_received_count}
```

Example: `26000015738-1-2/3`.

The denominator is the number of successfully received physical samples in the current delivery only. It is not the full order sample count and it is not cumulative across deliveries. A second delivery with one received sample is numbered `26000015738-2-1/1`.

Rejected rows are excluded before numbering. If three submitted receive rows contain one rejected row and two accepted rows, accepted samples are numbered `...-1-1/2` and `...-1-2/2`; the rejected row does not consume an index.

`sample_flows` is append-only:

- `sample_id`
- `action_type`
- `action_by`
- `action_time`
- `holder_from`
- `holder_to`
- `location_from`
- `location_to`
- `remark`

Rejected receive rows:

- Do not create a `samples` row.
- Do not create a `sample_flows` row because there is no sample subject.
- Record an `audit_logs` row with action `samples.receive.rejected`, module `samples`, and `after` containing `test_order_id`, `test_order_sample_id`, `sample_name`, and `reject_reason`.
- Keep receive response metadata with `delivery_received_count` and `rejected_count`.

### Status Field Conventions

Use separate status vocabularies because the same legacy label meant different things in different tables:

| Field | Values | Meaning |
|---|---|---|
| `standards.status` | `active`, `pending`, `abolished`, `replaced`, `disabled` | Standard library lifecycle |
| `test_orders.sample_status` | `not_received`, `partially_received`, `received`, `testing`, `completed` | Overall sample progress for an order |
| `test_order_samples.status` | `pending`, `partially_received`, `received`, `rejected`, `cancelled` | Expected sample row progress |
| `samples.status` | `pending`, `testing`, `completed`, `retained`, `returned`, `scrapped`, `outsourced`, `outsource_returned`, `abnormal` | Physical sample lifecycle |
| `sample_flows.action_type` | `receive`, `lend`, `transfer`, `return_room`, `send_out`, `receive_back`, `return_client`, `scrap`, `position_change` | Append-only physical sample events |

Chinese labels belong in frontend display and seeded dictionaries. Store stable English-ish enum values in the database and API.

### Order Number Algorithm

Keep the legacy order number format for continuity, but implement it in `OrderNumberService` and test it directly.

Format:

```text
YY + month_tens + day_tens + 3_digit_daily_sequence + month_ones + day_ones + 2_digit_check
```

Decode example for `26000015738`:

```text
date: 2026-05-07
YY: 26
month: 05 => month_tens 0, month_ones 5
day: 07 => day_tens 0, day_ones 7
daily sequence: 001
base: 26 + 0 + 0 + 001 + 5 + 7 = 260000157
check: 38
order_no: 26000015738
```

Check digit algorithm:

```php
$salt = 'XPD_LIMS_2026';
$hash = md5($base.$salt);
$digits = '';

for ($i = strlen($hash) - 1; $i >= 0; $i--) {
    if (ctype_digit($hash[$i])) {
        $digits .= $hash[$i];

        if (strlen($digits) === 2) {
            break;
        }
    }
}

$check = str_pad(strrev($digits), 2, '0', STR_PAD_LEFT);
```

For tests, freeze the date and sequence instead of asserting only a regex.

### Permission Catalog Contract

Add these resource permissions and keep them locked in `PermissionCatalogTest`:

```php
[
    'standards' => ['read', 'create', 'update', 'delete', 'export'],
    'standard_catalogs' => ['read', 'create', 'update', 'delete'],
    'standard_items' => ['read', 'create', 'update', 'delete'],
    'test_orders' => ['read', 'create', 'update', 'delete', 'export'],
    'test_order_standards' => ['read', 'create', 'update', 'delete'],
    'test_order_samples' => ['read', 'create', 'update', 'delete'],
    'samples' => ['read', 'receive', 'update', 'export'],
    'sample_flows' => ['read', 'create'],
]
```

Nested order child rows are still managed through `TestOrderController`, but child permissions exist so groups can be precise. Creating or updating nested `standards` rows requires the matching `test_order_standards.*` permission; creating or updating nested `samples` rows requires the matching `test_order_samples.*` permission.

`GET /api/test-orders/{testOrder}/sample-options` is a receive-helper endpoint. It requires `samples.receive` and returns only minimal order/sample-option data required by the receive page. Full test order detail still requires `test_orders.read`.

All new API routes belong in the same route protection layer as customers/equipment today: `auth:sanctum` plus `EnsurePasswordChangeIsNotRequired`. Do not introduce a new route middleware such as 2FA in this migration plan unless the application adds that requirement globally first.

### Dictionary Seeds

Seed dictionary sets for display labels and selects:

- `standard.status`
- `test_order.urgency`
- `test_order.sample_status`
- `test_order_sample.status`
- `sample.status`
- `sample_flow.action_type`
- `report.form`
- `outsourcing.option`
- `report.language`
- `lab.qualification`

## Backend File Structure

- Create: `backend/database/migrations/2026_05_29_010000_create_standards_tables.php`
- Create: `backend/database/migrations/2026_05_29_011000_create_test_order_tables.php`
- Create: `backend/database/migrations/2026_05_29_012000_create_sample_tables.php`
- Create: `backend/app/Models/Standard.php`
- Create: `backend/app/Models/StandardCatalog.php`
- Create: `backend/app/Models/StandardItem.php`
- Create: `backend/app/Models/TestOrder.php`
- Create: `backend/app/Models/TestOrderStandard.php`
- Create: `backend/app/Models/TestOrderSample.php`
- Create: `backend/app/Models/Sample.php`
- Create: `backend/app/Models/SampleFlow.php`
- Create: `backend/app/Services/TestOrders/OrderNumberService.php`
- Create: `backend/app/Services/TestOrders/TestOrderPayloadNormalizer.php`
- Create: `backend/app/Services/TestOrders/SyncTestOrderChildren.php`
- Create: `backend/app/Services/Samples/SampleNumberService.php`
- Create: `backend/app/Services/Samples/ReceiveSamples.php`
- Create: `backend/app/Http/Controllers/StandardController.php`
- Create: `backend/app/Http/Controllers/StandardCatalogController.php`
- Create: `backend/app/Http/Controllers/StandardItemController.php`
- Create: `backend/app/Http/Controllers/TestOrderController.php`
- Create: `backend/app/Http/Controllers/SampleController.php`
- Create: `backend/app/Http/Controllers/SampleFlowController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Services/Authorization/PermissionCatalog.php`
- Modify: `backend/database/seeders/CanonicalAcceptanceSeeder.php`
- Create: `backend/tests/Feature/Standards/StandardApiTest.php`
- Create: `backend/tests/Feature/TestOrders/TestOrderApiTest.php`
- Create: `backend/tests/Feature/Samples/SampleReceiveTest.php`
- Create: `backend/tests/Feature/Samples/SampleFlowTest.php`
- Modify: `backend/tests/Feature/System/PermissionCatalogTest.php`
- Modify: `backend/tests/Feature/Smoke/CanonicalAcceptanceSeederTest.php`

## Frontend File Structure

- Create: `frontend/src/features/standards/StandardListPage.tsx`
- Create: `frontend/src/features/standards/StandardFormPage.tsx`
- Create: `frontend/src/features/standards/StandardForm.tsx`
- Create: `frontend/src/features/standards/StandardDetailPage.tsx`
- Create: `frontend/src/features/standards/StandardCatalogTree.tsx`
- Create: `frontend/src/features/standards/StandardItemList.tsx`
- Create: `frontend/src/features/standards/standardSchema.ts`
- Create: `frontend/src/features/standards/standardPermissions.ts`
- Create: `frontend/src/features/test-orders/TestOrderListPage.tsx`
- Create: `frontend/src/features/test-orders/TestOrderFormPage.tsx`
- Create: `frontend/src/features/test-orders/TestOrderForm.tsx`
- Create: `frontend/src/features/test-orders/TestOrderDetailPage.tsx`
- Create: `frontend/src/features/test-orders/testOrderSchema.ts`
- Create: `frontend/src/features/test-orders/testOrderPermissions.ts`
- Create: `frontend/src/features/samples/SampleListPage.tsx`
- Create: `frontend/src/features/samples/SampleReceivePage.tsx`
- Create: `frontend/src/features/samples/SampleFlowPage.tsx`
- Create: `frontend/src/features/samples/sampleSchema.ts`
- Create: `frontend/src/features/samples/samplePermissions.ts`
- Modify: `frontend/src/app/routes.tsx`
- Modify: `frontend/src/app/routePermissions.ts`
- Modify: `frontend/src/components/app/Sidebar.tsx`
- Create: `frontend/src/features/standards/__tests__/standard-form.test.tsx`
- Create: `frontend/src/features/test-orders/__tests__/test-order-form.test.tsx`
- Create: `frontend/src/features/samples/__tests__/sample-receive.test.tsx`

---

## Task 1: Backend Standard Library

**Files:**
- Create: `backend/database/migrations/2026_05_29_010000_create_standards_tables.php`
- Create: `backend/app/Models/Standard.php`
- Create: `backend/app/Models/StandardCatalog.php`
- Create: `backend/app/Models/StandardItem.php`
- Create: `backend/app/Http/Controllers/StandardController.php`
- Create: `backend/app/Http/Controllers/StandardCatalogController.php`
- Create: `backend/app/Http/Controllers/StandardItemController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Services/Authorization/PermissionCatalog.php`
- Test: `backend/tests/Feature/Standards/StandardApiTest.php`

- [ ] **Step 1: Write failing backend tests**

Create `backend/tests/Feature/Standards/StandardApiTest.php` with these cases:

```php
public function test_standard_library_supports_crud_catalog_tree_and_items(): void
{
    $admin = $this->userWithPermissions([
        'standards.read',
        'standards.create',
        'standards.update',
        'standards.delete',
        'standard_catalogs.read',
        'standard_catalogs.create',
        'standard_items.read',
        'standard_items.create',
    ]);

    $standardId = $this->postJsonAs($admin, '/api/standards', [
        'std_no' => 'GB/T 7000.1-2023',
        'chinese_name' => '灯具 第1部分：一般要求与试验',
        'publish_date' => '2023-01-01',
        'implement_date' => '2023-07-01',
        'status' => 'active',
        'category' => 'lighting',
        'language' => 'zh',
    ])->assertCreated()->json('data.id');

    $catalogId = $this->postJsonAs($admin, "/api/standards/{$standardId}/catalogs", [
        'code' => '4',
        'name' => '试验要求',
        'content' => '接地电阻、绝缘电阻、耐压测试',
        'sort_order' => 1,
    ])->assertCreated()->json('data.id');

    $this->postJsonAs($admin, "/api/standards/{$standardId}/catalogs", [
        'parent_id' => $catalogId,
        'code' => '4.1',
        'name' => '接地电阻',
        'content' => '按标准条款执行',
        'sort_order' => 1,
    ])->assertCreated();

    $this->postJsonAs($admin, "/api/standards/{$standardId}/items", [
        'item_no' => 'I-001',
        'item_name' => '接地电阻',
        'requirement' => '符合标准要求',
        'unit' => 'Ω',
        'method' => 'GB/T 7000.1-2023',
    ])->assertCreated();

    $this->getJsonAs($admin, "/api/standards/{$standardId}")
        ->assertOk()
        ->assertJsonPath('data.std_no', 'GB/T 7000.1-2023')
        ->assertJsonCount(2, 'data.catalogs')
        ->assertJsonCount(1, 'data.items');

    $this->postJsonAs($admin, "/api/standards/{$standardId}/catalogs", [
        'name' => '缺少编号的目录',
        'content' => '必须返回校验错误',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
}
```

- [ ] **Step 2: Run the focused test and verify failure**

Run:

```bash
cd backend && composer test -- tests/Feature/Standards/StandardApiTest.php
```

Expected: FAIL because routes, migrations, models, and controllers do not exist.

- [ ] **Step 3: Implement migrations and models**

Use Laravel models with `#[Fillable([...])]`, `casts()` for dates, `hasMany`, `belongsTo`, and nullable `parent_id` for catalog rows. Status values are `active`, `pending`, `abolished`, `replaced`, and `disabled`; frontend displays Chinese labels through seeded dictionary items.

- [ ] **Step 4: Implement controllers**

Follow `CustomerController` style:

- `index` paginates and filters by `search`, `status`, `category`, `language`.
- `show` includes `catalogs` and `items`.
- `store`, `update`, `destroy` call `authorizePermission`.
- `destroy` soft-disables a standard by setting status to `disabled`, matching customers/equipment behavior.
- Use `AuditLogger` for create/update/delete.

- [ ] **Step 5: Register permissions and routes**

Add permissions:

```php
'standards' => ['read', 'create', 'update', 'delete', 'export'],
'standard_catalogs' => ['read', 'create', 'update', 'delete'],
'standard_items' => ['read', 'create', 'update', 'delete'],
```

Add routes under the authenticated, password-change-protected group:

```php
Route::get('/standards/export', [StandardController::class, 'export']);
Route::apiResource('/standards', StandardController::class);

Route::scopeBindings()->group(function (): void {
    Route::get('/standards/{standard}/catalogs', [StandardCatalogController::class, 'index']);
    Route::post('/standards/{standard}/catalogs', [StandardCatalogController::class, 'store']);
    Route::put('/standards/{standard}/catalogs/{standardCatalog}', [StandardCatalogController::class, 'update']);
    Route::delete('/standards/{standard}/catalogs/{standardCatalog}', [StandardCatalogController::class, 'destroy']);

    Route::get('/standards/{standard}/items', [StandardItemController::class, 'index']);
    Route::post('/standards/{standard}/items', [StandardItemController::class, 'store']);
    Route::put('/standards/{standard}/items/{standardItem}', [StandardItemController::class, 'update']);
    Route::delete('/standards/{standard}/items/{standardItem}', [StandardItemController::class, 'destroy']);
});
```

- [ ] **Step 6: Verify**

Run:

```bash
cd backend && composer test -- tests/Feature/Standards/StandardApiTest.php
cd backend && composer test -- tests/Feature/System/PermissionCatalogTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations backend/app/Models backend/app/Http/Controllers backend/routes/api.php backend/app/Services/Authorization/PermissionCatalog.php backend/tests/Feature/Standards backend/tests/Feature/System/PermissionCatalogTest.php
git commit -m "feat: add standard library domain"
```

## Task 2: Backend Test Orders With Child Row Sync

**Files:**
- Create: `backend/database/migrations/2026_05_29_011000_create_test_order_tables.php`
- Create: `backend/app/Models/TestOrder.php`
- Create: `backend/app/Models/TestOrderStandard.php`
- Create: `backend/app/Models/TestOrderSample.php`
- Create: `backend/app/Services/TestOrders/OrderNumberService.php`
- Create: `backend/app/Services/TestOrders/TestOrderPayloadNormalizer.php`
- Create: `backend/app/Services/TestOrders/SyncTestOrderChildren.php`
- Create: `backend/app/Http/Controllers/TestOrderController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Services/Authorization/PermissionCatalog.php`
- Test: `backend/tests/Feature/TestOrders/TestOrderApiTest.php`

- [ ] **Step 1: Write failing API tests**

Create tests for:

- creating a test order with two execution standards and two order sample rows;
- viewing the order detail and preserving both child arrays;
- updating one child row, adding one child row, and removing one child row without deleting all child IDs blindly;
- searching by order number, contract number, client company, and sample status.

Use a payload like:

```php
[
    'order_date' => '2026-05-29',
    'urgency' => 'normal',
    'client_customer_id' => $customer->id,
    'client_company' => '中山市XXX有限公司',
    'client_address' => '中山市古镇镇古一飞虎楼8栋2层201室',
    'client_contact' => '唐僧',
    'client_phone' => '1388888888',
    'report_forms' => ['electronic', 'paper'],
    'outsourcing_option' => 'allowed',
    'standards' => [
        [
            'standard_id' => $standard->id,
            'standard_code' => 'GB/T 7000.1-2023',
            'standard_name' => '灯具 第1部分：一般要求与试验',
            'report_language' => 'zh',
            'qualifications' => ['CMA'],
            'requirement' => "接地电阻\n绝缘电阻\n耐压测试",
        ],
    ],
    'samples' => [
        [
            'sample_name' => '路灯',
            'specification' => 'LD',
            'model' => 'LD-100',
            'status' => 'pending',
            'quantity' => 3,
            'detail_content' => "电压\n电流\n功率",
        ],
        [
            'sample_name' => '控制器',
            'specification' => 'CTRL',
            'model' => 'C-1',
            'status' => 'pending',
            'quantity' => 1,
            'detail_content' => '功能检查',
        ],
    ],
]
```

- [ ] **Step 2: Run focused test and verify failure**

Run:

```bash
cd backend && composer test -- tests/Feature/TestOrders/TestOrderApiTest.php
```

Expected: FAIL because test order routes and tables do not exist.

- [ ] **Step 3: Implement `OrderNumberService`**

Keep the legacy number intent but make the service isolated and testable. Use the exact algorithm from the Domain Decisions section, including the `XPD_LIMS_2026` salt and reverse-scan MD5 digit extraction. Store daily sequence in `test_order_sequences` with a unique `date_key`. Generate `contract_no` equal to `order_no` unless the request supplies a non-empty contract number.

- [ ] **Step 4: Implement payload normalization**

Convert empty strings to `null`, normalize enum values, normalize `report_forms` and `qualifications` to arrays in the API and JSON columns in storage. Do not store comma-delimited lists in new tables.

For customer, manufacturer, and maker snapshots:

- accept optional `*_customer_id` values;
- validate existing IDs against `customers`;
- copy selected customer fields into snapshot columns on create/update;
- preserve manually submitted snapshot text when `*_customer_id` is null.

- [ ] **Step 5: Implement keyed child sync**

`SyncTestOrderChildren` must:

- update existing child rows when the incoming item has `id`;
- create rows when `id` is absent;
- delete rows removed from the submitted array;
- assign `sort_order` from array order;
- reject a child `id` that does not belong to the parent order.

- [ ] **Step 6: Implement `TestOrderController`**

Endpoints:

- `GET /api/test-orders`
- `POST /api/test-orders`
- `GET /api/test-orders/{testOrder}`
- `PUT /api/test-orders/{testOrder}`
- `DELETE /api/test-orders/{testOrder}`
- `GET /api/test-orders/{testOrder}/sample-options`

`sample-options` replaces `ajax_get_order_samples.php` and returns `test_order_samples` for receive forms. Authorize it with `samples.receive`, not `test_orders.read`, and return only `id`, `sample_name`, `specification`, `model`, `quantity`, and order number.

- [ ] **Step 7: Register test order permissions**

Add these permissions to `PermissionCatalog` and assert them in `PermissionCatalogTest`:

```php
'test_orders' => ['read', 'create', 'update', 'delete', 'export'],
'test_order_standards' => ['read', 'create', 'update', 'delete'],
'test_order_samples' => ['read', 'create', 'update', 'delete'],
```

- [ ] **Step 8: Verify**

Run:

```bash
cd backend && composer test -- tests/Feature/TestOrders/TestOrderApiTest.php
cd backend && composer test -- tests/Feature/System/PermissionCatalogTest.php
```

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add backend/database/migrations backend/app/Models backend/app/Services/TestOrders backend/app/Http/Controllers/TestOrderController.php backend/routes/api.php backend/app/Services/Authorization/PermissionCatalog.php backend/tests/Feature/TestOrders backend/tests/Feature/System/PermissionCatalogTest.php
git commit -m "feat: add test order domain"
```

## Task 3: Backend Sample Receiving And Flow

**Files:**
- Create: `backend/database/migrations/2026_05_29_012000_create_sample_tables.php`
- Create: `backend/app/Models/Sample.php`
- Create: `backend/app/Models/SampleFlow.php`
- Create: `backend/app/Services/Samples/SampleNumberService.php`
- Create: `backend/app/Services/Samples/ReceiveSamples.php`
- Create: `backend/app/Http/Controllers/SampleController.php`
- Create: `backend/app/Http/Controllers/SampleFlowController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Services/Authorization/PermissionCatalog.php`
- Test: `backend/tests/Feature/Samples/SampleReceiveTest.php`
- Test: `backend/tests/Feature/Samples/SampleFlowTest.php`

- [ ] **Step 1: Write failing receive tests**

Create `SampleReceiveTest` to verify:

- receiving three physical samples for one test order creates sample numbers `ORDER-1-1/3`, `ORDER-1-2/3`, `ORDER-1-3/3`;
- a second delivery for the same order creates `ORDER-2-1/1`;
- receiving three submitted rows where one has `reject_reason` creates only `ORDER-3-1/2` and `ORDER-3-2/2`;
- each sample gets a `receive` flow row;
- the parent test order changes `sample_status` to `received`;
- rejected rows are not inserted, do not create `sample_flows`, do not consume sample indexes, and are recorded in `audit_logs` with action `samples.receive.rejected`.

- [ ] **Step 2: Write failing flow tests**

Create `SampleFlowTest` to verify actions:

- `lend` moves holder from `样品室` to the named tester and status to `testing`;
- `return_room` moves holder back to `样品室`;
- `send_out` sets status to `outsourced`;
- `return_client` sets status to `returned`;
- all actions append `sample_flows`.

- [ ] **Step 3: Run focused tests and verify failure**

Run:

```bash
cd backend && composer test -- tests/Feature/Samples/SampleReceiveTest.php tests/Feature/Samples/SampleFlowTest.php
```

Expected: FAIL because sample routes and services do not exist.

- [ ] **Step 4: Implement sample tables and models**

Use the status values from the Domain Decisions section in validation:

```text
pending, testing, completed, retained, returned, scrapped, outsourced, outsource_returned, abnormal
```

Keep Chinese labels in dictionaries/frontend display, not database enum names.

- [ ] **Step 5: Implement one receive service**

`ReceiveSamples` replaces both legacy receive flows. It accepts:

```php
[
    'test_order_id' => 1,
    'received_date' => '2026-05-29',
    'storage_condition' => '常温',
    'current_location' => '样品室 A1',
    'batch_no' => 'B001',
    'samples' => [
        [
            'test_order_sample_id' => 10,
            'sample_name' => '路灯',
            'specification' => 'LD',
            'model' => 'LD-100',
            'appearance_check' => '外观完整',
            'reject_reason' => null,
        ],
    ],
]
```

Reject empty valid sample arrays with `422` and message key `samples_required`.

For mixed accepted/rejected submissions:

- filter rejected rows first;
- compute `delivery_received_count` from accepted rows only;
- number accepted rows contiguously from 1;
- record rejected rows in `audit_logs`;
- return `{ delivery_received_count, rejected_count }` in the response metadata.

- [ ] **Step 6: Implement sample list and flow endpoints**

Routes:

```php
Route::get('/samples', [SampleController::class, 'index']);
Route::post('/samples/receive', [SampleController::class, 'receive']);
Route::get('/samples/{sample}', [SampleController::class, 'show']);
Route::get('/samples/{sample}/flows', [SampleFlowController::class, 'index']);
Route::post('/samples/{sample}/flows', [SampleFlowController::class, 'store']);
```

- [ ] **Step 7: Register sample permissions**

Add these permissions to `PermissionCatalog` and assert them in `PermissionCatalogTest`:

```php
'samples' => ['read', 'receive', 'update', 'export'],
'sample_flows' => ['read', 'create'],
```

- [ ] **Step 8: Verify**

Run:

```bash
cd backend && composer test -- tests/Feature/Samples/SampleReceiveTest.php tests/Feature/Samples/SampleFlowTest.php
cd backend && composer test -- tests/Feature/Smoke/CanonicalAcceptanceSeederTest.php
```

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add backend/database/migrations backend/app/Models backend/app/Services/Samples backend/app/Http/Controllers/SampleController.php backend/app/Http/Controllers/SampleFlowController.php backend/routes/api.php backend/app/Services/Authorization/PermissionCatalog.php backend/tests/Feature/Samples backend/tests/Feature/Smoke/CanonicalAcceptanceSeederTest.php
git commit -m "feat: add sample receiving and flow"
```

## Task 4: Frontend Standard Library

**Files:**
- Create: `frontend/src/features/standards/StandardListPage.tsx`
- Create: `frontend/src/features/standards/StandardFormPage.tsx`
- Create: `frontend/src/features/standards/StandardForm.tsx`
- Create: `frontend/src/features/standards/StandardDetailPage.tsx`
- Create: `frontend/src/features/standards/StandardCatalogTree.tsx`
- Create: `frontend/src/features/standards/StandardItemList.tsx`
- Create: `frontend/src/features/standards/standardSchema.ts`
- Create: `frontend/src/features/standards/standardPermissions.ts`
- Modify: `frontend/src/app/routes.tsx`
- Modify: `frontend/src/components/app/Sidebar.tsx`
- Test: `frontend/src/features/standards/__tests__/standard-form.test.tsx`

- [ ] **Step 1: Write failing frontend tests**

Test that:

- create/edit uses a standalone page;
- list actions navigate to `/standards/new` and `/standards/$standardId/edit`;
- fields without update permission are hidden or disabled for UX and omitted from the frontend payload;
- backend API tests still prove forbidden fields are rejected server-side;
- detail page renders catalogs and items from API data.

- [ ] **Step 2: Implement schema and form**

Use Zod:

```ts
export const standardSchema = z.object({
  std_no: z.string().min(1, 'Standard number is required'),
  chinese_name: z.string().min(1, 'Chinese name is required'),
  publish_date: z.string().optional(),
  implement_date: z.string().optional(),
  status: z.enum(['active', 'pending', 'abolished', 'replaced', 'disabled']),
  abolish_date: z.string().optional(),
  replaced_by: z.string().optional(),
  corresponding_std: z.string().optional(),
  category: z.string().optional(),
  language: z.string().optional(),
})
```

- [ ] **Step 3: Implement pages**

Use the current `CustomerListPage` and `CustomerFormPage` patterns:

- `PageShell`
- `Panel`
- `DataTable`
- `PermissionGate`
- `api`
- TanStack Query cache keys including filters.

Do not put the large standard form beside the list.

- [ ] **Step 4: Add routes and navigation**

Add:

```text
/standards
/standards/new
/standards/$standardId/edit
/standards/$standardId
```

Add sidebar item under `业务管理` with `BookOpen`.

- [ ] **Step 5: Verify**

Run:

```bash
cd frontend && npm run lint
cd frontend && npm test -- standards
cd frontend && npm run build
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/features/standards frontend/src/app/routes.tsx frontend/src/components/app/Sidebar.tsx
git commit -m "feat: add standard library UI"
```

## Task 5: Frontend Test Orders

**Files:**
- Create: `frontend/src/features/test-orders/TestOrderListPage.tsx`
- Create: `frontend/src/features/test-orders/TestOrderFormPage.tsx`
- Create: `frontend/src/features/test-orders/TestOrderForm.tsx`
- Create: `frontend/src/features/test-orders/TestOrderDetailPage.tsx`
- Create: `frontend/src/features/test-orders/testOrderSchema.ts`
- Create: `frontend/src/features/test-orders/testOrderPermissions.ts`
- Modify: `frontend/src/app/routes.tsx`
- Modify: `frontend/src/components/app/Sidebar.tsx`
- Test: `frontend/src/features/test-orders/__tests__/test-order-form.test.tsx`

- [ ] **Step 1: Write failing tests for dynamic child rows**

Test that a form submission includes:

- two `standards` rows;
- two `samples` rows;
- no forbidden fields in the frontend payload;
- stable `id` values for edited child rows;
- deleting an existing child row removes that `id` from the payload;
- adding a new row after deletion creates an item without reusing the deleted row's `id`;
- reordering rows preserves each row's `id` and updates array order only.

- [ ] **Step 2: Implement schema**

Use nested Zod arrays:

```ts
const orderStandardSchema = z.object({
  id: z.number().optional(),
  standard_id: z.number().nullable().optional(),
  standard_code: z.string().min(1, 'Standard code is required'),
  standard_name: z.string().min(1, 'Standard name is required'),
  report_language: z.string().optional(),
  qualifications: z.array(z.string()).default([]),
  requirement: z.string().optional(),
})

const orderSampleSchema = z.object({
  id: z.number().optional(),
  sample_name: z.string().min(1, 'Sample name is required'),
  specification: z.string().optional(),
  model: z.string().optional(),
  status: z.enum(['pending', 'testing', 'completed']).default('pending'),
  quantity: z.coerce.number().int().min(1),
  detail_content: z.string().optional(),
  remark: z.string().optional(),
})
```

- [ ] **Step 3: Implement form page**

The order form is a standalone page because it has many fields and nested rows. Use `useFieldArray` from React Hook Form for `standards` and `samples`. Fetch standards by `/api/standards?search=...` for the execution standard picker instead of using legacy AJAX.

- [ ] **Step 4: Implement list and detail**

List filters:

- search
- urgency
- sample_status
- client_company
- order_date_from
- order_date_to

Detail page sections:

- base information
- client/manufacturer/maker snapshots
- execution standards table
- order sample detail table
- report requirements
- receiving address
- confirmation signatures

- [ ] **Step 5: Verify**

Run:

```bash
cd frontend && npm run lint
cd frontend && npm test -- test-orders
cd frontend && npm run build
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/features/test-orders frontend/src/app/routes.tsx frontend/src/components/app/Sidebar.tsx
git commit -m "feat: add test order UI"
```

## Task 6: Frontend Samples

**Files:**
- Create: `frontend/src/features/samples/SampleListPage.tsx`
- Create: `frontend/src/features/samples/SampleReceivePage.tsx`
- Create: `frontend/src/features/samples/SampleFlowPage.tsx`
- Create: `frontend/src/features/samples/sampleSchema.ts`
- Create: `frontend/src/features/samples/samplePermissions.ts`
- Modify: `frontend/src/app/routes.tsx`
- Modify: `frontend/src/components/app/Sidebar.tsx`
- Test: `frontend/src/features/samples/__tests__/sample-receive.test.tsx`

- [ ] **Step 1: Write failing receive tests**

Test that:

- entering an order number loads `sample-options`;
- the receive form shows multiple rows from the order;
- removing a row excludes it from submit;
- reject reason excludes that physical sample;
- submit calls `POST /api/samples/receive` with one canonical payload shape.

- [ ] **Step 2: Implement receive page**

Use one receive UI, not separate equivalents of `sample_receive.php` and `sample_manage.php`.

Route:

```text
/samples/receive
```

When `orderNo` is provided in the query string, fetch the order and sample options immediately.

- [ ] **Step 3: Implement list and flow pages**

List columns:

- sample_no
- sample_name
- specification/model
- order_no
- client_company
- status
- current_holder
- current_location
- received_date

Flow page:

- show sample summary
- show append-only flow table
- show permitted actions as buttons/forms

- [ ] **Step 4: Verify**

Run:

```bash
cd frontend && npm run lint
cd frontend && npm test -- samples
cd frontend && npm run build
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/features/samples frontend/src/app/routes.tsx frontend/src/components/app/Sidebar.tsx
git commit -m "feat: add sample management UI"
```

## Task 7: Seeder, Permissions, And Acceptance Smoke

**Files:**
- Modify: `backend/database/seeders/CanonicalAcceptanceSeeder.php`
- Modify: `backend/tests/Feature/Smoke/AdminWorkflowTest.php`
- Modify: `backend/tests/Feature/Smoke/CanonicalAcceptanceSeederTest.php`
- Modify: `frontend/src/app/routePermissions.ts`
- Modify: `frontend/src/components/app/Sidebar.tsx`

- [ ] **Step 1: Expand canonical groups**

Seed the dictionary sets listed in the Domain Decisions section before assigning users, so frontend selects render stable Chinese labels during acceptance testing.

Add groups:

- `standard_manager`: standard library read/create/update/delete, catalog and item management.
- `test_order_manager`: standards read, test orders read/create/update/delete/export, test order standards read/create/update/delete, test order samples read/create/update/delete, samples receive.
- `sample_manager`: sample read/receive/update/export, sample flows read/create, receive-helper access through `samples.receive`.

- [ ] **Step 2: Add smoke test**

Extend admin smoke to cover:

1. create standard;
2. create test order with one standard and two sample rows;
3. receive two samples;
4. assert sample list has two rows;
5. assert audit logs include standard, test order, and sample receive actions.

- [ ] **Step 3: Verify full backend**

Run:

```bash
cd backend && php artisan migrate:fresh --seed
cd backend && composer test
```

Expected: PASS.

- [ ] **Step 4: Verify full frontend**

Run:

```bash
cd frontend && npm run lint
cd frontend && npm test
cd frontend && npm run build
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders backend/tests/Feature/Smoke frontend/src/app/routePermissions.ts frontend/src/components/app/Sidebar.tsx
git commit -m "test: cover standards orders and samples smoke flow"
```

## Task 8: Documentation And Migration Notes

**Files:**
- Modify: `README.md`
- Modify: `backend/README.md`
- Modify: `frontend/README.md`
- Create: `docs/example-migration-notes.md`

- [ ] **Step 1: Document adapted legacy mapping**

Create `docs/example-migration-notes.md` with:

- legacy file to new module mapping;
- table mapping;
- removed duplicate receive flow;
- sample numbering rule;
- status value mapping;
- known legacy bugs intentionally not copied.

- [ ] **Step 2: Update READMEs**

Add the new business modules and verification commands:

```bash
cd backend && composer test
cd frontend && npm run lint && npm test && npm run build
```

- [ ] **Step 3: Verify docs references**

Run:

```bash
rg -n "example/|standards|test-orders|samples|composer test|npm run build" README.md backend/README.md frontend/README.md docs/example-migration-notes.md
```

Expected: references point to current files and commands.

- [ ] **Step 4: Commit**

```bash
git add README.md backend/README.md frontend/README.md docs/example-migration-notes.md
git commit -m "docs: document example migration mapping"
```

## Final Verification

Run:

```bash
cd backend && php artisan migrate:fresh --seed
cd backend && composer test
cd frontend && npm run lint
cd frontend && npm test
cd frontend && npm run build
git status --short
```

Expected:

- fresh migration and canonical seed pass on the configured local/test database;
- backend tests pass;
- frontend lint passes;
- frontend tests pass;
- frontend build passes;
- `git status --short` only shows intentional committed work or a clean tree.

## Self-Review Notes

- The plan covers all requested functions: 检测标准库, 委托试验单, 样品信息, 执行标准.
- The plan preserves one委托单 to many样品信息 through `test_order_samples` and one委托单 to many physical `samples`.
- The plan adapts legacy behavior into Laravel services and React pages instead of copying PHP screens.
- The plan explicitly fixes the legacy receive duplication, child-row delete/reinsert behavior, catalog parent FK issue, missing catalog code handling, and inconsistent sample name columns.
- Large forms are standalone pages; supporting nested lists live inside detail pages.
