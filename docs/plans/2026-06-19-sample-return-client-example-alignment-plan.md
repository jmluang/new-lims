# Sample Return Client Example Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align `new-lims` sample return behavior with `example/sample_manage.php` by exposing a clear `return_client` operation from the sample list while preserving the existing scan-only `return_room` flow.

**Architecture:** Do not add a new entity or route. Reuse the existing `sample_flows.action_type = return_client` backend contract and `/api/samples/{sample}/flows` endpoint, then add a small frontend action-rule helper so list actions match the `example` state rules and are easy to test. Keep `sample_scan` behavior unchanged because `example/sample_scan.php` only handles lend, transfer, and return-to-room.

**Tech Stack:** Laravel 13, PHPUnit feature tests, React 19, TanStack Query, TypeScript, Vitest, Tailwind CSS, lucide-react.

---

## Source Of Truth

Use only `example/` for this change:

- `example/sample_operate.php`: `return` is the old "退样" label, but it returns a sample to `样品室`.
- `example/sample_manage.php`: `return_client` is "退样给客户"; it sets the holder to `客户`, status to `已退还`, and appends a `sample_flows` row.
- `example/sample_scan.php`: scanning supports `lend`, `transfer`, and `return_room`; it does not support customer return.

Do not use `zs-lims` as a reference for this task.

## UX Shape

```text
+--------------------------------------------------------------------------------+
| 样品信息                                                                        |
| [filters...]                                                                    |
+--------------------------------------------------------------------------------+
| 样品编号       状态       持有人      位置                  操作                 |
| S-001          待检       样品室      样品室 A1             [领样] [退客户] [...] |
| S-002          在检       Alice       实验区 A              [流转] [归还] [退客户]|
| S-003          已退还     客户        样品室 A1             [流转卡] [打印]      |
+--------------------------------------------------------------------------------+

退客户确认弹窗
+--------------------------------------+
| 退客户 - S-001                       |
| 样品状态将变更为 returned，持有人为客户 |
| 备注 [_____________________________]  |
|                         [取消] [确认] |
+--------------------------------------+
```

## File Structure

- Modify `backend/tests/Feature/Samples/SampleFlowTest.php`
  - Add backend coverage for `return_client` so the existing service behavior is pinned.
- Create `frontend/src/features/samples/sampleListActions.ts`
  - Encapsulate sample list action visibility rules from `example/sample_manage.php`.
- Create `frontend/src/features/samples/__tests__/sample-list-actions.test.ts`
  - Unit-test list action visibility without needing to mount the full page.
- Modify `frontend/src/features/samples/SampleListPage.tsx`
  - Add `退客户` button for samples whose status is not `returned` or `scrapped`.
  - Add a confirmation modal that posts `action_type: return_client`.
  - Keep scan behavior unchanged.
## Task 1: Pin Backend Return-Client Behavior

**Files:**
- Modify: `backend/tests/Feature/Samples/SampleFlowTest.php`
- Existing implementation: `backend/app/Services/Samples/SampleFlowService.php`
- Existing controller: `backend/app/Http/Controllers/SampleFlowController.php`

- [ ] **Step 1: Add a failing feature test for `return_client`**

Add this test near the other sample flow action tests in `SampleFlowTest`:

```php
public function test_return_client_marks_sample_returned_to_customer_and_appends_flow(): void
{
    Carbon::setTestNow('2026-06-19 10:20:30');
    $operator = $this->userWithPermissions(['samples.read', 'samples.update', 'sample_flows.read', 'sample_flows.create']);
    $sample = $this->receivedSample([
        'status' => 'completed',
        'current_holder' => '样品室',
        'current_location' => '样品室 A1',
    ]);

    $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
        'action_type' => 'return_client',
        'remark' => '客户已签收',
    ])->assertCreated()
        ->assertJsonPath('data.action_type', 'return_client')
        ->assertJsonPath('data.holder_from', '样品室')
        ->assertJsonPath('data.holder_to', '客户')
        ->assertJsonPath('data.location_from', '样品室 A1')
        ->assertJsonPath('data.location_to', '样品室 A1')
        ->assertJsonPath('data.remark', '客户已签收')
        ->assertJsonPath('data.action_time', '2026-06-19 10:20:30');

    $this->assertDatabaseHas('samples', [
        'id' => $sample->id,
        'status' => 'returned',
        'current_holder' => '客户',
        'current_location' => '样品室 A1',
    ]);

    $this->assertDatabaseHas('sample_flows', [
        'sample_id' => $sample->id,
        'action_type' => 'return_client',
        'holder_from' => '样品室',
        'holder_to' => '客户',
        'location_from' => '样品室 A1',
        'location_to' => '样品室 A1',
        'remark' => '客户已签收',
    ]);
}
```

