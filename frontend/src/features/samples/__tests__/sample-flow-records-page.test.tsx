import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import { SampleFlowRecordsPage } from '../SampleFlowRecordsPage'
import { buildSampleFlowRecordParams, emptySampleFlowRecordFilters } from '../sampleFlowRecordPageState'

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => <a href={to}>{children}</a>,
}))

describe('SampleFlowRecordsPage', () => {
  it('builds global flow record filters with pagination while removing blanks', () => {
    expect(
      buildSampleFlowRecordParams(
        {
          ...emptySampleFlowRecordFilters,
          search: 'SAMPLE-LAMP',
          action_type: 'return_room',
          action_time_from: '2026-06-01',
        },
        2,
        30,
      ),
    ).toEqual({
      search: 'SAMPLE-LAMP',
      action_type: 'return_room',
      action_time_from: '2026-06-01',
      page: 2,
      per_page: 30,
    })
  })

  it('renders the standalone sample flow records filters and ledger columns', () => {
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
      },
    })
    queryClient.setQueryData(['sample-flow-records', emptySampleFlowRecordFilters, 1, 15], {
      data: [
        {
          id: 1,
          sample_id: 10,
          action_type: 'return_room',
          action_time: '2026-06-16 09:30:00',
          holder_from: 'Alice',
          holder_to: '样品室',
          location_from: '实验区A',
          location_to: '样品室',
          action_by_name: 'Bob',
          sample: {
            id: 10,
            sample_no: 'SAMPLE-LAMP-001',
            sample_name: '路灯',
            order_no: 'FLOW',
            client_company: '中山市客户公司',
            status: 'pending',
          },
        },
      ],
      meta: { current_page: 1, per_page: 15, total: 1 },
    })

    const html = renderToStaticMarkup(
      <QueryClientProvider client={queryClient}>
        <SampleFlowRecordsPage />
      </QueryClientProvider>,
    )

    expect(html).toContain('样品流转记录')
    expect(html).toContain('样品编号/名称/委托单')
    expect(html).toContain('流转动作')
    expect(html).toContain('开始日期')
    expect(html).toContain('结束日期')
    expect(html).toMatch(
      /时间[\s\S]*委托单位[\s\S]*样品编号[\s\S]*流转类型[\s\S]*原位置[\s\S]*原持有人[\s\S]*现位置[\s\S]*现持有人[\s\S]*操作人[\s\S]*操作/,
    )
    expect(html).not.toContain('样品名称')
    expect(html).not.toContain('委托单号')
    expect(html).not.toContain('样品状态')
  })
})
