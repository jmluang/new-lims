import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import { SampleDetailPage } from '../SampleDetailPage'

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => <a href={to}>{children}</a>,
  useRouterState: ({ select }: { select: (state: { location: { pathname: string } }) => string }) => select({ location: { pathname: '/samples/1' } }),
}))

vi.mock('../../../components/app/PermissionGate', () => ({
  PermissionGate: ({ children }: { children: React.ReactNode }) => children,
}))

vi.mock('../../auth/useCurrentUser', () => ({
  useCurrentUser: () => ({
    data: {
      name: '流转操作员',
    },
  }),
  useEffectivePermissions: () => ({
    data: {
      resources: {
        sample_flows: {
          actions: {
            return_room: true,
          },
          fields: {},
        },
      },
    },
  }),
}))

describe('SampleDetailPage flow print action', () => {
  it('renders the print flow card action in the flow records panel header', () => {
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
      },
    })

    queryClient.setQueryData(['sample', 1], {
      id: 1,
      test_order_id: 1,
      delivery_sequence: 1,
      sample_no: 'S-001',
      sample_name: '灯具',
      model: 'LD-100',
      quantity: 1,
      status: 'pending',
      sort_order: 1,
      delivery_received_count: 1,
    })
    queryClient.setQueryData(['sample-flows', 1], [])
    queryClient.setQueryData(['sample-flow-card', 1], {
      sample: { sample_no: 'S-001', sample_name: '灯具', model: 'LD-100', status: 'pending' },
      flows: [],
    })

    const html = renderToStaticMarkup(
      <QueryClientProvider client={queryClient}>
        <SampleDetailPage />
      </QueryClientProvider>,
    )

    expect(html).toMatch(/<h2[^>]*>流转记录<\/h2><\/div><div[^>]*>[\s\S]*打印流转卡/)
  })
})
