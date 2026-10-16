// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, StrictMode } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { SigningDocument } from '../handwrittenApi'

const server = vi.hoisted(() => ({ download: vi.fn(), downloadAllowed: true, planningAllowed: true }))
vi.mock('../handwrittenApi', async original => ({
  ...await original<typeof import('../handwrittenApi')>(),
  downloadRevision: server.download,
}))
vi.mock('../PdfPlacementWorkspace', () => ({
  PdfPlacementWorkspace: ({ file, editable }: { file: Blob; editable: boolean }) =>
    <div data-testid="readonly-pdf" data-bytes={file.size} data-editable={String(editable)} />,
}))
vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => ({ data: { resources: {
    'pdf.document': { actions: { read: true, update: true, delete: true }, fields: {} },
    'pdf.workflow': { actions: { create: server.planningAllowed }, fields: {} },
    'pdf.revision': { actions: { download: server.downloadAllowed }, fields: {} },
  } } }),
}))

let root: Root
let container: HTMLDivElement
let client: QueryClient
let report: SigningDocument
const createUrl = vi.fn()
const revokeUrl = vi.fn()

async function settle(action?: () => void) {
  await act(async () => { action?.(); await new Promise(resolve => setTimeout(resolve, 20)) })
}
async function mount() {
  client.setQueryData(['pdf', 'documents', '', 1, 15], {
    data: [report], meta: { current_page: 1, per_page: 15, total: 1 },
  })
  const { PdfDocumentListPage } = await import('../PdfDocumentListPage')
  await settle(() => root.render(<StrictMode><QueryClientProvider client={client}><PdfDocumentListPage /></QueryClientProvider></StrictMode>))
}
async function openVersions(mobile = false) {
  const button = mobile
    ? [...container.querySelectorAll('article button')].find(button => button.textContent?.includes('查看版本与 PDF'))
    : container.querySelector<HTMLButtonElement>('button[aria-label="查看 XPD20261015-001 的版本与 PDF"]')
  expect(button).toBeDefined()
  await settle(() => (button as HTMLButtonElement).click())
  const deadline = Date.now() + 1000
  while (dialog().querySelector('[role=status]') && Date.now() < deadline) await settle()
  expect(dialog().querySelector('[role=status]')).toBeNull()
}
function dialog() { return container.querySelector('[role=dialog]')! }

beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  server.downloadAllowed = true
  server.planningAllowed = true
  server.download.mockReset().mockImplementation(async uuid => new Blob([`PDF:${uuid}`], { type: 'application/pdf' }))
  createUrl.mockReset().mockImplementation(() => `blob:version-${createUrl.mock.calls.length}`)
  revokeUrl.mockReset()
  vi.stubGlobal('URL', class extends URL {
    static createObjectURL = createUrl
    static revokeObjectURL = revokeUrl
  })
  report = {
    document_uuid: 'doc-1', report_number: 'XPD20261015-001', status: 'published', stage: 'published',
    published_revision_uuid: 'revision-6', integrity_state: 'ok', evidence_hold_state: 'none',
    has_running_work: false, workflow_uuid: 'workflow-8', workflow_status: 'completed', signers: [],
    revisions: [
      { revision_uuid: 'revision-2', revision_number: 2, revision_role: 'prepared', integrity_state: 'ready' },
      { revision_uuid: 'revision-6', revision_number: 6, revision_role: 'handwritten_signature', integrity_state: 'ready' },
      // Publication must win over a higher revision number when choosing the initial PDF.
      { revision_uuid: 'revision-7', revision_number: 7, revision_role: 'prepared', integrity_state: 'ready' },
    ],
    created_by_id: 1, is_owner: true, created_at: '2026-10-15T13:16:34Z',
  }
  client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity }, mutations: { retry: false } } })
  container = document.createElement('div')
  document.body.append(container)
  root = createRoot(container)
})
afterEach(async () => {
  await act(async () => root.unmount())
  client.clear()
  container.remove()
  vi.unstubAllGlobals()
})

