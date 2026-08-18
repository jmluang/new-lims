import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({ canPlan: true }))

vi.stubGlobal('window', { location: { hash: '#sign', search: '' } })
vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} })

vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => ({
    data: { resources: { 'pdf.workflow': { actions: { create: state.canPlan }, fields: {} } } },
  }),
}))

vi.mock('../resumePlanning', () => ({
  resumeDocumentUuid: () => null,
  requestedSigningUuid: () => null,
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

async function markup(canPlan: boolean) {
  state.canPlan = canPlan
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const { PdfHandwrittenSigningPage } = await import('../PdfHandwrittenSigningPage')

  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <PdfHandwrittenSigningPage />
    </QueryClientProvider>,
  )
}

describe('signing workspace', () => {
  it('does not reuse operation or ink state across tasks and canvas shapes', async () => {
    const { drawingStateForKey, operationUuidForRequest } = await import('../signingTaskState')

    expect(operationUuidForRequest({ requestUuid: 'task-a', operationUuid: 'operation-a' }, 'task-b')).toBe('')
    expect(drawingStateForKey({ key: 'task-a:2.8', previewUrl: 'ink-a', ready: true }, 'task-b:2.8'))
      .toEqual({ key: 'task-b:2.8', previewUrl: null, ready: false })
    expect(drawingStateForKey({ key: 'task-a:2.8', previewUrl: 'ink-a', ready: true }, 'task-a:2.1'))
      .toEqual({ key: 'task-a:2.1', previewUrl: null, ready: false })
  })

  // Planning who signs is a different job from signing. A signer without the
  // planning permission should not be offered the tab at all.
  it('hides the planning tab from someone who can only sign', async () => {
    expect(await markup(false)).not.toContain('规划签名位置')
    expect(await markup(true)).toContain('规划签名位置')
  })

  it('keeps the task list out of the layout until it is asked for', async () => {
    const html = await markup(false)

    // A switcher in the header, not a column standing beside the PDF. The
    // sidebar matches the planning page so a report is the same size in both.
    expect(html).toContain('切换任务')
    expect(html).toContain('xl:grid-cols-[minmax(0,1fr)_24rem]')
  })

  it('offers rejecting from the header rather than beside the signature', async () => {
    const html = await markup(false)

    // Confirming and rejecting stood in the same panel, so the page read as two
    // things to do rather than one decision. Rejecting now sits with the task it
    // applies to, and its reasons stay behind the modal until asked for.
    expect(html).toContain('拒绝')
    expect(html).not.toContain('拒绝并终止本次签署')
    expect(html).not.toContain('内容审核不通过')
  })

  it('asks for the password at the moment of signing, not beside the pad', async () => {
    const html = await markup(false)

    // The field sat in the panel the whole time the signer was drawing. It now
    // lives in the confirmation dialog, which is closed until the button is hit.
    expect(html).toContain('确认身份并签名')
    expect(html).not.toContain('当前登录密码')
  })

  it('draws the pad as a wide rectangle rather than a near square', async () => {
    const html = await markup(false)

    // A fixed height in a narrow column made the pad nearly square while its
    // canvas stayed 900x320, so every stroke was stretched sideways on the way
    // in. The display now follows the canvas.
    expect(html).toMatch(/aspect-ratio:\s*2\.8/)
    expect(html).not.toMatch(/class="[^"]*\bh-56\b/)
    expect(html).not.toMatch(/class="[^"]*\bh-72\b/)
  })
})
