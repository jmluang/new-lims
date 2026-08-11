import { CircleAlert, CircleCheck, LoaderCircle, X } from 'lucide-react'
import { cn } from '../../lib/utils'
import { dismissToast, useToasts, type Toast } from '../../lib/toast'

/** Corner notifications: they live outside the page content so route changes never interrupt them. */
export function ToastViewport() {
  const toasts = useToasts()

  if (toasts.length === 0) {
    return null
  }

  return (
    <div className="pointer-events-none fixed right-4 top-20 z-[60] flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-2">
      {toasts.map((toast) => (
        <ToastCard key={toast.id} toast={toast} />
      ))}
    </div>
  )
}

function ToastCard({ toast }: { toast: Toast }) {
  const isError = toast.variant === 'error'

  return (
    <div
      className={cn(
        'toast-enter pointer-events-auto flex items-start gap-3 rounded-xl border bg-white/95 p-3 shadow-lg shadow-emerald-900/10 backdrop-blur',
        toast.variant === 'loading' && 'border-emerald-900/10',
        toast.variant === 'success' && 'border-emerald-200',
        isError && 'border-red-200',
      )}
      role={isError ? 'alert' : 'status'}
      aria-live={isError ? 'assertive' : 'polite'}
    >
      <ToastIcon variant={toast.variant} />
      <div className="min-w-0 flex-1">
        <div className="text-sm font-medium text-slate-900">{toast.title}</div>
        {toast.description ? <div className="mt-0.5 truncate text-xs text-slate-500">{toast.description}</div> : null}
      </div>
      <button
        type="button"
        aria-label="关闭提示"
        className="-m-1 inline-flex size-6 shrink-0 items-center justify-center rounded-md text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
        onClick={() => dismissToast(toast.id)}
      >
        <X className="size-4" aria-hidden="true" />
      </button>
    </div>
  )
}

function ToastIcon({ variant }: { variant: Toast['variant'] }) {
  if (variant === 'success') {
    return <CircleCheck className="mt-0.5 size-4 shrink-0 text-emerald-600" aria-hidden="true" />
  }

  if (variant === 'error') {
    return <CircleAlert className="mt-0.5 size-4 shrink-0 text-red-600" aria-hidden="true" />
  }

  return <LoaderCircle className="mt-0.5 size-4 shrink-0 animate-spin text-emerald-700" aria-hidden="true" />
}