describe('document versions access', () => {
  it('opens a published PDF without enabling any published-document mutation', async () => {
    await mount()
    const row = container.querySelector('tbody tr')!
    const mutations = [...row.querySelectorAll('button[title="已发布的报告不可修改"]')]
    expect(mutations).toHaveLength(3)
    expect(mutations.every(button => (button as HTMLButtonElement).disabled)).toBe(true)
    await openVersions()
    expect(dialog().querySelector('select')?.value).toBe('revision-6')
    expect(dialog().textContent).toContain('已发布')
    expect(server.download).toHaveBeenCalledExactlyOnceWith('revision-6')
    expect(dialog().querySelector('[data-testid=readonly-pdf]')?.getAttribute('data-editable')).toBe('false')
    expect(dialog().querySelector('a[download]')?.getAttribute('href')).toMatch(/^blob:/)
    expect(dialog().querySelector('a[download]')?.getAttribute('download')).toBe('XPD20261015-001-v6.pdf')
    expect(dialog().querySelector('input[type=password]')).toBeNull()
  })

  it('switches to an exact older version and releases each preview URL', async () => {
    await mount()
    await openVersions()
    const firstUrl = dialog().querySelector<HTMLAnchorElement>('a[download]')!.href
    await settle(() => {
      const select = dialog().querySelector('select')!
      select.value = 'revision-2'
      select.dispatchEvent(new Event('change', { bubbles: true }))
    })
    expect(server.download).toHaveBeenLastCalledWith('revision-2')
    expect(dialog().querySelector('a[download]')?.getAttribute('download')).toBe('XPD20261015-001-v2.pdf')
    expect(revokeUrl).toHaveBeenCalledWith(firstUrl)
    const secondUrl = dialog().querySelector<HTMLAnchorElement>('a[download]')!.href
    await settle(() => dialog().querySelector<HTMLButtonElement>('button[aria-label="关闭"]')!.click())
    expect(revokeUrl).toHaveBeenCalledWith(secondUrl)
  })

  it('keeps version metadata accessible but never fetches bytes without download permission', async () => {
    server.downloadAllowed = false
    await mount()
    await openVersions()
    expect(dialog().querySelectorAll('option')).toHaveLength(3)
    expect(dialog().textContent).toContain('没有 PDF 预览或下载权限')
    expect(dialog().querySelector('[data-testid=readonly-pdf],a[download]')).toBeNull()
    expect(server.download).not.toHaveBeenCalled()
  })

  it('allows a document reader to preview without granting signing or planning permission', async () => {
    server.planningAllowed = false
    await mount()
    await openVersions()
    expect(server.download).toHaveBeenCalledExactlyOnceWith('revision-6')
    expect(dialog().querySelector('[data-testid=readonly-pdf]')).not.toBeNull()
  })

  it.each(['revision', 'document'])('does not download when %s integrity is unavailable', async scope => {
    if (scope === 'revision') report.revisions[1].integrity_state = 'unavailable'
    else report.integrity_state = 'hold'
    await mount()
    await openVersions()
    expect(dialog().textContent).toContain('此版本当前不可预览或下载')
    expect(server.download).not.toHaveBeenCalled()
  })

  it('offers the same version access on mobile and reuses the immutable PDF on reopening', async () => {
    await mount()
    await openVersions(true)
    await settle(() => dialog().querySelector<HTMLButtonElement>('button[aria-label="关闭"]')!.click())
    await openVersions(true)
    expect(server.download).toHaveBeenCalledExactlyOnceWith('revision-6')
    expect(dialog().querySelector('a[download]')).not.toBeNull()
  })

  it('shows a failed download without automatically retrying', async () => {
    server.download.mockRejectedValue(new Error('PDF暂不可用'))
    await mount()
    await openVersions()
    expect(dialog().textContent).toContain('PDF 加载失败')
    expect(dialog().querySelector('[data-testid=readonly-pdf],a[download]')).toBeNull()
    expect(server.download).toHaveBeenCalledTimes(1)
  })
})
