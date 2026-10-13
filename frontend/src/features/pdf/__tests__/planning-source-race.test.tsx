// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { InspectedSigningSource } from '../handwrittenApi'

const server = vi.hoisted(() => ({ inspect: vi.fn(), finalize: vi.fn() }))
vi.mock('../../auth/useCurrentUser', () => ({ useEffectivePermissions: () => ({
  data: { resources: { 'pdf.workflow': { actions: { create: true } } } },
}) }))
vi.mock('../PdfPlacementWorkspace', () => ({ PdfPlacementWorkspace: () => null }))
vi.mock('../handwrittenApi', async (original) => ({
  ...await original<typeof import('../handwrittenApi')>(),
  fetchPlanningOptions: async () => ({ assignees: [], policies: [] }),
  inspectSigningSource: server.inspect,
  confirmAndFinalizeSigningSource: server.finalize,
}))

let root: Root
let container: HTMLDivElement
let client: QueryClient
async function settle(action?: () => void) {
  await act(async () => { action?.(); await new Promise((resolve) => setTimeout(resolve, 20)) })
}
function button(text: string) {
  const found = [...container.querySelectorAll('button')].find((item) => item.textContent?.trim() === text)
  expect(found, `Missing button: ${text}`).toBeDefined()
  return found!
}
async function selectFile(name: string) {
  await settle(() => {
    const input = container.querySelector<HTMLInputElement>('input[type="file"]')!
    Object.defineProperty(input, 'files', { configurable: true, value: [new File([name], name, { type: 'application/pdf' })] })
    input.dispatchEvent(new Event('change', { bubbles: true }))
  })
}
beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  window.history.replaceState(null, '', '/pdf/handwritten-signing#plan')
  server.inspect.mockReset()
  server.finalize.mockReset().mockResolvedValue({
    revision: { revision_uuid: 'revision-a', revision_role: 'finalized_unsigned' }, file: new Blob(['pdf-a']),
  })
  client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
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

describe('planning source ownership', () => {
  it('can inspect file B immediately and keeps its result when file A finishes later', async () => {
    const pending: Record<string, (source: InspectedSigningSource) => void> = {}
    server.inspect.mockImplementation((file: File) => new Promise<InspectedSigningSource>((resolve) => { pending[file.name] = resolve }))
    const { PdfHandwrittenSigningPage } = await import('../PdfHandwrittenSigningPage')
    await settle(() => root.render(<QueryClientProvider client={client}><PdfHandwrittenSigningPage /></QueryClientProvider>))
    await selectFile('RPT-A.pdf')
    await settle(() => button('检查 PDF').click())
    await selectFile('RPT-B.pdf')
    await settle(() => button('检查 PDF').click())
    expect(server.inspect).toHaveBeenCalledTimes(2)
    await settle(() => pending['RPT-B.pdf']({ source_uuid: 'source-b', sha256: 'hash-b', file_size: 10,
      page_count: 1, status: 'inspected', inspection: null }))
    await settle(() => pending['RPT-A.pdf']({ source_uuid: 'source-a', sha256: 'hash-a', file_size: 10,
      page_count: 1, status: 'inspected', inspection: null }))
    expect(container.textContent).toContain('hash-b')
    expect(container.textContent).not.toContain('hash-a')
    await settle(() => {
      const input = container.querySelector<HTMLInputElement>('input[maxlength="128"]')!
      Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, 'RPT-B')
      input.dispatchEvent(new Event('input', { bubbles: true }))
    })
    await settle(() => button('确认报告编号并生成定稿').click())
    expect(server.finalize).toHaveBeenCalledWith({ sourceUuid: 'source-b', reportNumber: 'RPT-B' })
  })

  it('locks source and report identity while finalization is pending', async () => {
    server.inspect.mockResolvedValue({ source_uuid: 'source-a', sha256: 'hash-a', file_size: 10,
      page_count: 1, status: 'inspected', inspection: null })
    server.finalize.mockImplementation(() => new Promise(() => {}))
    const { PdfHandwrittenSigningPage } = await import('../PdfHandwrittenSigningPage')
    await settle(() => root.render(<QueryClientProvider client={client}><PdfHandwrittenSigningPage /></QueryClientProvider>))
    await selectFile('XDP1001.pdf')
    await settle(() => button('检查 PDF').click())
    await settle(() => button('确认报告编号并生成定稿').click())
    expect(server.finalize).toHaveBeenCalledWith({ sourceUuid: 'source-a', reportNumber: 'XDP1001' })
    expect(container.querySelector<HTMLInputElement>('input[type="file"]')!.disabled).toBe(true)
    expect(container.querySelector<HTMLInputElement>('input[maxlength="128"]')!.disabled).toBe(true)
  })

  it('does not finalize file A under file B after a late inspection response', async () => {
    let finishInspection!: (source: InspectedSigningSource) => void
    server.inspect.mockImplementation(() => new Promise<InspectedSigningSource>((resolve) => { finishInspection = resolve }))
    const { PdfHandwrittenSigningPage } = await import('../PdfHandwrittenSigningPage')
    await settle(() => root.render(<QueryClientProvider client={client}><PdfHandwrittenSigningPage /></QueryClientProvider>))
    await selectFile('RPT-A.pdf')
    await settle(() => button('检查 PDF').click())
    expect(server.inspect.mock.calls[0][0].name).toBe('RPT-A.pdf')
    await selectFile('RPT-B.pdf')
    await settle(() => finishInspection({ source_uuid: 'source-a', sha256: 'hash-a', file_size: 10,
      page_count: 1, status: 'inspected', inspection: null }))
    expect(container.textContent).toContain('RPT-B.pdf')
    await settle(() => {
      const reportNumber = container.querySelector<HTMLInputElement>('input[maxlength="128"]')!
      Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(reportNumber, 'RPT-B')
      reportNumber.dispatchEvent(new Event('input', { bubbles: true }))
    })
    await settle(() => button('确认报告编号并生成定稿').click())
    expect(server.finalize).not.toHaveBeenCalled()
  })
})
