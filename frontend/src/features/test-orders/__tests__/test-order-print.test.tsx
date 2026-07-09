import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { TestOrderDetailPage } from '../TestOrderDetailPage'
import { TestOrderListPage, type TestOrder } from '../TestOrderListPage'
import { TestOrderPrintButtonView } from '../TestOrderPrintButton'
import { downloadEntrustOrderPdf } from '../testOrderPrint'

type TestPermissions = {
  resources: Record<
    string,
    {
      actions: Record<string, boolean>
      fields: Record<string, Record<string, boolean>>
    }
  >
}

const permissionState = vi.hoisted((): { data?: TestPermissions } => ({}))
const navigateMock = vi.hoisted(() => vi.fn())

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => <a href={to}>{children}</a>,
  useNavigate: () => navigateMock,
  useRouterState: ({ select }: { select: (state: { location: { pathname: string } }) => string }) =>
    select({ location: { pathname: '/test-orders/7' } }),
}))

vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => permissionState,
}))

const apiGetMock = vi.hoisted(() => vi.fn())

vi.mock('../../../lib/api', () => ({
  api: {
    get: apiGetMock,
    delete: vi.fn(),
  },
}))

describe('test order entrust print', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
    apiGetMock.mockReset()
    permissionState.data = permissionsWithPrint(true)
  })

  it('downloads the entrust order PDF through the blob endpoint', async () => {
    const click = vi.fn()
    const revokeObjectURL = vi.fn()
    const createObjectURL = vi.fn(() => 'blob:test-order')
    const append = vi.fn()
    const remove = vi.fn()
    const anchor = {
      href: '',
      download: '',
      click,
    }

    vi.stubGlobal('URL', { createObjectURL, revokeObjectURL })
    vi.stubGlobal('document', {
      body: {
        appendChild: append,
        removeChild: remove,
      },
      createElement: vi.fn(() => anchor),
    })
    apiGetMock.mockResolvedValueOnce({ data: new Blob(['pdf'], { type: 'application/pdf' }) })

    await downloadEntrustOrderPdf({ id: 7, order_no: 'TO-20260628' })

    expect(apiGetMock).toHaveBeenCalledWith('/api/test-orders/7/entrust-order.pdf', { responseType: 'blob' })
    expect(createObjectURL).toHaveBeenCalled()
    expect(click).toHaveBeenCalled()
    expect(anchor.download).toBe('TO-20260628.pdf')
    expect(append).toHaveBeenCalled()
    expect(remove).toHaveBeenCalled()
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:test-order')
  })

  it('disables the print button and shows feedback while the PDF is generating', () => {
    const html = renderToStaticMarkup(<TestOrderPrintButtonView order={testOrder()} status="pending" onPrint={() => undefined} />)

    expect(html).toContain('disabled=""')
    expect(html).toContain('生成中')
    expect(html).toContain('正在生成委托单')
    expect(html).toContain('aria-live="polite"')
  })

  it('renders list and detail print actions only when test order print permission is granted', () => {
    const visibleClient = queryClientWithOrder()

    const listHtml = renderToStaticMarkup(
      <QueryClientProvider client={visibleClient}>
        <TestOrderListPage />
      </QueryClientProvider>,
    )
    const detailHtml = renderToStaticMarkup(
      <QueryClientProvider client={queryClientWithOrder()}>
        <TestOrderDetailPage />
      </QueryClientProvider>,
    )

    expect(listHtml).toContain('打印委托单')
    expect(detailHtml).toContain('打印委托单')

    permissionState.data = permissionsWithPrint(false)

    const hiddenListHtml = renderToStaticMarkup(
      <QueryClientProvider client={queryClientWithOrder()}>
        <TestOrderListPage />
      </QueryClientProvider>,
    )
    const hiddenDetailHtml = renderToStaticMarkup(
      <QueryClientProvider client={queryClientWithOrder()}>
        <TestOrderDetailPage />
      </QueryClientProvider>,
    )

    expect(hiddenListHtml).not.toContain('打印委托单')
    expect(hiddenDetailHtml).not.toContain('打印委托单')
  })
})

function permissionsWithPrint(print: boolean): TestPermissions {
  return {
    resources: {
      test_orders: {
        actions: {
          print,
          update: true,
          delete: true,
          notify: true,
          export: true,
          create: true,
        },
        fields: {},
      },
    },
  }
}

function queryClientWithOrder() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  })
  const order = testOrder()

  queryClient.setQueryData(['test-orders', { search: '', sample_status: '', client_company: '', order_date_from: '', order_date_to: '' }, 1, 15], {
    data: [order],
    meta: { current_page: 1, per_page: 15, total: 1 },
  })
  queryClient.setQueryData(['test-order', 7], order)

  return queryClient
}

function testOrder(): TestOrder {
  return {
    id: 7,
    order_no: 'TO-20260628',
    contract_no: 'C-20260628',
    order_date: '2026-06-28',
    planned_end_date: '2026-07-01',
    urgency: 'urgent',
    client_company: '中山市委托单位',
    client_email: 'client@example.test',
    manufacturer_company: '中山市制造商',
    manufacturer_email: 'manufacturer@example.test',
    maker_company: '中山市生产厂',
    maker_email: 'maker@example.test',
    report_forms: ['formal_report', 'paper_report'],
    delivery_method: 'self_pick',
    outsourcing_option: 'allowed',
    sample_return: 'return',
    sample_status: 'not_received',
    shipping_notes: 'Keep original packaging.',
    standards: [
      {
        id: 1,
        standard_code: 'GB/T 7000.1-2023',
        standard_name: '灯具 第1部分',
        sort_order: 0,
      },
    ],
    samples: [
      {
        id: 1,
        sample_name: 'LED路灯',
        model: 'MYM-300',
        input_voltage: '220V',
        rated_current: '1.3A',
        power: '300W',
        rated_frequency: '50Hz',
        quantity: 1,
        quantity_unit: '个',
        sample_condition: 'good',
        status: 'pending',
        sort_order: 0,
      },
    ],
  }
}
