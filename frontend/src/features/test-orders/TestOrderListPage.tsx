import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { Download, Edit3, Eye, Plus, Search, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, PageShell, PaginationControls, Panel, StatusBadge } from '../system/shared'
import { type ApiCollection, inputClass, paginationParams } from '../system/utils'

export type TestOrderStandard = {
  id: number
  standard_id?: number | null
  standard_code: string
  standard_name: string
  report_language?: string | null
  qualifications?: string[]
  requirement?: string | null
  sort_order: number
}

export type TestOrderSample = {
  id: number
  sample_name: string
  specification?: string | null
  model?: string | null
  status: 'pending' | 'partially_received' | 'received' | 'rejected' | 'cancelled'
  quantity: number
  detail_content?: string | null
  remark?: string | null
  sort_order: number
}

export type TestOrder = {
  id: number
  order_no: string
  contract_no?: string | null
  order_date: string
  planned_end_date?: string | null
  urgency?: 'normal' | 'urgent' | 'critical' | null
  client_customer_id?: number | null
  client_company: string
  client_address?: string | null
  client_contact?: string | null
  client_phone?: string | null
  manufacturer_customer_id?: number | null
  manufacturer_company?: string | null
  manufacturer_address?: string | null
  manufacturer_contact?: string | null
  manufacturer_phone?: string | null
  maker_customer_id?: number | null
  maker_company?: string | null
  maker_address?: string | null
  maker_contact?: string | null
  maker_phone?: string | null
  report_forms?: string[]
  delivery_method?: string | null
  outsourcing_option?: string | null
  remark?: string | null
  sample_status: 'not_received' | 'partially_received' | 'received' | 'testing' | 'completed'
  address_lab_name?: string | null
  address_contact?: string | null
  address_detail?: string | null
  address_phone?: string | null
  client_signature?: string | null
  client_sign_date?: string | null
  dept_confirm?: string | null
  dept_confirm_date?: string | null
  lab_confirm?: string | null
  lab_confirm_date?: string | null
  standards: TestOrderStandard[]
  samples: TestOrderSample[]
}

type TestOrderFilters = {
  search: string
  sample_status: string
  client_company: string
  order_date_from: string
  order_date_to: string
}

const emptyFilters: TestOrderFilters = {
  search: '',
  sample_status: '',
  client_company: '',
  order_date_from: '',
  order_date_to: '',
}

