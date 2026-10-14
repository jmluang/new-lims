// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({ resume: vi.fn() }))
vi.mock('../../auth/useCurrentUser', () => ({ useEffectivePermissions: () => ({
  data: { resources: { 'pdf.workflow': { actions: { create: true } } } },
}) }))
vi.mock('../PdfPlacementWorkspace', () => ({ PdfPlacementWorkspace: () => null }))
vi.mock('../resumePlanning', async (original) => ({
  ...await original<typeof import('../resumePlanning')>(), resumePlanning: server.resume,
}))
vi.mock('../handwrittenApi', async (original) => ({
  ...await original<typeof import('../handwrittenApi')>(),
  fetchPlanningOptions: async () => ({ assignees: [], policies: [] }),
}))

// A cached editable revision can be published while the planner is away.
// Keep controls locked until fresh server metadata decides the revision role.
it.each(['cancelled', 'rejected', 'published'])('refreshes an old plan before permitting edits: %s', async (status) => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  window.history.replaceState(null, '', '/pdf/handwritten-signing?document=doc-1#plan')
  const prior = { reportNumber: 'RPT-1', revision: { revision_uuid: 'revision-1' },
    file: new Blob(['pdf']), assignments: null, placements: null, readOnly: false,
    activeWorkflow: { workflow_uuid: 'workflow-1', status: 'cancelled' } }
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  client.setQueryData(['pdf', 'documents', 'doc-1', 'resume'], prior)
  let finish!: (value: typeof prior) => void
  server.resume.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  const host = document.createElement('div')
  document.body.append(host)
  const root = createRoot(host)
  try {
    const { PdfHandwrittenSigningPage } = await import('../PdfHandwrittenSigningPage')
    await act(async () => root.render(<QueryClientProvider client={client}><PdfHandwrittenSigningPage /></QueryClientProvider>))
    expect(host.querySelector<HTMLFieldSetElement>('fieldset.contents')?.disabled).toBe(true)
    expect(host.textContent).not.toContain('重新确认并发起签署')
    await act(async () => {
      finish({ ...prior, readOnly: status === 'published',
        activeWorkflow: { ...prior.activeWorkflow, status: status === 'published' ? 'completed' : status } })
      await new Promise((resolve) => setTimeout(resolve, 20))
    })
    expect(host.querySelector<HTMLFieldSetElement>('fieldset.contents')?.disabled).toBe(status === 'published')
    if (status === 'published') {
      expect(host.textContent).toContain('已发布报告（只读）')
      expect(host.textContent).not.toContain('重新确认并发起签署')
      expect(host.textContent).not.toContain('取消签署流程')
    } else {
      expect(host.textContent).toContain('重新确认并发起签署')
    }
  } finally {
    await act(async () => root.unmount())
    client.clear()
    host.remove()
    vi.unstubAllGlobals()
  }
})