- [ ] **Step 2: Run the focused backend test**

Run:

```bash
cd backend
/usr/local/bin/php artisan test tests/Feature/Samples/SampleFlowTest.php --filter=return_client
```

Expected: PASS if the existing backend behavior is already correct. If it fails, fix `SampleFlowService::updatesFor()` or `SampleFlowController::store()` rather than adding a workaround in the frontend.

- [ ] **Step 3: Commit backend test**

```bash
git add backend/tests/Feature/Samples/SampleFlowTest.php
git commit -m "test: cover sample return client flow"
```

## Task 2: Extract Example-Based List Action Rules

**Files:**
- Create: `frontend/src/features/samples/sampleListActions.ts`
- Create: `frontend/src/features/samples/__tests__/sample-list-actions.test.ts`
- Read: `example/sample_manage.php`

- [ ] **Step 1: Write action-rule tests**

Create `frontend/src/features/samples/__tests__/sample-list-actions.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { visibleSampleListActions, type SampleListActionSubject } from '../sampleListActions'

function sample(overrides: Partial<SampleListActionSubject> = {}): SampleListActionSubject {
  return {
    status: 'pending',
    current_holder: '样品室',
    ...overrides,
  }
}

describe('visibleSampleListActions', () => {
  it('matches example sample_manage list actions for active samples', () => {
    expect(visibleSampleListActions(sample({ status: 'pending', current_holder: '样品室' }))).toEqual(['lend', 'return_client'])
    expect(visibleSampleListActions(sample({ status: 'testing', current_holder: 'Alice' }))).toEqual(['transfer', 'return_room', 'return_client'])
    expect(visibleSampleListActions(sample({ status: 'outsourced', current_holder: '分包实验室' }))).toEqual(['receive_back', 'return_client'])
    expect(visibleSampleListActions(sample({ status: 'completed', current_holder: '样品室' }))).toEqual(['return_client'])
    expect(visibleSampleListActions(sample({ status: 'retained', current_holder: '样品室' }))).toEqual(['return_client'])
  })

  it('hides customer return for terminal returned and scrapped samples', () => {
    expect(visibleSampleListActions(sample({ status: 'returned', current_holder: '客户' }))).toEqual([])
    expect(visibleSampleListActions(sample({ status: 'scrapped', current_holder: '样品室' }))).toEqual([])
  })
})
```

- [ ] **Step 2: Run the new frontend unit test and verify failure**

Run:

```bash
cd frontend
/Users/luang/.nvm/versions/node/v22.22.2/bin/npm test -- sample-list-actions
```

Expected: FAIL because `sampleListActions.ts` does not exist.

- [ ] **Step 3: Implement `sampleListActions.ts`**

Create `frontend/src/features/samples/sampleListActions.ts`:

```ts
export type SampleListAction = 'lend' | 'transfer' | 'return_room' | 'receive_back' | 'return_client'

export type SampleListActionSubject = {
  status: 'pending' | 'testing' | 'completed' | 'retained' | 'returned' | 'scrapped' | 'outsourced' | 'outsource_returned' | 'abnormal'
  current_holder?: string | null
}

export function visibleSampleListActions(sample: SampleListActionSubject): SampleListAction[] {
  const actions: SampleListAction[] = []

  if (sample.status === 'pending' && sample.current_holder === '样品室') {
    actions.push('lend')
  }

  if (sample.status === 'testing' && sample.current_holder !== '样品室') {
    actions.push('transfer', 'return_room')
  }

  if (sample.status === 'outsourced') {
    actions.push('receive_back')
  }

  if (!['returned', 'scrapped'].includes(sample.status)) {
    actions.push('return_client')
  }

  return actions
}
```

