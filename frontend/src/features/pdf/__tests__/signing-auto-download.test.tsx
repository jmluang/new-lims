// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, StrictMode } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn(), getUri: vi.fn(({ url }: { url: string }) => url) }))
vi.mock('../../../lib/api', () => ({ api: server }))
vi.mock('../api', async original => ({
  ...await original<typeof import('../api')>(),
  useSigningOptions: () => ({ data: { data: { certificate_templates: [], digital_signatures: [{ id: 1, name: '测试章', is_default: true }], perforation_stamps: [{ id: 2, name: '测试骑缝章', is_default: true }], function_stamps: [] }, meta: { signing_enabled: true, photometric_removal_enabled: false, max_upload_kb: 20480 } }, isPending: false, isError: false }),
  useAuthedObjectUrl: () => null,
}))

let root: Root, container: HTMLDivElement, client: QueryClient
const click = vi.fn()
const completed = { download_url: '/api/pdf/files/20/temporary-download?expires=9999999999&signature=fixture', download_name: 'TEST-RUST-existing-正本.pdf', sha256: 'a'.repeat(64), file_size: 191916, report_number: 'TEST-RUST-existing' }
async function settle(action?: () => void) { await act(async () => { action?.(); await vi.advanceTimersByTimeAsync(30) }) }
async function mount() {
  const { PdfSigningPage } = await import('../PdfSigningPage')
  await settle(() => root.render(<StrictMode><QueryClientProvider client={client}><PdfSigningPage /></QueryClientProvider></StrictMode>))
}
async function upload(count = 1) {
  const input = container.querySelector<HTMLInputElement>('input[type=file]')!
  Object.defineProperty(input, 'files', { configurable: true, value: Array.from({ length: count }, (_, n) => new File(['%PDF saved fixture'], `TEST-RUST-local-${n}.pdf`, { type: 'application/pdf' })) })
  await settle(() => input.dispatchEvent(new Event('change', { bubbles: true })))
}
function start() { return [...container.querySelectorAll<HTMLButtonElement>('button')].find(b => b.textContent?.startsWith('开始处理'))! }
function manual() { return container.querySelector<HTMLAnchorElement>('a[download]') }
beforeEach(() => {
  vi.useFakeTimers()
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  server.post.mockReset().mockResolvedValue({ status: 200, data: { data: completed } })
  server.get.mockReset();server.getUri.mockClear();click.mockReset()
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) { click(this.getAttribute('href'), this.download) })
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  container = document.createElement('div');document.body.append(container);root = createRoot(container)
})
afterEach(async () => { await act(async () => root.unmount());client.clear();container.remove();vi.restoreAllMocks();vi.unstubAllGlobals();vi.useRealTimers() })

describe('signing desk completed-file download', () => {
  it('starts once after synchronous completion and keeps the original manual link after six seconds', async () => {
    await mount();await upload();await settle(() => start().click())
    expect(server.post).toHaveBeenCalledTimes(1)
    expect(click).toHaveBeenCalledExactlyOnceWith(completed.download_url, completed.download_name)
    for (let n = 0; n < 7; n++) await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    expect(manual()?.getAttribute('href')).toBe(completed.download_url)
    expect(container.textContent).not.toContain('秒后自动收起')
    expect(container.textContent).toContain('若未收到文件')
    await settle(() => manual()!.click())
    expect(click).toHaveBeenCalledTimes(2)
    expect(server.post).toHaveBeenCalledTimes(1)
    await mount();expect(click).toHaveBeenCalledTimes(2)
  })
  it('waits for delayed completion and does not download while the signing response is pending', async () => {
    let finish!: (r: unknown) => void
    server.post.mockImplementation(() => new Promise(resolve => { finish = resolve }))
    await mount();await upload();await settle(() => start().click())
    for (let n = 0; n < 10; n++) await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    expect(click).not.toHaveBeenCalled();expect(manual()).toBeNull()
    await settle(() => finish({ status: 200, data: { data: completed } }))
    expect(click).toHaveBeenCalledTimes(1)
    await mount();await mount();expect(click).toHaveBeenCalledTimes(1)
  })
  it('preserves successful signing and manual retry if the browser throws while starting auto download', async () => {
    click.mockImplementationOnce(() => { throw new Error('download denied') })
    await mount();await upload();await settle(() => start().click())
    expect(container.textContent).toContain('处理成功')
    expect(container.textContent).toContain('自动下载未能启动')
    expect(manual()?.getAttribute('href')).toBe(completed.download_url)
    await settle(() => manual()!.click());expect(server.post).toHaveBeenCalledTimes(1)
  })
  it.each([403, 404, 429, 500])('never downloads or reports completion when the signing API fails with %i', async status => {
    server.post.mockRejectedValue({ response: { status, data: { message: 'sign failed' } } })
    await mount();await upload();await settle(() => start().click())
    expect(click).not.toHaveBeenCalled();expect(manual()).toBeNull();expect(container.textContent).toContain('处理失败')
  })
  it.each([{}, { ...completed, download_url: '' }, { ...completed, download_url: 'javascript:alert(1)' }])('rejects incomplete or unsafe completion data without navigating', async data => {
    server.post.mockResolvedValue({ status: 200, data: { data } })
    await mount();await upload();await settle(() => start().click())
    expect(click).not.toHaveBeenCalled();expect(manual()).toBeNull();expect(container.textContent).toContain('未触发下载')
  })
  it('does not mistake an undefined 202 operation envelope for completed signing or invent a polling contract', async () => {
    server.post.mockResolvedValue({ status: 202, data: { data: { operation_uuid: 'operation-1', state: 'processing' } } })
    await mount();await upload();await settle(() => start().click())
    expect(click).not.toHaveBeenCalled();expect(server.get).not.toHaveBeenCalled();expect(container.textContent).toContain('未触发下载')
    await mount();expect(click).not.toHaveBeenCalled()
  })
  it('starts one download for each completed batch item and does not repeat on rerender', async () => {
    await mount();await upload(3);await settle(() => start().click())
    expect(server.post).toHaveBeenCalledTimes(3);expect(click).toHaveBeenCalledTimes(3)
    await mount();await mount();expect(click).toHaveBeenCalledTimes(3)
  })
})
