// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, expect, it, vi } from 'vitest'
import type { SigningOperation, SigningRequestDetail } from '../handwrittenApi'
import { queryClient as applicationClient } from '../../../lib/query-client'

const server = vi.hoisted(() => ({ readRequest: vi.fn(), readOperation: vi.fn(), download: vi.fn() }))
vi.mock('../../auth/useCurrentUser', () => ({ useEffectivePermissions: () => ({ data: { resources: {} } }) }))
vi.mock('../PdfPlacementWorkspace', () => ({ PdfPlacementWorkspace: () => null }))
vi.mock('../SignaturePad', () => ({ SignaturePad: () => null }))
vi.mock('../handwrittenApi', async (original) => ({
  ...await original<typeof import('../handwrittenApi')>(),
  fetchAssignedSigningRequests: async () => [], fetchSigningRequest: server.readRequest,
  fetchSigningOperation: server.readOperation, downloadRevision: server.download,
}))
async function settle(action?: () => void) {
  await act(async () => { action?.(); await new Promise(resolve => setTimeout(resolve, 40)) })
}
afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals(); vi.restoreAllMocks() })

it.each([0, 31_000])('does not fetch the completed PDF again when request detail catches up after %i ms', async (delay) => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  window.history.replaceState(null, '', '/pdf/handwritten-signing?request=task-1#sign')
  const processing: SigningOperation = { operation_uuid: 'operation-1', action: 'fill_signature_field', state: 'processing', stage: 'java_call', status_url: '' }
  const completed: SigningOperation = { ...processing, state: 'completed', result_revision_uuid: 'signed-1' }
  const request: SigningRequestDetail = { request_uuid: 'task-1', status: 'signing', sequence: 1, semantic_role: 'inspector',
    report_number: 'RPT-1', pdf_signature_role: 'certification_p2', certificate_subject: 'organization',
    field: { field_uuid: 'field-1', field_name: 'lims_inspector_g1', slots: [] },
    revision: { revision_uuid: 'source-1', sha256: 'hash-1', file_size: 4 }, operation: processing }
  server.readRequest.mockReset().mockResolvedValue(request)
  server.readOperation.mockReset().mockResolvedValue(processing)
  server.download.mockReset().mockImplementation(async () => new Blob(['%PDF']))
  const client = new QueryClient({ defaultOptions: applicationClient.getDefaultOptions() })
  client.setQueryData(['pdf', 'handwritten', 'request', 'task-1'], request)
  client.setQueryData(['pdf', 'handwritten', 'operation', 'operation-1'], processing)
  const container = document.createElement('div')
  document.body.append(container)
  const root = createRoot(container)
  try {
    const { PdfHandwrittenSigningPage } = await import('../PdfHandwrittenSigningPage')
    await settle(() => root.render(<QueryClientProvider client={client}><PdfHandwrittenSigningPage /></QueryClientProvider>))
    let finishRequest!: (request: SigningRequestDetail) => void
    server.readRequest.mockImplementation(() => new Promise<SigningRequestDetail>(resolve => { finishRequest = resolve }))
    server.readOperation.mockResolvedValue(completed)
    await settle(() => client.setQueryData(['pdf', 'handwritten', 'operation', 'operation-1'], completed))
    expect(server.download.mock.calls.filter(([uuid]) => uuid === 'signed-1')).toHaveLength(1)
    expect(finishRequest).toBeDefined()
    if (delay) {
      vi.useFakeTimers({ toFake: ['Date'] })
      vi.setSystemTime(Date.now() + delay)
    }
    await settle(() => finishRequest({ ...request, status: 'signed', operation: completed,
      revision: { revision_uuid: 'signed-1', sha256: 'hash-signed', file_size: 4 } }))
    expect(server.download.mock.calls.filter(([uuid]) => uuid === 'signed-1')).toHaveLength(1)
  } finally {
    await act(async () => root.unmount())
    client.clear()
    container.remove()
  }
})

it('downloads a new revision UUID while reusing a previously viewed revision after 30 seconds', async () => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  window.history.replaceState(null, '', '/pdf/handwritten-signing?request=task-1#sign')
  const request: SigningRequestDetail = { request_uuid: 'task-1', status: 'available', sequence: 1, semantic_role: 'inspector',
    report_number: 'RPT-1', pdf_signature_role: 'certification_p2', certificate_subject: 'organization', field: null,
    revision: { revision_uuid: 'source-1', sha256: 'hash-1', file_size: 4 }, operation: null }
  server.readRequest.mockReset().mockResolvedValue(request)
  server.readOperation.mockReset()
  server.download.mockReset().mockImplementation(async () => new Blob(['%PDF']))
  const client = new QueryClient({ defaultOptions: applicationClient.getDefaultOptions() })
  const key = ['pdf', 'handwritten', 'request', 'task-1']
  client.setQueryData(key, request)
  const container = document.createElement('div')
  document.body.append(container)
  const root = createRoot(container)
  try {
    const { PdfHandwrittenSigningPage } = await import('../PdfHandwrittenSigningPage')
    await settle(() => root.render(<QueryClientProvider client={client}><PdfHandwrittenSigningPage /></QueryClientProvider>))
    await settle(() => client.setQueryData(key, { ...request, revision: { revision_uuid: 'source-2', sha256: 'hash-2', file_size: 4 } }))
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(Date.now() + 31_000)
    await settle(() => client.setQueryData(key, request))
    expect(server.download.mock.calls.filter(([uuid]) => uuid === 'source-1')).toHaveLength(1)
    expect(server.download.mock.calls.filter(([uuid]) => uuid === 'source-2')).toHaveLength(1)
  } finally {
    await act(async () => root.unmount())
    client.clear()
    container.remove()
  }
})
