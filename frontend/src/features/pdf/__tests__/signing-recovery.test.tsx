import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'

vi.stubGlobal('window', { location: { hash: '#sign', search: '?request=task-1' } })
vi.stubGlobal('localStorage', { getItem: () => null })
vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => ({ data: { resources: {} } }),
}))
vi.mock('../PdfPlacementWorkspace', () => ({ PdfPlacementWorkspace: () => null }))
vi.mock('../SignaturePad', () => ({
  SignaturePad: ({ disabled }: { disabled?: boolean }) => <div>{disabled ? 'Locked signature pad' : 'Editable signature pad'}</div>,
}))

async function markup(status: string, revision: object | null, operation?: object) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
  client.setQueryData(['pdf', 'handwritten', 'requests'], [])
  client.setQueryData(['pdf', 'handwritten', 'request', 'task-1'], {
    request_uuid: 'task-1', status, sequence: 1, semantic_role: 'inspector',
    report_number: 'XPD-RECOVERY-1',
    field: { field_uuid: 'field-1', field_name: 'lims_inspector_g1', slots: [] }, revision,
    operation: operation ?? null,
  })
  if (operation) client.setQueryData(['pdf', 'handwritten', 'operation', 'operation-1'], operation)
  const { PdfHandwrittenSigningPage } = await import('../PdfHandwrittenSigningPage')
  return renderToStaticMarkup(
    <QueryClientProvider client={client}><PdfHandwrittenSigningPage /></QueryClientProvider>,
  )
}

describe('signing task recovery', () => {
  it('opens a signed notification as a completed task without another signature form', async () => {
    const html = await markup('signed', { revision_uuid: 'signed-revision' })
    expect(html).not.toContain('Editable signature pad')
    expect(html).not.toContain('确认身份并签名')
    expect(html).toContain('签名已完成')
    expect(html).toContain('XPD-RECOVERY-1')
  })

  it('resumes the persisted operation after refreshing during signing', async () => {
    const html = await markup('signing', { revision_uuid: 'source-revision' }, {
      operation_uuid: 'operation-1', state: 'processing', stage: 'java_call',
    })
    expect(html).toContain('正在生成签名')
    expect(html).toContain('Locked signature pad')
    expect(html).not.toContain('Editable signature pad')
  })

  it('shows a waiting task without crashing when its source revision is not ready', async () => {
    const html = await markup('pending', null)
    expect(html).toContain('等待前一位')
    expect(html).not.toContain('Editable signature pad')
  })

  it('does not offer signing for a cancelled notification', async () => {
    const html = await markup('cancelled', { revision_uuid: 'source-revision' })
    expect(html).not.toContain('Editable signature pad')
    expect(html).toContain('已取消')
  })
})
