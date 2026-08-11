import { LoaderCircle, Printer } from 'lucide-react'
import { Button } from '../system/shared'
import { printEntrustOrder, useEntrustPrintPending, type PrintableTestOrder } from './testOrderPrint'

export function TestOrderPrintButton({ order }: { order: PrintableTestOrder }) {
  const isPending = useEntrustPrintPending(order.id)

  return <TestOrderPrintButtonView isPending={isPending} onPrint={() => void printEntrustOrder(order)} />
}

export function TestOrderPrintButtonView({ isPending, onPrint }: { isPending: boolean; onPrint: () => void }) {
  return (
    <Button variant="secondary" onClick={onPrint} disabled={isPending}>
      {isPending ? (
        <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />
      ) : (
        <Printer className="size-4" aria-hidden="true" />
      )}
      {isPending ? '生成中' : '打印委托单'}
    </Button>
  )
}
