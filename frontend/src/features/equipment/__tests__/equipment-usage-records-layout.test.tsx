import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import { EquipmentUsageRecordPage } from '../EquipmentUsageRecordPage'

vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => ({
    data: {
      resources: {
        equipment_usage_records: {
          actions: {
            create: true,
            update: true,
            delete: true,
          },
          fields: {},
        },
      },
    },
  }),
}))

describe('EquipmentUsageRecordPage layout', () => {
  it('renders the start test form with a separate remark details row', () => {
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
      },
    })

    const html = renderToStaticMarkup(
      <QueryClientProvider client={queryClient}>
        <EquipmentUsageRecordPage />
      </QueryClientProvider>,
    )

    expect(html).toContain('开始新测试')
    expect(html).toContain('md:grid-cols-3')
    expect(html).not.toContain('min-h-28')
    expect(html).not.toContain('md:col-start-3')
    expect(html).toContain('备注明细')
    expect(html).toContain('md:col-span-3')
    expect(html.match(/data-scanner-selection/g)).toHaveLength(2)
  })
})
