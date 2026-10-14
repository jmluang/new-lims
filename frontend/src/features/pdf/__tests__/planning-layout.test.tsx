import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { afterEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({ search: '' }))

// renderToStaticMarkup does not run effects, so the component only reads
// location.hash. Stub that rather than pulling in a DOM environment.
vi.stubGlobal('window', { location: { hash: '#plan', search: '' } })
vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} })

// The planning tab is permission-gated; these tests are about its layout, so
// grant it and let the gating have its own test.
vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => ({
    data: { resources: { 'pdf.workflow': { actions: { create: true }, fields: {} } } },
  }),
}))

vi.mock('../resumePlanning', () => ({
  resumeDocumentUuid: () => new URLSearchParams(state.search).get('document'),
  requestedSigningUuid: () => new URLSearchParams(state.search).get('request'),
  resumePlanning: vi.fn(),
}))

vi.mock('../handwrittenApi', () => ({
  fetchPlanningOptions: vi.fn(async () => ({ assignees: [], policies: [] })),
  fetchAssignedSigningRequests: vi.fn(async () => []),
  fetchSigningRequest: vi.fn(),
  fetchSigningWorkflow: vi.fn(),
  fetchSigningOperation: vi.fn(),
  inspectSigningSource: vi.fn(),
  confirmAndFinalizeSigningSource: vi.fn(),
  createPreparedSigningWorkflow: vi.fn(),
  cancelSigningWorkflow: vi.fn(),
  rejectSigningRequest: vi.fn(),
  submitSignatureAppearance: vi.fn(),
  downloadRevision: vi.fn(),
}))

vi.mock('../PdfPlacementWorkspace', () => ({ PdfPlacementWorkspace: () => null }))
vi.mock('../SignaturePad', () => ({ SignaturePad: () => null }))

async function planningMarkup(search: string, workflowStatus?: string, serverStatus?: string, published = false) {
  state.search = search
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  if (workflowStatus) {
    client.setQueryData(['pdf', 'documents', 'doc-1', 'resume'], {
      reportNumber: 'RPT-1', revision: { revision_uuid: 'revision-1', revision_role: 'finalized_unsigned' },
      file: new Blob(['test pdf']), assignments: null, placements: null,
      activeWorkflow: { workflow_uuid: 'workflow-1', status: workflowStatus },
    })
  }
  if (serverStatus) {
    client.setQueryData(['pdf', 'handwritten', 'workflow', 'workflow-1'], {
      workflow_uuid: 'workflow-1', status: serverStatus, requests: [], fields: [],
    })
  }
  if (published) client.setQueryData(['pdf', 'documents', 'doc-1', 'resume'], { reportNumber: 'PUBLISHED-1', readOnly: true, revision: { revision_uuid: 'published-5' }, file: new Blob(['PDF']), placements: null, assignments: null, activeWorkflow: null })
  const { PdfHandwrittenSigningPage } = await import('../PdfHandwrittenSigningPage')

  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <PdfHandwrittenSigningPage />
    </QueryClientProvider>,
  )
}

afterEach(() => {
  state.search = ''
})

describe('planning workspace layout', () => {
  it('uses the current rejected state instead of the original ready snapshot', async () => {
    const html = await planningMarkup('?document=doc-1', 'ready', 'rejected')
    expect(html).toContain('本次签署已终止')
    expect(html).not.toContain('取消签署流程')
    // Static rendering has not refreshed resumed metadata yet.
    expect(html).toMatch(/<fieldset[^>]*disabled=""[^>]*class="contents"/)
  })
  it.each(['rejected', 'cancelled'])('retains the %s status while resumed metadata refreshes', async (status) => {
    const html = await planningMarkup('?document=doc-1', status)
    expect(html).toMatch(/<fieldset[^>]*disabled=""[^>]*class="contents"/)
    expect(html).toContain('本次签署已终止')
    expect(html).not.toContain('取消签署流程')
  })
  it('drops the explainer strip that pushed the workspace down the page', async () => {
    const html = await planningMarkup('')

    expect(html).not.toContain('签名字段先冻结')
    expect(html).not.toContain('只做增量修订')
  })

  it('shows the upload and finalize steps when starting from a new file', async () => {
    const html = await planningMarkup('')

    expect(html).toContain('1. 上传并检查原始 PDF')
    expect(html).toContain('2. 确认报告并生成定稿')
    expect(html).toContain('3. 调整签名位置')
  })

  it('hides the already-finished steps when continuing a document', async () => {
    const html = await planningMarkup('?document=doc-1')

    expect(html).not.toContain('1. 上传并检查原始 PDF')
    expect(html).not.toContain('2. 确认报告并生成定稿')
    // The remaining cards lose their numbering along with them.
    expect(html).toContain('调整签名位置')
    expect(html).not.toContain('3. 调整签名位置')
    expect(html).toContain('指定三位签署人')
  })

  it('presents a published report as read-only without editing or cancellation actions', async () => {
    const html = await planningMarkup('?document=doc-1', undefined, undefined, true)
    expect(html).toContain('已发布报告（只读）')
    expect(html).toContain('已载入已发布的最终版本')
    expect(html).not.toContain('正在编辑已有报告')
    expect(html).not.toContain('取消签署流程')
    expect(html).not.toContain('确认签名位置并发起签署')
    expect(html).toMatch(/<fieldset[^>]*disabled=""[^>]*class="contents"/)
  })

  it('gives every role its own page field instead of one behind a selection', async () => {
    const html = await planningMarkup('')
    const pageInputs = html.match(/type="number"/g) ?? []

    // 主检 / 审核 / 签发 each carry their own, so setting one no longer starts
    // with selecting it.
    expect(pageInputs.length).toBe(3)
    expect(html).toContain('主检')
    expect(html).toContain('审核')
    expect(html).toContain('签发')
  })

  it('keeps the exact coordinates behind an advanced toggle', async () => {
    const html = await planningMarkup('')

    expect(html).toContain('高级：精确坐标')
    // Collapsed by default: dragging is the normal way to place a box.
    expect(html).not.toContain('WIDTH')
    expect(html).not.toContain('HEIGHT')
  })

  // The workspace is only locked while a workflow owns the fields. Cancelling
  // hands them back, and the boxes have to be movable again to replan.
  it('ties the canvas being editable to the same rule as the freeze button', async () => {
    const { canFreeze } = await import('../workflowAttempt')

    expect(canFreeze(null)).toBe(true)
    expect(canFreeze('cancelled')).toBe(true)
    expect(canFreeze('ready')).toBe(false)
  })

  // A notification links to one task; landing on the planning tab, or on
  // whichever task happens to be first, would defeat the link.
  it('opens the signing tab when a notification names a task', async () => {
    const html = await planningMarkup('?request=req-1')

    expect(html).toContain('手写签名')
    expect(html).not.toContain('上传并检查原始 PDF')
  })

  it('lets the sidebar scroll on its own so the page does not', async () => {
    const html = await planningMarkup('')

    expect(html).toMatch(/<aside[^>]*xl:overflow-y-auto/)
    expect(html).toContain('xl:h-[calc(100vh-11rem)]')
    expect(html).toContain('xl:grid-cols-[minmax(0,1fr)_24rem]')
  })
})
