import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useRouterState } from '@tanstack/react-router'
import { ArrowLeft, Pencil, X } from 'lucide-react'
import { useState } from 'react'
import { useEffectivePermissions } from '../auth/useCurrentUser'
import type { Customer } from '../customers/CustomerListPage'
import type { Standard } from '../standards/StandardListPage'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { Button, ErrorNotice, LoadingState, PageShell } from '../system/shared'
import type { ApiResource } from '../system/utils'
import { TestOrderEntrustForm } from './TestOrderEntrustForm'
import { TestOrderChangeHistory } from './TestOrderChangeHistory'
import type { TestOrder } from './TestOrderListPage'
import { TestOrderPrintButton } from './TestOrderPrintButton'
import { printEntrustOrder } from './testOrderPrint'

type TestOrderFormOptions = {
  customers: Customer[]
  standards: Standard[]
}

export function TestOrderDetailPage() {
  const queryClient = useQueryClient()
  const permissions = useEffectivePermissions()
  const pathname = useRouterState({ select: (state) => state.location.pathname })
  const testOrderId = testOrderIdFromPath(pathname)
  const canUpdate = Boolean(permissions.data?.resources.test_orders?.actions.update)
  const orderQuery = useQuery({
    queryKey: ['test-order', testOrderId],
    queryFn: async () => {
      const response = await api.get<ApiResource<TestOrder>>(`/api/test-orders/${testOrderId}`)

      return response.data.data
    },
  })
  const formOptionsQuery = useQuery({
    queryKey: ['test-order-form-options'],
    enabled: canUpdate,
    queryFn: async () => {
      const response = await api.get<{ data: TestOrderFormOptions }>('/api/test-orders/form-options', { params: { limit: 100 } })

      return response.data.data
    },
  })
  const saveOrder = useMutation({
    mutationFn: async (payload: Record<string, unknown>) => {
      const response = await api.put<ApiResource<TestOrder>>(`/api/test-orders/${testOrderId}`, payload)

      return response.data.data
    },
    onSuccess: async (saved) => {
      queryClient.setQueryData(['test-order', testOrderId], saved)
      await queryClient.invalidateQueries({ queryKey: ['test-orders'] })
      await queryClient.invalidateQueries({ queryKey: ['test-order-history', testOrderId] })
    },
  })
  const order = orderQuery.data
  // A detail page is for reading. Editing is entered deliberately, so a stray
  // click cannot alter a commission order that is already with the lab.
  const [editing, setEditing] = useState(false)

  async function save(payload: Record<string, unknown>, action: 'save' | 'print') {
    const saved = await saveOrder.mutateAsync(payload)

    if (action === 'print') {
      await printEntrustOrder(saved)
    }

    setEditing(false)
  }

  return (
    <PageShell
      title="Test order detail"
      description="按正式委托单版式展示；点击编辑后可修改并保存，随时生成最新 PDF。"
      actions={
        <>
          <Link className="inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100" to="/test-orders">
            <ArrowLeft className="size-4" aria-hidden="true" />
            返回列表
          </Link>
          {order && canUpdate ? (
            editing ? (
              <Button variant="secondary" onClick={() => setEditing(false)}>
                <X className="size-4" aria-hidden="true" />
                取消编辑
              </Button>
            ) : (
              <Button variant="secondary" disabled={formOptionsQuery.isPending || formOptionsQuery.isError} onClick={() => setEditing(true)}>
                <Pencil className="size-4" aria-hidden="true" />
                编辑
              </Button>
            )
          ) : null}
          {order ? <PermissionGate resource="test_orders" action="print"><TestOrderPrintButton order={order} /></PermissionGate> : null}
        </>
      }
    >
      {orderQuery.isError ? <ErrorNotice error={orderQuery.error} fallback="Unable to load test order" /> : null}
      {canUpdate && formOptionsQuery.isError ? <ErrorNotice error={formOptionsQuery.error} fallback="无法加载委托单位选项" /> : null}
      {orderQuery.isPending ? <LoadingState label="Loading test order" /> : null}
      {order ? (
        <>
          <TestOrderEntrustForm
            key={`${order.id}:${editing ? 'edit' : 'read'}`}
            order={order}
            customers={formOptionsQuery.data?.customers ?? []}
            standardOptions={formOptionsQuery.data?.standards ?? []}
            editable={canUpdate && editing}
            submitting={saveOrder.isPending}
            error={saveOrder.error}
            onSubmit={save}
          />
          <TestOrderChangeHistory orderId={order.id} />
        </>
      ) : null}
    </PageShell>
  )
}

function testOrderIdFromPath(pathname: string) {
  const match = pathname.match(/^\/test-orders\/(\d+)$/)

  return match ? Number(match[1]) : null
}
