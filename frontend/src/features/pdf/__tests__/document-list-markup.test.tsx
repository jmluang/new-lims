import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import type { SigningDocument } from '../handwrittenApi'

const document: SigningDocument = {
  document_uuid: '33f5dde4-539a-416c-b966-db69e2d80de6',
  report_number: 'XDP2025120133',
  status: 'draft',
  stage: 'awaiting_signature',
  integrity_state: 'ok',
  evidence_hold_state: 'none',
  has_running_work: false,
  workflow_uuid: 'w-1',
  workflow_status: 'signing',
  signers: [
    { sequence: 1, semantic_role: 'inspector', assigned_user_id: 2, assigned_user_name: '张三', status: 'signed', act_status: 'completed' },
    { sequence: 2, semantic_role: 'reviewer', assigned_user_id: 3, assigned_user_name: '李四', status: 'available', act_status: 'planned' },
  ],
  revisions: [{ revision_uuid: 'r1', revision_number: 1, revision_role: 'finalized_unsigned', integrity_state: 'ready' }],
  created_by_id: 1,
  is_owner: true,
  created_at: '2026-08-16T15:25:42.000000Z',
}

vi.mock('../handwrittenApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../handwrittenApi')>()),
  fetchSigningDocuments: vi.fn(async () => ({
    data: [document],
    meta: { current_page: 1, per_page: 15, total: 1 },
  })),
}))

vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => ({
    data: { resources: { 'pdf.document': { actions: { read: true, update: true, delete: true }, fields: {} } } },
  }),
}))

async function markup() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  await client.prefetchQuery({
    queryKey: ['pdf', 'documents', '', 1, 15],
    queryFn: async () => ({ data: [document], meta: { current_page: 1, per_page: 15, total: 1 } }),
  })
  const { PdfDocumentListPage } = await import('../PdfDocumentListPage')

  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <PdfDocumentListPage />
    </QueryClientProvider>,
  )
}

describe('PdfDocumentListPage markup', () => {
  it('gives every table cell the shared padding the other list pages use', async () => {
    const html = await markup()
    // (?=[\s>]) so <thead> is not mistaken for a <th>.
    const headerCells = html.match(/<th(?=[\s>])[^>]*>/g) ?? []
    const bodyCells = html.match(/<td(?=[\s>])[^>]*>/g) ?? []

    expect(headerCells.length).toBeGreaterThan(0)
    expect(bodyCells.length).toBeGreaterThan(0)
    // Without these the row collapses against the header and the columns stop
    // lining up, which is exactly how this table first shipped.
    for (const cell of [...headerCells, ...bodyCells]) {
      expect(cell).toContain('px-3')
      expect(cell).toContain('py-2')
    }
  })

  it('styles the header row like the other list pages', async () => {
    const html = await markup()

    expect(html).toContain('bg-slate-50')
    expect(html).toMatch(/<thead[^>]*text-left/)
  })

  it('renders a mobile card list, since the table is desktop-only', async () => {
    const html = await markup()

    expect(html).toContain('md:hidden')
    expect(html).toContain('XDP2025120133')
  })

  it('shows each signer with their own status', async () => {
    const html = await markup()

    expect(html).toContain('张三')
    expect(html).toContain('李四')
    expect(html).toContain('已签署')
    expect(html).toContain('待签署')
  })
})
