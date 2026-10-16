// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, StrictMode } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { SigningDocument } from '../handwrittenApi'

const server = vi.hoisted(() => ({
  download: vi.fn(), read: vi.fn(), navigate: vi.fn(), downloadAllowed: true,
  location: { pathname: '/pdf/documents/doc-1', searchStr: '' },
}))
vi.mock('../handwrittenApi', async original => ({
  ...await original<typeof import('../handwrittenApi')>(),
  downloadRevision: server.download, fetchSigningDocument: server.read,
}))
vi.mock('@tanstack/react-router', async original => ({
  ...await original<typeof import('@tanstack/react-router')>(),
  useRouterState: ({ select }: { select: (state: unknown) => unknown }) => select({ location: server.location }),
  useNavigate: () => server.navigate,
}))
vi.mock('../PdfPlacementWorkspace', () => ({
  PdfPlacementWorkspace: ({ file, editable }: { file: Blob; editable: boolean }) =>
    <div data-testid="readonly-pdf" data-bytes={file.size} data-editable={String(editable)} />,
}))
vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => ({ data: { resources: {
    'pdf.document': { actions: { read: true }, fields: {} },
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
async function until(done: () => boolean) {
  const deadline = Date.now() + 1500
  while (!done() && Date.now() < deadline) await settle()
  expect(done()).toBe(true)
}
async function mount() {
  const { PdfDocumentDetailPage } = await import('../PdfDocumentDetailPage')
  await settle(() => root.render(<StrictMode><QueryClientProvider client={client}><PdfDocumentDetailPage /></QueryClientProvider></StrictMode>))
  await until(() => Boolean(combo()) || container.textContent?.includes('报告不存在') === true)
  await until(() => !container.querySelector('[role=status]'))
}
function combo() { return container.querySelector<HTMLButtonElement>('[role=combobox]')! }
function options() { return [...container.querySelectorAll<HTMLButtonElement>('[role=option]')] }
async function expand() { await settle(() => combo().click()) }
async function key(name: string) {
  await settle(() => combo().dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true })))
}
async function followNavigation() {
  const destination = server.navigate.mock.lastCall![0]
  server.location.searchStr = `?revision=${destination.search.revision}`
  await mount()
}

beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  server.location = { pathname: '/pdf/documents/doc-1', searchStr: '' }
  server.downloadAllowed = true
  server.download.mockReset().mockImplementation(async uuid => new Blob([`PDF:${uuid}`], { type: 'application/pdf' }))
  server.navigate.mockReset()
  createUrl.mockReset().mockImplementation(() => `blob:version-${createUrl.mock.calls.length}`)
  revokeUrl.mockReset()
  vi.stubGlobal('URL', class extends URL { static createObjectURL = createUrl; static revokeObjectURL = revokeUrl })
  report = {
    document_uuid: 'doc-1', report_number: 'XPD20261015-001', status: 'published', stage: 'published',
    published_revision_uuid: 'revision-6', integrity_state: 'ok', evidence_hold_state: 'none',
    has_running_work: false, workflow_uuid: 'workflow-8', workflow_status: 'completed', signers: [],
    revisions: Array.from({ length: 6 }, (_, index) => ({
      revision_uuid: `revision-${index + 1}`, revision_number: index + 1,
      revision_role: index === 0 ? 'finalized_unsigned' : index < 3 ? 'prepared' : 'handwritten_signature', integrity_state: 'ready',
    })), created_by_id: 1, is_owner: true, created_at: '2026-10-15T13:16:34Z',
  }
  server.read.mockReset().mockImplementation(async () => report)
  client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
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

describe('standalone report versions', () => {
  it('defaults to the published version, supplies a return link, and opens all six styled options', async () => {
    await mount()
    expect(server.read).toHaveBeenCalledExactlyOnceWith('doc-1')
    expect(combo().textContent).toContain('版本 6')
    expect(combo().textContent).toContain('已发布')
    expect(container.querySelector('[role=dialog],select')).toBeNull()
    expect(container.querySelector('a[href="/pdf/documents"]')?.textContent).toContain('返回列表')
    expect(server.download).toHaveBeenCalledExactlyOnceWith('revision-6')
    expect(container.querySelector('[data-testid=readonly-pdf]')?.getAttribute('data-editable')).toBe('false')
    await expand()
    expect(options()).toHaveLength(6)
    expect(options()[0].textContent).toContain('版本 6')
    expect(options()[5].textContent).toContain('版本 1')
    expect(container.querySelector('[role=listbox]')?.className).toContain('bg-white')
    expect(options().every(option => option.className.includes('text-sm'))).toBe(true)
  })

  it('does not substitute a newer unpublished PDF for the published default', async () => {
    report.revisions.push({ revision_uuid: 'revision-7', revision_number: 7, revision_role: 'prepared', integrity_state: 'ready' })
    await mount()
    expect(server.download).toHaveBeenCalledExactlyOnceWith('revision-6')
  })

  it.each([null, 'missing-published-revision'])('does not replace unavailable published metadata %j with another PDF', async published => {
    report.published_revision_uuid = published
    await mount()
    expect(container.querySelector('[role=alert]')?.textContent).toContain('已发布版本不可用')
    expect(server.download).not.toHaveBeenCalled()
    expect(container.querySelector('[data-testid=readonly-pdf],a[download]')).toBeNull()
  })

  it('opens an explicit copied version link directly, retaining the exact version on refresh', async () => {
    server.location.searchStr = '?revision=revision-4'
    await mount()
    expect(combo().textContent).toContain('版本 4')
    expect(server.download).toHaveBeenCalledExactlyOnceWith('revision-4')
    expect(container.querySelector('a[download]')?.getAttribute('download')).toBe('XPD20261015-001-v4.pdf')
    expect(server.download).not.toHaveBeenCalledWith('revision-6')
  })

  it('changes the URL on selection and releases the previous download URL', async () => {
    await mount()
    const previousUrl = container.querySelector<HTMLAnchorElement>('a[download]')!.href
    await expand()
    await settle(() => options()[2].click())
    expect(server.navigate).toHaveBeenCalledWith({ to: '/pdf/documents/$documentUuid', params: { documentUuid: 'doc-1' }, search: { revision: 'revision-4' }, hash: '' })
    await followNavigation()
    expect(server.download).toHaveBeenLastCalledWith('revision-4')
    expect(revokeUrl).toHaveBeenCalledWith(previousUrl)
    expect(combo().getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(combo())
  })

  it.each(['other-report-revision', '', 'missing-revision'])('rejects invalid/foreign version %j before requesting any PDF', async revision => {
    server.location.searchStr = `?revision=${revision}`
    await mount()
    expect(container.querySelector('[role=alert]')?.textContent).toContain('不存在或不属于此报告')
    expect(server.download).not.toHaveBeenCalled()
    expect(container.querySelector('[data-testid=readonly-pdf],a[download]')).toBeNull()
    await expand()
    expect(options()).toHaveLength(6)
  })

  it('does not request PDF bytes for a missing report', async () => {
    server.location.pathname = '/pdf/documents/missing'
    server.read.mockRejectedValue({ response: { status: 404 } })
    await mount()
    expect(container.textContent).toContain('报告不存在')
    expect(server.download).not.toHaveBeenCalled()
  })

  it('keeps metadata accessible but does not load PDF without download permission', async () => {
    server.downloadAllowed = false
    await mount()
    await expand()
    expect(options()).toHaveLength(6)
    expect(container.textContent).toContain('没有 PDF 预览或下载权限')
    expect(server.download).not.toHaveBeenCalled()
  })

  it.each(['revision', 'document'])('blocks unavailable %s integrity', async scope => {
    if (scope === 'revision') report.revisions[5].integrity_state = 'unavailable'
    else report.integrity_state = 'hold'
    await mount()
    expect(container.textContent).toContain('此版本当前不可预览或下载')
    expect(server.download).not.toHaveBeenCalled()
  })

  it('supports arrow keys, Home/End, Enter/Space, Escape, Tab and blur without native popup', async () => {
    await mount()
    combo().focus()
    await key('ArrowDown')
    expect(combo().getAttribute('aria-expanded')).toBe('true')
    await key('End')
    expect(combo().getAttribute('aria-activedescendant')).toBe(options()[5].id)
    await key('Home')
    await key('ArrowDown')
    await key('ArrowUp')
    await key('Escape')
    expect(options()).toHaveLength(0)
    expect(server.navigate).not.toHaveBeenCalled()
    await key(' ')
    await key('ArrowDown')
    await key('Enter')
    expect(server.navigate.mock.lastCall![0].search.revision).toBe('revision-5')
    await followNavigation()
    await key('ArrowUp')
    await key('Tab')
    expect(options()).toHaveLength(0)
    await expand()
    await settle(() => container.querySelector<HTMLAnchorElement>('a[href="/pdf/documents"]')!.focus())
    expect(options()).toHaveLength(0)
  })

  it('shows a failed download without automatically retrying', async () => {
    server.download.mockRejectedValue(new Error('PDF暂不可用'))
    await mount()
    expect(container.textContent).toContain('PDF 加载失败')
    expect(server.download).toHaveBeenCalledTimes(1)
  })
})
