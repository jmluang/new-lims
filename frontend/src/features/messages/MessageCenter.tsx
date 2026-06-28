import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { Bell, Check, ExternalLink, Loader2 } from 'lucide-react'
import { useState } from 'react'
import { errorMessage } from '../system/utils'
import { fetchMessages, markMessageRead, type UserMessage } from './messages'
import { unreadBadgeLabel } from './messagePresentation'

export function MessageCenter() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const messagesQuery = useQuery({
    queryKey: ['messages'],
    queryFn: fetchMessages,
    refetchInterval: 5000,
  })
  const markRead = useMutation({
    mutationFn: markMessageRead,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['messages'] }),
  })
  const unreadCount = messagesQuery.data?.meta.unread_count ?? 0
  const badge = unreadBadgeLabel(unreadCount)

  async function openTestOrder(message: UserMessage) {
    if (!message.read) {
      await markRead.mutateAsync(message.id)
    }

    if (message.test_order) {
      await navigate({ to: '/test-orders/$testOrderId', params: { testOrderId: String(message.test_order.id) } })
      setOpen(false)
    }
  }

  return (
    <div className="relative">
      <button
        className="relative inline-flex size-9 items-center justify-center rounded-md border border-emerald-900/10 bg-white text-slate-700 transition-colors hover:bg-emerald-50 hover:text-emerald-800"
        type="button"
        aria-label="消息"
        onClick={() => setOpen((current) => !current)}
      >
        <Bell className="size-4" aria-hidden="true" />
        {badge ? (
          <span className="absolute -right-1 -top-1 min-w-5 rounded-full bg-red-600 px-1 text-center text-[11px] font-semibold leading-5 text-white">
            {badge}
          </span>
        ) : null}
      </button>
      {open ? (
        <div className="absolute right-0 z-30 mt-2 w-[min(22rem,calc(100vw-2rem))] rounded-lg border border-emerald-900/10 bg-white shadow-lg">
          <div className="flex items-center justify-between border-b border-slate-200 px-3 py-2">
            <div>
              <div className="text-sm font-semibold text-slate-900">消息</div>
              <div className="text-xs text-slate-500">未读 {unreadCount} 条</div>
            </div>
            {messagesQuery.isFetching ? <Loader2 className="size-4 animate-spin text-slate-400" aria-hidden="true" /> : null}
          </div>
          <div className="max-h-96 overflow-y-auto p-2">
            {messagesQuery.isError ? (
              <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                {errorMessage(messagesQuery.error, '无法加载消息')}
              </div>
            ) : null}
            {messagesQuery.isPending ? <div className="p-3 text-sm text-slate-500">正在加载消息</div> : null}
            {!messagesQuery.isPending && (messagesQuery.data?.data.length ?? 0) === 0 ? (
              <div className="p-3 text-sm text-slate-500">暂无消息</div>
            ) : null}
            <div className="space-y-2">
              {messagesQuery.data?.data.map((message) => (
                <article className="rounded-md border border-slate-200 p-3" key={message.id}>
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <h2 className="text-sm font-semibold text-slate-900">{message.title}</h2>
                      <p className="mt-1 text-sm leading-5 text-slate-600">{message.content}</p>
                      <div className="mt-2 text-xs text-slate-500">
                        {message.sender?.name ? `来自 ${message.sender.name}` : '系统消息'}
                      </div>
                    </div>
                    {!message.read ? <span className="mt-1 size-2 shrink-0 rounded-full bg-red-500" aria-label="未读" /> : null}
                  </div>
                  <div className="mt-3 flex flex-wrap gap-2">
                    {message.test_order ? (
                      <button
                        className="inline-flex h-8 items-center gap-1 rounded-md border border-emerald-900/15 bg-white px-2 text-xs font-medium text-slate-700 hover:bg-emerald-50 hover:text-emerald-800"
                        type="button"
                        onClick={() => void openTestOrder(message)}
                      >
                        <ExternalLink className="size-3.5" aria-hidden="true" />
                        查看委托单
                      </button>
                    ) : null}
                    {!message.read ? (
                      <button
                        className="inline-flex h-8 items-center gap-1 rounded-md border border-slate-200 bg-slate-50 px-2 text-xs font-medium text-slate-700 hover:bg-slate-100"
                        type="button"
                        disabled={markRead.isPending}
                        onClick={() => markRead.mutate(message.id)}
                      >
                        <Check className="size-3.5" aria-hidden="true" />
                        标为已读
                      </button>
                    ) : null}
                  </div>
                </article>
              ))}
            </div>
          </div>
        </div>
      ) : null}
    </div>
  )
}
