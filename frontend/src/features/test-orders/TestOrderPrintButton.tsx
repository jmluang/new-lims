import { Printer } from 'lucide-react'
import { useState } from 'react'
import { cn } from '../../lib/utils'
import { Button } from '../system/shared'
import type { TestOrder } from './TestOrderListPage'
import { downloadEntrustOrderPdf } from './testOrderPrint'

export type TestOrderPrintStatus = 'idle' | 'pending' | 'success' | 'error'

export function TestOrderPrintButton({ order }: { order: Pick<TestOrder, 'id' | 'order_no'> }) {
  const [status, setStatus] = useState<TestOrderPrintStatus>('idle')

  async function handlePrint() {
    if (status === 'pending') {
      return
    }

    setStatus('pending')

    try {
      await downloadEntrustOrderPdf(order)
      setStatus('success')
    } catch {
      setStatus('error')
    }
  }

  return <TestOrderPrintButtonView order={order} status={status} onPrint={() => void handlePrint()} />
}

export function TestOrderPrintButtonView({
  order,
  status,
  onPrint,
}: {
  order: Pick<TestOrder, 'id' | 'order_no'>
  status: TestOrderPrintStatus
  onPrint: () => void
}) {
  const isPending = status === 'pending'
  const message = printMessage(status, order.order_no)

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Button variant="secondary" onClick={onPrint} disabled={isPending}>
        <Printer className="size-4" aria-hidden="true" />
        {isPending ? '生成中' : '打印委托单'}
      </Button>
      <span
        aria-live="polite"
        className={cn(
          'min-h-5 text-xs leading-5',
          status === 'pending' && 'text-amber-700',
          status === 'success' && 'text-emerald-700',
          status === 'error' && 'text-red-700',
        )}
      >
        {message}
      </span>
    </div>
  )
}

function printMessage(status: TestOrderPrintStatus, orderNo: string) {
  if (status === 'pending') {
    return `正在生成委托单 ${orderNo}`
  }

  if (status === 'success') {
    return `已下载 ${orderNo}.pdf`
  }

  if (status === 'error') {
    return '委托单生成失败，请重试'
  }

  return ''
}