export function TestOrderListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [filters, setFilters] = useState<TestOrderFilters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const ordersQuery = useQuery({
    queryKey: ['test-orders', filters, page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<TestOrder>>('/api/test-orders', { params: cleanParams({ ...filters, ...paginationParams(page, perPage) }) })

      return response.data
    },
  })
  const deleteOrder = useMutation({
    mutationFn: async (order: TestOrder) => {
      await api.delete(`/api/test-orders/${order.id}`)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['test-orders'] }),
  })
  const orders = ordersQuery.data?.data ?? []

  async function exportOrders() {
    const response = await api.get<{ headers: string[]; data: Record<string, unknown>[] }>('/api/test-orders/export', {
      params: cleanParams(filters),
    })
    const blob = new Blob([JSON.stringify(response.data, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = `test-orders-${new Date().toISOString().slice(0, 10)}.json`
    link.click()
    URL.revokeObjectURL(url)
  }

  return (
    <PageShell
      title="Test Orders"
      description="Manage commission test orders, execution standards and expected sample rows."
      actions={
        <>
          <PermissionGate resource="test_orders" action="export">
            <Button variant="secondary" onClick={() => void exportOrders()}>
              <Download className="size-4" aria-hidden="true" />
              Export
            </Button>
          </PermissionGate>
          <PermissionGate resource="test_orders" action="create">
            <Button variant="primary" onClick={() => void navigate({ to: '/test-orders/new' })}>
              <Plus className="size-4" aria-hidden="true" />
              New test order
            </Button>
          </PermissionGate>
        </>
      }
    >
      <Panel title="Filters">
        <div className="grid gap-3 md:grid-cols-5">
          <Field label="Search">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input
                className={`${inputClass} pl-9`}
                value={filters.search}
                onChange={(event) => setFilters({ ...filters, search: event.target.value })}
                placeholder={zhText('order, contract, client') ?? undefined}
              />
            </div>
          </Field>
          <Field label="Sample status">
            <select className={inputClass} value={filters.sample_status} onChange={(event) => setFilters({ ...filters, sample_status: event.target.value })}>
              <option value="">{zhText('All')}</option>
              {['not_received', 'partially_received', 'received', 'testing', 'completed'].map((status) => (
                <option value={status} key={status}>
                  {zhText(status)}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Client company">
            <input className={inputClass} value={filters.client_company} onChange={(event) => setFilters({ ...filters, client_company: event.target.value })} />
          </Field>
          <Field label="Order from">
            <input className={inputClass} type="date" value={filters.order_date_from} onChange={(event) => setFilters({ ...filters, order_date_from: event.target.value })} />
          </Field>
          <Field label="Order to">
            <input className={inputClass} type="date" value={filters.order_date_to} onChange={(event) => setFilters({ ...filters, order_date_to: event.target.value })} />
          </Field>
        </div>
      </Panel>

      {ordersQuery.isError ? <ErrorNotice error={ordersQuery.error} fallback="Unable to load test orders" /> : null}
      {deleteOrder.error ? <ErrorNotice error={deleteOrder.error} fallback="Unable to delete test order" /> : null}
      {ordersQuery.isPending ? <LoadingState label="Loading test orders" /> : null}
      {!ordersQuery.isPending && orders.length === 0 ? <EmptyState title="No test orders found" description="Adjust filters or create a new commission order." /> : null}
      {orders.length > 0 ? (
        <>
          <DataTable>
            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">Order no</th>
                <th className="px-3 py-2 font-medium">Contract no</th>
                <th className="px-3 py-2 font-medium">Client company</th>
                <th className="px-3 py-2 font-medium">Order date</th>
                <th className="px-3 py-2 font-medium">Sample status</th>
                <th className="px-3 py-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {orders.map((order) => (
                <tr key={order.id}>
                  <td className="px-3 py-3 text-sm font-medium text-slate-900">{order.order_no}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{order.contract_no ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{order.client_company}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{order.order_date}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">
                    <StatusBadge status={order.sample_status} />
                  </td>
                  <td className="px-3 py-3">
                    <TestOrderActions order={order} onDelete={(target) => deleteOrder.mutate(target)} />
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          <div className="space-y-3 md:hidden">
            {orders.map((order) => (
              <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" key={order.id}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h2 className="truncate text-sm font-semibold text-slate-950">{order.order_no}</h2>
                    <p className="truncate text-xs text-slate-500">{order.client_company}</p>
                  </div>
                  <StatusBadge status={order.sample_status} />
                </div>
                <div className="mt-3">
                  <TestOrderActions order={order} onDelete={(target) => deleteOrder.mutate(target)} />
                </div>
              </article>
            ))}
          </div>
        </>
      ) : null}
      <PaginationControls
        meta={ordersQuery.data?.meta}
        page={page}
        perPage={perPage}
        onPageChange={setPage}
        onPerPageChange={(nextPerPage) => {
          setPerPage(nextPerPage)
          setPage(1)
        }}
      />
    </PageShell>
  )
}

function TestOrderActions({ order, onDelete }: { order: TestOrder; onDelete: (order: TestOrder) => void }) {
  const navigate = useNavigate()

  return (
    <div className="flex flex-wrap gap-2">
      <Button variant="secondary" onClick={() => void navigate({ to: '/test-orders/$testOrderId', params: { testOrderId: String(order.id) } })}>
        <Eye className="size-4" aria-hidden="true" />
        View
      </Button>
      <PermissionGate resource="test_orders" action="update">
        <Button variant="secondary" onClick={() => void navigate({ to: '/test-orders/$testOrderId/edit', params: { testOrderId: String(order.id) } })}>
          <Edit3 className="size-4" aria-hidden="true" />
          Edit
        </Button>
      </PermissionGate>
      <PermissionGate resource="test_orders" action="delete">
        <Button variant="danger" onClick={() => onDelete(order)}>
          <Trash2 className="size-4" aria-hidden="true" />
          Delete
        </Button>
      </PermissionGate>
    </div>
  )
}

function cleanParams(filters: Record<string, string | number>) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
}