- [ ] **Step 4: Run frontend action-rule test**

Run:

```bash
cd frontend
/Users/luang/.nvm/versions/node/v22.22.2/bin/npm test -- sample-list-actions
```

Expected: PASS.

- [ ] **Step 5: Commit action-rule helper**

```bash
git add frontend/src/features/samples/sampleListActions.ts frontend/src/features/samples/__tests__/sample-list-actions.test.ts
git commit -m "test: define sample list return actions"
```

## Task 3: Add Sample List Return-Client UI

**Files:**
- Modify: `frontend/src/features/samples/SampleListPage.tsx`
- Use helper: `frontend/src/features/samples/sampleListActions.ts`

- [ ] **Step 1: Import icon and helper**

Update imports in `SampleListPage.tsx`:

```ts
import { ArrowLeftRight, Eye, FileText, HandCoins, PackageCheck, Printer, RotateCcw, Search, Undo2 } from 'lucide-react'
import { visibleSampleListActions } from './sampleListActions'
```

Update the shared utility import to include `textareaClass`:

```ts
import { type ApiCollection, type ApiResource, inputClass, paginationParams, textareaClass } from '../system/utils'
```

- [ ] **Step 2: Add modal state**

Add state near existing `flowRecordsSample` state:

```ts
const [returnClientSample, setReturnClientSample] = useState<Sample | null>(null)
const [returnClientRemark, setReturnClientRemark] = useState('')
```

- [ ] **Step 3: Add customer-return mutation**

Add this mutation after `quickFlow`:

```ts
const returnClientFlow = useMutation({
  mutationFn: async (input: { sampleId: number; remark?: string }) => {
    await api.post(`/api/samples/${input.sampleId}/flows`, {
      action_type: 'return_client',
      ...(input.remark?.trim() ? { remark: input.remark.trim() } : {}),
    })
  },
  onSuccess: async () => {
    setReturnClientSample(null)
    setReturnClientRemark('')
    await queryClient.invalidateQueries({ queryKey: ['samples'] })
  },
})
```

- [ ] **Step 4: Add action dispatcher helpers**

Add helper functions near `claimSample`:

```ts
function returnClient(sample: Sample) {
  setReturnClientSample(sample)
  setReturnClientRemark('')
}

function confirmReturnClient() {
  if (!returnClientSample) {
    return
  }

  returnClientFlow.mutate({ sampleId: returnClientSample.id, remark: returnClientRemark })
}
```

- [ ] **Step 5: Replace inline list action conditions**

Inside the table row actions, calculate:

```ts
const actions = visibleSampleListActions(sample)
```

Use it to render buttons:

```tsx
<PermissionGate resource="sample_flows" action="create">
  {actions.includes('lend') ? (
    <Button variant="secondary" disabled={quickFlow.isPending} onClick={() => claimSample(sample)}>
      <HandCoins className="size-4" aria-hidden="true" />
      领样
    </Button>
  ) : null}
  {actions.includes('transfer') ? (
    <Button variant="secondary" onClick={() => goToDetail(sample)}>
      <ArrowLeftRight className="size-4" aria-hidden="true" />
      流转
    </Button>
  ) : null}
  {actions.includes('return_room') ? (
    <Button variant="secondary" onClick={() => goToDetail(sample)}>
      <Undo2 className="size-4" aria-hidden="true" />
      归还
    </Button>
  ) : null}
  {actions.includes('receive_back') ? (
    <Button variant="secondary" onClick={() => goToDetail(sample)}>
      <PackageCheck className="size-4" aria-hidden="true" />
      外发退回
    </Button>
  ) : null}
  {actions.includes('return_client') ? (
    <Button variant="secondary" onClick={() => returnClient(sample)}>
      <RotateCcw className="size-4" aria-hidden="true" />
      退客户
    </Button>
  ) : null}
</PermissionGate>
```

