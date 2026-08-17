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
