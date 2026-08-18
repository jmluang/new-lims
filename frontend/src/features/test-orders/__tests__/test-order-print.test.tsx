import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastViewport } from '../../../components/app/ToastViewport'
import { clearToasts } from '../../../lib/toast'
import { TestOrderDetailPage } from '../TestOrderDetailPage'
import { TestOrderListPage, type TestOrder } from '../TestOrderListPage'
import { TestOrderPrintButtonView } from '../TestOrderPrintButton'
import { downloadEntrustOrderPdf, printEntrustOrder } from '../testOrderPrint'

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
    clearToasts()
    permissionState.data = permissionsWithPrint(true)
  })

  afterEach(() => {
    clearToasts()
  })

  it('downloads the entrust order PDF through the blob endpoint', async () => {
    const { anchor, append, click, createObjectURL, remove, revokeObjectURL } = stubDownloadEnvironment()

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

  it('disables the print button while the PDF is generating and keeps the row free of status text', () => {
    const html = renderToStaticMarkup(<TestOrderPrintButtonView isPending onPrint={() => undefined} />)

    expect(html).toContain('disabled=""')
    expect(html).toContain('生成中')
    expect(html).not.toContain('正在生成委托单')
  })

  it('reports print progress through the corner toast so page navigation is not blocked', async () => {
    stubDownloadEnvironment()
    apiGetMock.mockResolvedValueOnce({ data: new Blob(['pdf'], { type: 'application/pdf' }) })

    const printing = printEntrustOrder({ id: 7, order_no: 'TO-20260628' })
    const pendingHtml = renderToStaticMarkup(<ToastViewport />)

    expect(pendingHtml).toContain('正在生成委托单')
    expect(pendingHtml).toContain('TO-20260628')
    expect(pendingHtml).toContain('aria-live="polite"')

    await printing

    const successHtml = renderToStaticMarkup(<ToastViewport />)

    expect(successHtml).toContain('委托单已下载')
    expect(successHtml).toContain('TO-20260628.pdf')
  })

  it('shows a dismissible error toast when the PDF request fails', async () => {
    stubDownloadEnvironment()
    apiGetMock.mockRejectedValueOnce(new Error('boom'))

    await printEntrustOrder({ id: 7, order_no: 'TO-20260628' })

    const errorHtml = renderToStaticMarkup(<ToastViewport />)

    expect(errorHtml).toContain('委托单生成失败，请重试')
    expect(errorHtml).toContain('role="alert"')
    expect(errorHtml).toContain('关闭提示')
  })

  it('ignores a second print request while the same order is still generating', async () => {
    stubDownloadEnvironment()
    apiGetMock.mockResolvedValue({ data: new Blob(['pdf'], { type: 'application/pdf' }) })

    const printing = printEntrustOrder({ id: 7, order_no: 'TO-20260628' })

    await printEntrustOrder({ id: 7, order_no: 'TO-20260628' })
    await printing

    expect(apiGetMock).toHaveBeenCalledTimes(1)
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
    expect(detailHtml).toContain('修改记录')

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

function stubDownloadEnvironment() {
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

  return { anchor, append, click, createObjectURL, remove, revokeObjectURL }
}

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