Keep the existing `流转卡`, `打印`, and `View` buttons after this block.

- [ ] **Step 6: Add confirmation modal**

Render this near the existing flow-record modal:

```tsx
<Modal open={returnClientSample !== null} title={returnClientSample ? `退客户 - ${returnClientSample.sample_no}` : '退客户'} onClose={() => setReturnClientSample(null)}>
  {returnClientFlow.error ? <ErrorNotice error={returnClientFlow.error} fallback="退客户操作失败" /> : null}
  <div className="space-y-3">
    <p className="text-sm text-slate-600">
      确认后样品状态将变更为{zhText('returned')}，当前持有人将变更为客户。
    </p>
    <Field label="Remark">
      <textarea className={textareaClass} value={returnClientRemark} onChange={(event) => setReturnClientRemark(event.target.value)} />
    </Field>
    <div className="flex justify-end gap-2">
      <Button variant="secondary" onClick={() => setReturnClientSample(null)}>
        取消
      </Button>
      <Button variant="primary" onClick={confirmReturnClient} disabled={returnClientFlow.isPending}>
        确认退客户
      </Button>
    </div>
  </div>
</Modal>
```

- [ ] **Step 7: Run frontend checks**

Run:

```bash
cd frontend
/Users/luang/.nvm/versions/node/v22.22.2/bin/npm test -- sample-list-actions
/Users/luang/.nvm/versions/node/v22.22.2/bin/npm run lint
```

Expected: both PASS.

- [ ] **Step 8: Commit UI change**

```bash
git add frontend/src/features/samples/SampleListPage.tsx
git commit -m "feat: expose sample return client action"
```

## Task 4: Full Verification

**Files:**
- No new files.

- [ ] **Step 1: Run backend focused tests**

Run:

```bash
cd backend
/usr/local/bin/php artisan test tests/Feature/Samples/SampleFlowTest.php tests/Feature/Samples/SampleScanTest.php
```

Expected: PASS. This proves `return_client` works through the generic flow endpoint and scan behavior remains unchanged.

- [ ] **Step 2: Run frontend focused tests**

Run:

```bash
cd frontend
/Users/luang/.nvm/versions/node/v22.22.2/bin/npm test -- sample-list-actions sample-flow-permissions sample-scan
```

Expected: PASS.

- [ ] **Step 3: Run build-level checks**

Run:

```bash
cd frontend
/Users/luang/.nvm/versions/node/v22.22.2/bin/npm run lint
/Users/luang/.nvm/versions/node/v22.22.2/bin/npm run build
```

Expected: PASS. If build updates backend public assets, inspect `git status` and include only expected generated assets if the repo convention requires it.

- [ ] **Step 4: Final diff review**

Run:

```bash
git diff -- backend/tests/Feature/Samples/SampleFlowTest.php frontend/src/features/samples/SampleListPage.tsx frontend/src/features/samples/sampleListActions.ts frontend/src/features/samples/__tests__/sample-list-actions.test.ts
```

Expected: diff only contains the return-client test, sample list action helper, action test, and UI wiring.

- [ ] **Step 5: Final commit if needed**

If previous task commits were not created individually:

```bash
git add backend/tests/Feature/Samples/SampleFlowTest.php frontend/src/features/samples/SampleListPage.tsx frontend/src/features/samples/sampleListActions.ts frontend/src/features/samples/__tests__/sample-list-actions.test.ts
git commit -m "feat: align sample return client flow with example"
```

## Self-Review Checklist

- `return_client` is implemented as sample flow behavior, not as a new table or new entity.
- `return_room` remains gated by `sample_flows.return_room`.
- `return_client` only requires the existing `sample_flows.create` and `samples.update` permissions through `SampleFlowController`.
- `SampleScanController` and scan UI do not gain `return_client`, matching `example/sample_scan.php`.
- List action visibility matches `example/sample_manage.php`: `退客户` appears unless status is `returned` or `scrapped`.
- The UI labels distinguish `归还` from `退客户`; do not use the ambiguous label `退样` for both.
