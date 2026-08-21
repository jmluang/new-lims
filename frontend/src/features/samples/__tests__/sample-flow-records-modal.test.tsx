import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import { SampleFlowRecordsModal } from '../SampleListPage'

vi.mock('@tanstack/react-router', () => ({
  useNavigate: () => vi.fn(),
}))

vi.mock('../auth/useCurrentUser', () => ({}))

describe('SampleFlowRecordsModal', () => {
  it('renders only the flow ledger table fields inside the modal', () => {
    const html = renderToStaticMarkup(
      <SampleFlowRecordsModal
        card={{
          sample: { client_company: '中山市客户公司', sample_no: 'S-001', sample_name: '灯具', model: 'LD-100', status: 'pending' },
          flows: [
            {
              id: 1,
              action_type: 'receive',
              action_time: '2026-06-12 08:30:00',
              holder_from: '客户',
              holder_to: '样品室',
              location_from: '前台',
              location_to: '样品室',
            },
          ],
        }}
        error={null}
        isLoading={false}
        onClose={() => {}}
        open
      />,
    )

    expect(html).toContain('流转记录')
    expect(html).toContain('max-w-5xl')
    expect(html).toContain('客户名称')
    expect(html).toContain('样品名称')
    expect(html).toContain('时间')
    expect(html).toContain('2026-06-12 08:30:00')
    expect(html).not.toContain('样品流转记录流水单')
    expect(html).toContain('打印流转卡')
  })
})
