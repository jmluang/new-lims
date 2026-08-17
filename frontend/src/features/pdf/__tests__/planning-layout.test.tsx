import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { afterEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({ search: '' }))

// renderToStaticMarkup does not run effects, so the component only reads
// location.hash. Stub that rather than pulling in a DOM environment.
vi.stubGlobal('window', { location: { hash: '#plan', search: '' } })

vi.mock('../resumePlanning', () => ({
  resumeDocumentUuid: () => new URLSearchParams(state.search).get('document'),
  requestedSigningUuid: () => new URLSearchParams(state.search).get('request'),
  resumePlanning: vi.fn(),
}))

vi.mock('../handwrittenApi', () => ({
  fetchPlanningOptions: vi.fn(async () => ({ assignees: [], policies: [] })),
  fetchAssignedSigningRequests: vi.fn(async () => []),
  fetchSigningRequest: vi.fn(),
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

async function planningMarkup(search: string) {
  state.search = search
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
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
  it('drops the explainer strip that pushed the workspace down the page', async () => {
    const html = await planningMarkup('')

    expect(html).not.toContain('签名字段先冻结')
    expect(html).not.toContain('只做增量修订')
  })

  it('shows the upload and finalize steps when starting from a new file', async () => {
    const html = await planningMarkup('')

    expect(html).toContain('1. 上传并检查原始 PDF')
    expect(html).toContain('2. 确认报告并生成定稿')
    expect(html).toContain('3. 在定稿上调整签名框')
  })

  it('hides the already-finished steps when continuing a document', async () => {
    const html = await planningMarkup('?document=doc-1')

    expect(html).not.toContain('1. 上传并检查原始 PDF')
    expect(html).not.toContain('2. 确认报告并生成定稿')
    // The remaining cards lose their numbering along with them.
    expect(html).toContain('调整签名框')
    expect(html).not.toContain('3. 在定稿上调整签名框')
    expect(html).toContain('指定三位签署人')
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
  })
})
