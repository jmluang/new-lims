import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import type { ReactNode } from 'react'
import type { ReportSummary } from '../lm79Api'
import { Button } from '../../system/shared'

vi.mock('@tanstack/react-router', () => ({
  useNavigate: () => vi.fn(),
  Link: ({ to, params, children, ...props }: { to: string; params?: { reportId: string }; children: ReactNode }) => <a href={to.replace('$reportId', params?.reportId ?? '')} {...props}>{children}</a>,
}))
vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => ({ data: { resources: { lm79_reports: { actions: { read: true, create: true, update: true, delete: true, print: true } } } } }),
}))

async function markup(locked: boolean) {
  const report: ReportSummary = { id: 7, report_number: 'REPORT-007', sample_no: 'S-007', order_no: 'ORDER-007', product_name: 'Precision lamp', model: 'L-30', applicant: 'A&B Lighting', created_by_name: 'Report Editor', attachment_count: 2, spectrum_point_count: 401, locked, document_uuid: locked ? 'document-007' : null, updated_at: '2026-10-04T10:00:00Z' }
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  await client.prefetchQuery({ queryKey: ['lm79-reports', '', '', 1, 15], queryFn: async () => ({ data: [report], meta: { total: 1, current_page: 1, per_page: 15 } }) })
  const { Lm79ReportListPage } = await import('../Lm79ReportListPage')
  return renderToStaticMarkup(<QueryClientProvider client={client}><Lm79ReportListPage /></QueryClientProvider>)
}

describe('LM-79 report management list', () => {
  it('uses the same primary button styles as other management lists', async () => {
    const html = await markup(false)
    const createButton = html.match(/<button[^>]*class="([^"]*)"[^>]*>[\s\S]*?新建报告<\/button>/)
    const sharedClass = renderToStaticMarkup(<Button variant="primary">Create</Button>).match(/class="([^"]*)"/)?.[1]
    expect(createButton).not.toBeNull()
    expect(createButton?.[1]).toBe(sharedClass)
  })
  it('shows effective report details, data counts and responsive list layouts', async () => {
    const html = await markup(false)
    for (const value of ['REPORT-007', 'S-007', 'ORDER-007', 'Precision lamp', 'L-30', 'A&amp;B Lighting', 'Report Editor', '401', '报告状态', '编制报告']) expect(html).toContain(value)
    expect(html.match(/<th\s/g)).toHaveLength(8)
    expect(html).toContain('<article')
    expect(html).toContain('md:hidden')
  })

  it('shows handoff without claiming publication or offering draft-only actions', async () => {
    const html = await markup(true)
    expect(html).toContain('已交接签署')
    expect(html).toContain('查看报告')
    expect(html).not.toContain('已签发')
    expect(html).not.toContain('下载草稿 PDF')
    expect(html).not.toContain('删除草稿')
  })
})
