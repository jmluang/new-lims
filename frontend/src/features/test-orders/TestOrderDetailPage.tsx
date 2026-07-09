import { useQuery } from '@tanstack/react-query'
import { Link, useRouterState } from '@tanstack/react-router'
import { ArrowLeft } from 'lucide-react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { DataTable, ErrorNotice, LoadingState, PageShell, Panel, StatusBadge } from '../system/shared'
import type { ApiResource } from '../system/utils'
import { TestOrderPrintButton } from './TestOrderPrintButton'
import type { TestOrder } from './TestOrderListPage'

export function TestOrderDetailPage() {
  const pathname = useRouterState({ select: (state) => state.location.pathname })
  const testOrderId = testOrderIdFromPath(pathname)
  const orderQuery = useQuery({
    queryKey: ['test-order', testOrderId],
    queryFn: async () => {
      const response = await api.get<ApiResource<TestOrder>>(`/api/test-orders/${testOrderId}`)

      return response.data.data
    },
  })
  const order = orderQuery.data

  return (
    <PageShell
      title="Test order detail"
      description="Review commission snapshots, execution standards and expected samples."
      actions={
        <>
          <Link className="inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100" to="/test-orders">
            <ArrowLeft className="size-4" aria-hidden="true" />
            返回列表
          </Link>
          {order ? (
            <PermissionGate resource="test_orders" action="print">
              <TestOrderPrintButton order={order} />
            </PermissionGate>
          ) : null}
        </>
      }
    >
      {orderQuery.isError ? <ErrorNotice error={orderQuery.error} fallback="Unable to load test order" /> : null}
      {orderQuery.isPending ? <LoadingState label="Loading test order" /> : null}
      {order ? (
        <div className="space-y-4">
          <Panel title="Order profile">
            <div className="grid gap-3 text-sm md:grid-cols-4">
              <Detail label="Order no" value={order.order_no} />
              <Detail label="Contract no" value={order.contract_no} />
              <Detail label="Order date" value={order.order_date} />
              <Detail label="Planned end date" value={order.planned_end_date} />
              <Detail label="Client company" value={order.client_company} />
              <Detail label="Client email" value={order.client_email} />
              <Detail label="Urgency" value={zhText(order.urgency)} />
              <div>
                <div className="text-xs font-medium uppercase text-slate-500">{zhText('Sample status')}</div>
                <div className="mt-1">
                  <StatusBadge status={order.sample_status} />
                </div>
              </div>
              <Detail label="Manufacturer" value={order.manufacturer_company} />
              <Detail label="Manufacturer email" value={order.manufacturer_email} />
              <Detail label="Maker" value={order.maker_company} />
              <Detail label="Maker email" value={order.maker_email} />
              <Detail label="Delivery address" value={order.address_detail} />
            </div>
          </Panel>

          <Panel title="Report requirements">
            <div className="grid gap-3 text-sm md:grid-cols-4">
              <Detail label="Report forms" value={order.report_forms?.map((item) => zhText(item)).join('、')} />
              <Detail label="Report submission" value={zhText(order.delivery_method)} />
              <Detail label="Outsourcing option" value={zhText(order.outsourcing_option)} />
              <Detail label="Sample return" value={zhText(order.sample_return)} />
              <Detail label="Remark" value={order.remark} />
              <Detail label="Shipping notes" value={order.shipping_notes} />
            </div>
          </Panel>

          <Panel title="Execution standards">
            <DataTable>
              <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                <tr>
                  <th className="px-3 py-2 font-medium">Code</th>
                  <th className="px-3 py-2 font-medium">Name</th>
                  <th className="px-3 py-2 font-medium">Language</th>
                  <th className="px-3 py-2 font-medium">Qualifications</th>
                  <th className="px-3 py-2 font-medium">Requirement</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {order.standards.map((standard) => (
                  <tr key={standard.id}>
                    <td className="px-3 py-3 text-sm font-medium text-slate-900">{standard.standard_code}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{standard.standard_name}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{standard.report_language ?? '-'}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{standard.qualifications?.join(', ') || '-'}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{standard.requirement ?? '-'}</td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
          </Panel>

          <Panel title="Sample rows">
            <DataTable>
              <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                <tr>
                  <th className="px-3 py-2 font-medium">Sample name</th>
                  <th className="px-3 py-2 font-medium">Specification</th>
                  <th className="px-3 py-2 font-medium">Model</th>
                  <th className="px-3 py-2 font-medium">Input voltage</th>
                  <th className="px-3 py-2 font-medium">Rated current</th>
                  <th className="px-3 py-2 font-medium">Power</th>
                  <th className="px-3 py-2 font-medium">Rated frequency</th>
                  <th className="px-3 py-2 font-medium">Quantity</th>
                  <th className="px-3 py-2 font-medium">Quantity unit</th>
                  <th className="px-3 py-2 font-medium">Sample condition</th>
                  <th className="px-3 py-2 font-medium">Status</th>
                  <th className="px-3 py-2 font-medium">Detail content</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {order.samples.map((sample) => (
                  <tr key={sample.id}>
                    <td className="px-3 py-3 text-sm font-medium text-slate-900">{sample.sample_name}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{sample.specification ?? '-'}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{sample.model ?? '-'}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{sample.input_voltage ?? '-'}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{sample.rated_current ?? '-'}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{sample.power ?? '-'}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{sample.rated_frequency ?? '-'}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{sample.quantity}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{sample.quantity_unit ?? '-'}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">
                      {[zhText(sample.sample_condition), sample.sample_condition_note].filter(Boolean).join('：') || '-'}
                    </td>
                    <td className="px-3 py-3 text-sm text-slate-700">
                      <StatusBadge status={sample.status} />
                    </td>
                    <td className="px-3 py-3 text-sm text-slate-700">{sample.detail_content ?? '-'}</td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
          </Panel>
        </div>
      ) : null}
    </PageShell>
  )
}

function Detail({ label, value }: { label: string; value?: string | number | null }) {
  return (
    <div>
      <div className="text-xs font-medium uppercase text-slate-500">{zhText(label)}</div>
      <div className="mt-1 text-slate-900">{value || '-'}</div>
    </div>
  )
}

function testOrderIdFromPath(pathname: string) {
  const match = pathname.match(/^\/test-orders\/(\d+)$/)

  return match ? Number(match[1]) : null
}
