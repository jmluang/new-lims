// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, StrictMode } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { SigningOperation, SigningRequestDetail } from '../handwrittenApi'
import { clearToasts } from '../../../lib/toast'

const server = vi.hoisted(() => ({
  request: null as SigningRequestDetail | null,
  operation: null as SigningOperation | null,
  submit: vi.fn(), readRequest: vi.fn(), readOperation: vi.fn(),
}))
vi.mock('../../auth/useCurrentUser', () => ({ useEffectivePermissions: () => ({ data: { resources: {} } }) }))
vi.mock('../PdfPlacementWorkspace', () => ({
  PdfPlacementWorkspace: ({ signaturePreview, file }: { signaturePreview?: string | null; file?: Blob | null }) =>
    <div data-testid="pdf-preview" data-signature={signaturePreview ?? ''} data-file-size={file?.size ?? 0} />,
}))
vi.mock('../handwrittenApi', async (original) => ({
  ...await original<typeof import('../handwrittenApi')>(),
  fetchAssignedSigningRequests: async () => [],
  fetchSigningRequest: server.readRequest,
  fetchSigningOperation: server.readOperation,
  submitSignatureAppearance: server.submit,
}))

const requestKey = ['pdf', 'handwritten', 'request', 'task-1']
const operationKey = ['pdf', 'handwritten', 'operation', 'operation-1']
let client: QueryClient
let root: Root
let container: HTMLDivElement
let strokes: WeakMap<HTMLCanvasElement, number>

function operation(state: string): SigningOperation {
  return { operation_uuid: 'operation-1', action: 'fill_signature_field', state,
    stage: state === 'processing' ? 'java_call' : 'done', status_url: '/operation-1' }
}
function request(status = 'available', currentOperation: SigningOperation | null = null): SigningRequestDetail {
  return { request_uuid: 'task-1', status, sequence: 1, semantic_role: 'inspector',
    report_number: 'RPT-RECOVERY', pdf_signature_role: 'certification_p2',
    field: { field_uuid: 'field-1', field_name: 'lims_inspector_g1', slots: [{ page_index: 0,
      widget_index: 0, normalized_rect: { x: '0.12', y: '0.72', width: '0.16', height: '0.055' } }] },
    revision: { revision_uuid: 'source-1', sha256: 'source-hash', file_size: 1 },
    operation: currentOperation, certificate_subject: 'organization' }
}
async function settle(action?: () => void) {
  await act(async () => { action?.(); await new Promise((resolve) => setTimeout(resolve, 20)) })
}
function button(text: string) {
  const result = [...container.querySelectorAll('button')].find((item) => item.textContent?.trim() === text)
  expect(result, `Missing button: ${text}`).toBeDefined()
  return result!
}
function canvas() {
  return container.querySelector<HTMLCanvasElement>('canvas[aria-label="手写签名区域"]')
}
async function draw(surface: HTMLCanvasElement) {
  await settle(() => {
    surface.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, clientX: 20, clientY: 20 }))
    surface.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: 100, clientY: 60 }))
    surface.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }))
  })
}
async function enterPassword() {
  await settle(() => {
    const input = container.querySelector<HTMLInputElement>('input[type="password"]')!
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, 'fixture-password')
    input.dispatchEvent(new Event('input', { bubbles: true }))
  })
}
async function confirm() {
  await settle(() => button('确认身份并签名').click())
  await enterPassword()
  expect(button('确认并签名').disabled).toBe(false)
  await settle(() => button('确认并签名').click())
}
async function mount() {
  const { PdfHandwrittenSigningPage } = await import('../PdfHandwrittenSigningPage')
  await settle(() => root.render(<StrictMode><QueryClientProvider client={client}><PdfHandwrittenSigningPage /></QueryClientProvider></StrictMode>))
}

beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => setTimeout(() => callback(0), 0))
  vi.stubGlobal('cancelAnimationFrame', (id: number) => clearTimeout(id))
  window.history.replaceState(null, '', '/pdf/handwritten-signing?request=task-1#sign')
  localStorage.clear()
  strokes = new WeakMap()
  // Keep the real SignaturePad lifecycle; only browser canvas primitives are mocked.
  vi.spyOn(HTMLCanvasElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLCanvasElement) {
    return { x: 0, y: 0, left: 0, top: 0, right: this.width, bottom: this.height,
      width: this.width, height: this.height, toJSON: () => ({}) }
  })
  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(function (this: HTMLCanvasElement) {
    return { clearRect: () => strokes.set(this, 0), beginPath: () => {}, moveTo: () => {}, lineTo: () => {},
      stroke: () => strokes.set(this, (strokes.get(this) ?? 0) + 1), drawImage: () => {},
      getImageData: () => ({ width: 8, height: 8, data: new Uint8ClampedArray([0, 0, 0, 255, ...Array(252).fill(0)]) }),
    } as unknown as CanvasRenderingContext2D
  })
  vi.spyOn(HTMLCanvasElement.prototype, 'toDataURL').mockReturnValue('data:image/png;base64,aW5r')
  vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation(function (this: HTMLCanvasElement, callback) {
    callback(new Blob([`ink:${strokes.get(this) ?? 0}`], { type: 'image/png' }))
  })
  server.request = request()
  server.operation = operation('processing')
  server.submit.mockReset().mockImplementation(async () => {
    server.operation = { ...operation('processing'),
      operation_uuid: server.submit.mock.calls.length === 1 ? 'operation-1' : 'operation-2' }
    server.request = request('signing', server.operation)
    return server.operation
  })
  server.readRequest.mockReset().mockImplementation(async () => server.request)
  server.readOperation.mockReset().mockImplementation(async () => server.operation)
  client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity,
    refetchOnWindowFocus: false }, mutations: { retry: false } } })
  client.setQueryData(['pdf', 'handwritten', 'requests'], [])
  client.setQueryData(requestKey, server.request)
  client.setQueryData(operationKey, server.operation)
  client.setQueryData(['pdf', 'handwritten', 'revision', 'source-1'], new Blob(['pdf']))
  container = document.createElement('div')
  document.body.append(container)
  root = createRoot(container)
})
afterEach(async () => {
  await act(async () => root.unmount())
  client.clear()
  clearToasts()
  container.remove()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('signing failure recovery interactions', () => {
  it('preserves the actual ink through processing and a retryable failure', async () => {
    await mount()
    const original = canvas()!
    await draw(original)
    expect(button('确认身份并签名').disabled).toBe(false)
    await confirm()
    expect(server.submit).toHaveBeenCalledTimes(1)
    expect(canvas()).toBe(original)
    const savedStrokes = strokes.get(original)
    await draw(original)
    expect(strokes.get(original)).toBe(savedStrokes)

    server.operation = operation('failed')
    server.request = request('available', server.operation)
    await settle(() => {
      client.setQueryData(operationKey, server.operation)
      client.setQueryData(requestKey, server.request)
    })
    expect(canvas()).toBe(original)
    expect(button('确认身份并签名').disabled).toBe(false)
    await confirm()
    expect(server.submit).toHaveBeenCalledTimes(2)
    expect(server.submit.mock.calls[1][0].appearance.size).toBe(server.submit.mock.calls[0][0].appearance.size)
  })

  it('unlocks without reload when the request reports an administrator recovery', async () => {
    server.operation = operation('manual_review')
    server.request = request('manual_review', server.operation)
    client.setQueryData(operationKey, server.operation)
    client.setQueryData(requestKey, server.request)
    await mount()
    expect(canvas() === null || canvas()!.getAttribute('aria-disabled') === 'true').toBe(true)
    server.readOperation.mockClear()

    server.operation = { ...operation('failed'), error_code: 'MANUAL_CONFIRMED_NO_PRIVATE_KEY' }
    server.request = request('available', server.operation)
    // Simulate the existing request poll returning the administrator's decision.
    await settle(() => client.setQueryData(requestKey, server.request))
    await settle()
    expect(server.readOperation).toHaveBeenCalled()
    expect(canvas()).not.toBeNull()
    expect(canvas()!.getAttribute('aria-disabled')).not.toBe('true')
    await draw(canvas()!)
    await confirm()
    expect(server.submit).toHaveBeenCalledTimes(1)
  })

  it('retires the ink and displays the adopted revision when manual review completes', async () => {
    server.operation = operation('manual_review')
    server.request = request('manual_review', server.operation)
    client.setQueryData(operationKey, server.operation)
    client.setQueryData(requestKey, server.request)
    await mount()

    const signed = new Blob(['adopted signed revision'])
    client.setQueryData(['pdf', 'handwritten', 'revision', 'signed-1'], signed)
    server.operation = { ...operation('completed'), result_revision_uuid: 'signed-1' }
    server.request = { ...request('signed', server.operation),
      revision: { revision_uuid: 'signed-1', sha256: 'signed-hash', file_size: signed.size } }
    await settle(() => client.setQueryData(requestKey, server.request))
    await settle()
    expect(canvas()).toBeNull()
    expect(container.textContent).toContain('签名已完成')
    expect(container.querySelector('[data-testid="pdf-preview"]')?.getAttribute('data-file-size')).toBe(String(signed.size))
    expect(container.querySelector('[data-testid="pdf-preview"]')?.getAttribute('data-signature')).toBe('')
  })

  it('does not overwrite a resolved operation with a late manual-review snapshot', async () => {
    const oldRequest = request('manual_review', operation('manual_review'))
    server.operation = operation('failed')
    server.request = request('available', server.operation)
    client.setQueryData(operationKey, server.operation)
    client.setQueryData(requestKey, oldRequest)
    await mount()
    await settle()
    expect(client.getQueryData<SigningOperation>(operationKey)?.state).toBe('failed')
    expect(canvas()).not.toBeNull()
    expect(canvas()?.getAttribute('aria-disabled')).not.toBe('true')
    expect(server.readRequest).toHaveBeenCalled()
  })

  it('locks an open confirmation dialog when the request becomes unavailable', async () => {
    await mount()
    await draw(canvas()!)
    await settle(() => button('确认身份并签名').click())
    await enterPassword()
    expect(button('确认并签名').disabled).toBe(false)

    server.operation = operation('manual_review')
    server.request = request('manual_review', server.operation)
    await settle(() => {
      client.setQueryData(operationKey, server.operation)
      client.setQueryData(requestKey, server.request)
    })
    expect(button('确认并签名').disabled).toBe(true)
    await settle(() => container.querySelector('input[type="password"]')!
      .dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })))
    expect(server.submit).not.toHaveBeenCalled()
  })
})
