// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, expect, it, vi } from 'vitest'
import type { Report } from '../lm79Api'

const server = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), navigate: vi.fn() }))
vi.mock('../../../lib/api', () => ({ api: server }))
vi.mock('@tanstack/react-router', () => ({
  Link: ({ children }: { children: React.ReactNode }) => <span>{children}</span>,
  useLoaderData: () => undefined,
  useNavigate: () => vi.fn(),
  useRouterState: () => '/reports/lm79/7',
}))
vi.mock('../../auth/useCurrentUser', () => ({ useEffectivePermissions: () => ({ data: { resources: {
  lm79_reports: { actions: { update: true, print: true } }, 'pdf.workflow': { actions: { create: true } },
} } }) }))
vi.mock('../ReportEquipmentFields', () => ({ ReportEquipmentFields: () => null }))

afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks() })

it.each(['completed', 'failed'])('keeps the handed-off report frozen when reopening after %s finalization', async (finalization) => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  const actualWindow = window
  const location = { search: '', assign: server.navigate }
  vi.stubGlobal('window', new Proxy(actualWindow, { get: (target, key) => key === 'location' ? location : Reflect.get(target, key, target) }))
  const report: Report = { id: 7, sample_id: 1, report_number: 'RPT-HANDOFF', locked: false,
    document_uuid: null, media: [], updated_at: '', data: { values: {}, standards: [], equipment: [] },
    sample_snapshot: { sample_no: 'S-1', sample_name: 'Lamp', model: 'L-1', order_no: 'ORDER-1' } }
  const options = { groups: [], defaults: {} }
  server.navigate.mockReset()
  server.post.mockReset().mockImplementation(async (url: string) => {
    if (url === '/api/lm79-reports/7') return { data: { data: report } }
    if (url.endsWith('/signing-source')) return { data: { data: { source_uuid: 'source-1', document_uuid: 'doc-1', report_number: 'RPT-HANDOFF' } } }
    if (url.endsWith('/confirm')) return { data: {} }
    if (url.endsWith('/finalize')) {
      if (finalization === 'failed') throw new Error('Finalization unavailable')
      return { data: { data: { operation_uuid: 'operation-1', state: 'completed',
        result_revision_uuid: 'revision-1', result_sha256: 'hash-1', result_size: 4 } } }
    }
    throw new Error(`Unexpected POST: ${url}`)
  })
  server.get.mockReset().mockImplementation(async (url: string) => {
    if (url.endsWith('/download')) return { data: new Blob(['%PDF'], { type: 'application/pdf' }) }
    if (url.endsWith('/form-options')) return { data: { data: options } }
    if (url === '/api/lm79-reports/7') return { data: { data: report } }
    if (url === '/api/pdf/documents/doc-1') return { data: { data: {
      report_number: 'RPT-HANDOFF', workflow_uuid: null, workflow_status: null,
      revisions: [{ revision_uuid: 'revision-1', revision_role: 'finalized_unsigned', integrity_state: 'ready' }],
    } } }
    throw new Error(`Unexpected GET: ${url}`)
  })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity }, mutations: { retry: false } } })
  client.setQueryData(['lm79-options'], options)
  client.setQueryData(['lm79-report', '7'], report)
  const container = document.createElement('div')
  document.body.append(container)
  const root = createRoot(container)
  try {
    const { Lm79ReportPage } = await import('../Lm79ReportPage')
    await act(async () => root.render(<QueryClientProvider client={client}><Lm79ReportPage /></QueryClientProvider>))
    const submit = [...container.querySelectorAll('button')].find(button => button.textContent === '提交签署')!
    expect(submit).toBeDefined()
    await act(async () => { submit.click(); await new Promise(resolve => setTimeout(resolve, 40)) })
    if (finalization === 'completed') {
      expect(server.navigate).toHaveBeenCalledWith('/pdf/handwritten-signing?document=doc-1#plan')
      const { resumePlanning } = await import('../../pdf/resumePlanning')
      const planned = await resumePlanning('doc-1')
      expect(planned.file.size).toBe(4)
      expect(server.get.mock.calls.filter(([url]) => url === '/api/pdf/revisions/revision-1/download')).toHaveLength(1)
    } else {
      expect(server.navigate).not.toHaveBeenCalled()
      expect(container.textContent).toContain('报告操作失败')
    }
    await act(async () => root.render(null))
    await act(async () => root.render(<QueryClientProvider client={client}><Lm79ReportPage /></QueryClientProvider>))
    expect(container.textContent).toContain('报告已交接签署，原始资料已冻结。')
    expect(container.querySelector('a[href="/pdf/handwritten-signing?document=doc-1#plan"]')).not.toBeNull()
    expect([...container.querySelectorAll('button')].some(button => button.textContent === '保存草稿')).toBe(false)
  } finally {
    await act(async () => root.unmount())
    client.clear()
    container.remove()
  }
})
