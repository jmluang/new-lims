import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useRouterState } from '@tanstack/react-router'
import { ArrowLeft } from 'lucide-react'
import { api } from '../../lib/api'
import type { Customer } from '../customers/CustomerListPage'
import type { Standard } from '../standards/StandardListPage'
import { ErrorNotice, LoadingState, PageShell } from '../system/shared'
import type { ApiCollection, ApiResource } from '../system/utils'
import { TestOrderForm } from './TestOrderForm'
import type { TestOrder } from './TestOrderListPage'

export function TestOrderFormPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const pathname = useRouterState({ select: (state) => state.location.pathname })
  const testOrderId = testOrderIdFromPath(pathname)
  const isEditing = testOrderId !== null
  const orderQuery = useQuery({
    queryKey: ['test-order', testOrderId],
    enabled: isEditing,
    queryFn: async () => {
      const response = await api.get<ApiResource<TestOrder>>(`/api/test-orders/${testOrderId}`)

      return response.data
    },
  })
  const customersQuery = useQuery({
    queryKey: ['test-order-customers'],
    queryFn: async () => {
      const response = await api.get<ApiCollection<Customer>>('/api/customers', { params: { per_page: 100, status: 'active' } })

      return response.data.data
    },
  })
  const standardsQuery = useQuery({
    queryKey: ['test-order-standards'],
    queryFn: async () => {
      const response = await api.get<ApiCollection<Standard>>('/api/standards', { params: { per_page: 100, status: 'active' } })

      return response.data.data
    },
  })
  const saveOrder = useMutation({
    mutationFn: async (payload: Record<string, unknown>) => {
      if (isEditing) {
        await api.put(`/api/test-orders/${testOrderId}`, payload)
        return
      }

      await api.post('/api/test-orders', payload)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['test-orders'] })
      await navigate({ to: '/test-orders' })
    },
  })

  return (
    <PageShell
      title={isEditing ? 'Edit test order' : 'Create test order'}
      description="Use a dedicated page for the large commission order form."
      actions={
        <Link className="inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100" to="/test-orders">
          <ArrowLeft className="size-4" aria-hidden="true" />
          返回列表
        </Link>
      }
    >
      {orderQuery.isError ? <ErrorNotice error={orderQuery.error} fallback="Unable to load test order" /> : null}
      {customersQuery.isError ? <ErrorNotice error={customersQuery.error} fallback="Unable to load customers" /> : null}
      {standardsQuery.isError ? <ErrorNotice error={standardsQuery.error} fallback="Unable to load standards" /> : null}
      {(isEditing && orderQuery.isPending) || customersQuery.isPending || standardsQuery.isPending ? <LoadingState label="Loading test order form" /> : null}
      {(!isEditing || orderQuery.data) && customersQuery.data && standardsQuery.data ? (
        <TestOrderForm
          order={orderQuery.data?.data ?? null}
          customers={customersQuery.data}
          standards={standardsQuery.data}
          submitting={saveOrder.isPending}
          error={saveOrder.error}
          onSubmit={(payload) => saveOrder.mutateAsync(payload)}
          onCancel={() => navigate({ to: '/test-orders' })}
        />
      ) : null}
    </PageShell>
  )
}

function testOrderIdFromPath(pathname: string) {
  const match = pathname.match(/^\/test-orders\/(\d+)\/edit$/)

  return match ? Number(match[1]) : null
}
