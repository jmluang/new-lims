import { api } from '../../lib/api'
import type { TestOrder } from './TestOrderListPage'

export async function downloadEntrustOrderPdf(order: Pick<TestOrder, 'id' | 'order_no'>) {
  const response = await api.get<Blob>(`/api/test-orders/${order.id}/entrust-order.pdf`, {
    responseType: 'blob',
  })
  const blob = new Blob([response.data], { type: 'application/pdf' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = url
  link.download = `entrust-order-${order.order_no}.pdf`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}
