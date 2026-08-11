import { useSyncExternalStore } from 'react'
import { api } from '../../lib/api'
import { showToast, updateToast } from '../../lib/toast'
import type { TestOrder } from './TestOrderListPage'

export type PrintableTestOrder = Pick<TestOrder, 'id' | 'order_no'>

/** Kept outside React so the run survives the button unmounting when the user switches pages. */
const pendingOrderIds = new Set<number>()
const pendingListeners = new Set<() => void>()

export async function printEntrustOrder(order: PrintableTestOrder) {
  if (pendingOrderIds.has(order.id)) {
    return
  }

  setPending(order.id, true)

  const toastId = showToast({ variant: 'loading', title: '正在生成委托单', description: order.order_no })

  try {
    await downloadEntrustOrderPdf(order)
    updateToast(toastId, { variant: 'success', title: '委托单已下载', description: `${order.order_no}.pdf` })
  } catch {
    updateToast(toastId, { variant: 'error', title: '委托单生成失败，请重试', description: order.order_no })
  } finally {
    setPending(order.id, false)
  }
}

export function useEntrustPrintPending(orderId: number) {
  return useSyncExternalStore(
    subscribePending,
    () => pendingOrderIds.has(orderId),
    () => false,
  )
}

export async function downloadEntrustOrderPdf(order: PrintableTestOrder) {
  const response = await api.get<Blob>(`/api/test-orders/${order.id}/entrust-order.pdf`, {
    responseType: 'blob',
  })
  const blob = new Blob([response.data], { type: 'application/pdf' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = url
  link.download = `${order.order_no}.pdf`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

function subscribePending(listener: () => void) {
  pendingListeners.add(listener)

  return () => {
    pendingListeners.delete(listener)
  }
}

function setPending(orderId: number, pending: boolean) {
  if (pending) {
    pendingOrderIds.add(orderId)
  } else {
    pendingOrderIds.delete(orderId)
  }

  pendingListeners.forEach((listener) => listener())
}
