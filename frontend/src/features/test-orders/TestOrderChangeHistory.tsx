import { useQuery } from '@tanstack/react-query'
import { ChevronRight, History } from 'lucide-react'
import { api } from '../../lib/api'
import { ErrorNotice, LoadingState, Panel } from '../system/shared'
import { formatDateTime } from '../system/utils'
import { zhText } from '../../lib/zh'

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
      {!historyQuery.isPending && !historyQuery.isError && entries.length === 0 ? (
        <p className="text-sm text-slate-500">暂时没有修改记录。</p>
      ) : null}

      <div className="space-y-2">
        {entries.map((entry, index) => (
          <details
            className="group overflow-hidden rounded-lg border border-emerald-900/10 bg-white"
            open={index === 0}
            key={entry.id}
          >
            {/* Wraps rather than truncates: on a phone the timestamp took
                enough of the row to cut a person's name down to four letters. */}
            <summary className="flex cursor-pointer list-none flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2.5 hover:bg-emerald-50/60 marker:hidden">
              <ChevronRight
                className="size-4 shrink-0 text-slate-400 transition-transform group-open:rotate-90"
                aria-hidden="true"
              />
              <History className="size-4 shrink-0 text-emerald-700" aria-hidden="true" />
              <span className="text-sm font-medium text-slate-900">{entry.actor_name}</span>
              <span className="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">
                {entry.changes.length} 项
              </span>
              <time className="ml-auto shrink-0 text-xs tabular-nums text-slate-500">
                {formatDateTime(entry.occurred_at)}
              </time>
            </summary>

            <dl className="divide-y divide-emerald-900/10 border-t border-emerald-900/10">
              {entry.changes.map((change, changeIndex) => (
                <div
                  className="gap-x-4 gap-y-1.5 px-3 py-2.5 md:grid md:grid-cols-[minmax(6rem,10rem)_1fr]"
                  key={`${entry.id}-${change.field}-${changeIndex}`}
                >
                  <dt className="text-xs font-medium text-slate-500 md:pt-0.5 md:text-sm md:text-slate-700">
                    {change.field}
                  </dt>
                  {/* Old beside new rather than under labels repeated on every
                      row: the arrow carries the direction, so the eye reads the
                      change itself instead of the words for it. */}
                  <dd className="mt-1 flex flex-wrap items-start gap-x-1 gap-y-1 md:mt-0">
                    <Value value={change.old_value} />
                    {/* The arrow travels with the new value. Left as a sibling
                        it stranded itself at the end of the previous line
                        whenever a long field wrapped. */}
                    <span className="flex min-w-0 items-start gap-1">
                      <ChevronRight className="mt-1 size-3.5 shrink-0 text-slate-400" aria-hidden="true" />
                      <Value value={change.new_value} highlight />
                    </span>
                  </dd>
                </div>
              ))}
            </dl>
          </details>
        ))}
      </div>
    </Panel>
  )
}

function Value({ value, highlight = false }: { value: unknown; highlight?: boolean }) {
  const text = displayValue(value)
  const empty = text === EMPTY

  return (
    <span
      className={[
        // Long fields carry newlines, so they keep them instead of collapsing
        // into one run-on line.
        'min-w-0 whitespace-pre-wrap break-words rounded px-1.5 py-0.5 text-sm',
        empty ? 'text-slate-400' : highlight ? 'bg-emerald-50 text-emerald-900' : 'bg-slate-100 text-slate-600',
      ].join(' ')}
    >
      {text}
    </span>
  )
}

const EMPTY = '（空）'

/** Enum codes as the database stores them: `urgent`, `not_allowed`, `zh`. */
const ENUM_CODE = /^[a-z][a-z0-9_]*$/

function displayValue(value: unknown): string {
  if (value === null || value === undefined || value === '') {
    return EMPTY
  }
  if (Array.isArray(value)) {
    return value.length === 0 ? EMPTY : value.map(displayValue).join('、')
  }
  if (typeof value === 'object') {
    return JSON.stringify(value)
  }

  const text = String(value)

  // Only code-shaped values are translated. Free text is left alone, so a
  // remark is never rewritten because it happens to match a dictionary key.
  return ENUM_CODE.test(text) ? zhText(text) ?? text : text
}
