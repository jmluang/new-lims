import { useQuery } from '@tanstack/react-query'
import { History } from 'lucide-react'
import { api } from '../../lib/api'
import { ErrorNotice, LoadingState, Panel } from '../system/shared'
import { formatDateTime } from '../system/utils'

type Change = {
  field: string
  old_value: unknown
  new_value: unknown
}

type HistoryEntry = {
  id: number
  occurred_at: string
  actor_user_id?: number | null
  actor_name: string
  changes: Change[]
}

export function TestOrderChangeHistory({ orderId }: { orderId: number }) {
  const historyQuery = useQuery({
    queryKey: ['test-order-history', orderId],
    queryFn: async () => {
      const response = await api.get<{ data: HistoryEntry[] }>(`/api/test-orders/${orderId}/history`)

      return response.data.data
    },
  })
  const entries = historyQuery.data ?? []

  return (
    <Panel title="修改记录" description="记录每次保存时修改的字段、修改前后值和操作人。">
      {historyQuery.isPending ? <LoadingState label="正在加载修改记录" /> : null}
      {historyQuery.isError ? <ErrorNotice error={historyQuery.error} fallback="无法加载修改记录" /> : null}
      {!historyQuery.isPending && !historyQuery.isError && entries.length === 0 ? <p className="text-sm text-slate-500">暂时没有修改记录。</p> : null}
      <div className="space-y-3">
        {entries.map((entry, index) => (
          <details className="rounded-md border border-slate-200 bg-slate-50" open={index === 0} key={entry.id}>
            <summary className="flex cursor-pointer list-none items-center gap-2 px-3 py-2 text-sm text-slate-700 marker:hidden">
              <History className="size-4 text-emerald-700" aria-hidden="true" />
              <span className="font-medium text-slate-900">{entry.actor_name}</span>
              <span>修改了 {entry.changes.length} 个字段</span>
              <time className="ml-auto text-xs text-slate-500">{formatDateTime(entry.occurred_at)}</time>
            </summary>
            <div className="border-t border-slate-200 bg-white">
              {entry.changes.map((change, changeIndex) => (
                <div className="grid gap-2 border-b border-slate-100 px-3 py-3 text-sm last:border-b-0 md:grid-cols-[13rem_1fr_1fr]" key={`${entry.id}-${change.field}-${changeIndex}`}>
                  <div className="font-medium text-slate-800">{change.field}</div>
                  <Value label="修改前" value={change.old_value} />
                  <Value label="修改后" value={change.new_value} highlight />
                </div>
              ))}
            </div>
          </details>
        ))}
      </div>
    </Panel>
  )
}

function Value({ label, value, highlight = false }: { label: string; value: unknown; highlight?: boolean }) {
  return (
    <div className={highlight ? 'rounded bg-emerald-50 px-2 py-1 text-emerald-950' : 'rounded bg-slate-50 px-2 py-1 text-slate-700'}>
      <span className="mr-2 text-xs text-slate-500">{label}</span>
      <span className="break-words">{displayValue(value)}</span>
    </div>
  )
}

function displayValue(value: unknown): string {
  if (value === null || value === undefined || value === '') {
    return '（空）'
  }
  if (Array.isArray(value)) {
    return value.length === 0 ? '（空）' : value.map(displayValue).join('、')
  }
  if (typeof value === 'object') {
    return JSON.stringify(value)
  }

  return String(value)
}
