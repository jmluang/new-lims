import type { ReactNode } from 'react'
import { AlertCircle, Loader2, X } from 'lucide-react'
import { cn } from '../../lib/utils'
import { zhText } from '../../lib/zh'
import { errorMessage } from './utils'

export function PageShell({
  title,
  description,
  actions,
  children,
}: {
  title: string
  description: string
  actions?: ReactNode
  children: ReactNode
}) {
  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="text-xl font-semibold tracking-normal text-slate-950">{zhText(title)}</h1>
          <p className="mt-1 text-sm text-slate-500">{zhText(description)}</p>
        </div>
        {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
      </div>
      {children}
    </div>
  )
}

export function Panel({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
  return (
    <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
      <div className="border-b border-slate-200 px-4 py-3">
        <h2 className="text-sm font-semibold text-slate-900">{zhText(title)}</h2>
        {description ? <p className="mt-1 text-xs text-slate-500">{zhText(description)}</p> : null}
      </div>
      <div className="p-4">{children}</div>
    </section>
  )
}

export function Modal({
  title,
  description,
  open,
  onClose,
  children,
}: {
  title: string
  description?: string
  open: boolean
  onClose: () => void
  children: ReactNode
}) {
  if (!open) {
    return null
  }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/40 px-4 py-8">
      <section className="w-full max-w-2xl rounded-lg border border-slate-200 bg-white shadow-xl" role="dialog" aria-modal="true">
        <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-3">
          <div>
            <h2 className="text-sm font-semibold text-slate-900">{zhText(title)}</h2>
            {description ? <p className="mt-1 text-xs text-slate-500">{zhText(description)}</p> : null}
          </div>
          <button
            className="inline-flex size-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-700"
            type="button"
            onClick={onClose}
            aria-label="关闭"
          >
            <X className="size-4" aria-hidden="true" />
          </button>
        </div>
        <div className="p-4">{children}</div>
      </section>
    </div>
  )
}

export function Field({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
  return (
    <label className={cn('block min-w-0', className)}>
      <span className="text-xs font-medium uppercase tracking-normal text-slate-500">{zhText(label)}</span>
      <div className="mt-1">{children}</div>
    </label>
  )
}

export function Button({
  type = 'button',
  variant = 'secondary',
  className,
  children,
  ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost'
}) {
  return (
    <button
      type={type}
      className={cn(
        'inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60',
        variant === 'primary' && 'bg-emerald-600 text-white hover:bg-emerald-700',
        variant === 'secondary' && 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-100',
        variant === 'danger' && 'border border-red-200 bg-red-50 text-red-700 hover:bg-red-100',
        variant === 'ghost' && 'text-slate-600 hover:bg-slate-100',
        className,
      )}
      {...props}
    >
      {translateChildren(children)}
    </button>
  )
}

export function StatusBadge({ status }: { status?: string | null }) {
  const normalized = status ?? 'unknown'

  return (
    <span
      className={cn(
        'inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium',
        ['active', 'success', 'completed'].includes(normalized) && 'border-emerald-200 bg-emerald-50 text-emerald-700',
        ['locked', 'failed', 'error'].includes(normalized) && 'border-red-200 bg-red-50 text-red-700',
        ['disabled', 'pending', 'running'].includes(normalized) && 'border-amber-200 bg-amber-50 text-amber-700',
        !['active', 'success', 'completed', 'locked', 'failed', 'error', 'disabled', 'pending', 'running'].includes(
          normalized,
        ) && 'border-slate-200 bg-slate-50 text-slate-600',
      )}
    >
      {zhText(normalized)}
    </span>
  )
}

export function LoadingState({ label = 'Loading data' }: { label?: string }) {
  return (
    <div className="flex min-h-32 items-center justify-center gap-2 rounded-lg border border-dashed border-slate-300 bg-white text-sm text-slate-500">
      <Loader2 className="size-4 animate-spin" aria-hidden="true" />
      {zhText(label)}
    </div>
  )
}

export function EmptyState({ title, description }: { title: string; description: string }) {
  return (
    <div className="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center">
      <h2 className="text-sm font-semibold text-slate-900">{zhText(title)}</h2>
      <p className="mt-1 text-sm text-slate-500">{zhText(description)}</p>
    </div>
  )
}

function translateChildren(children: ReactNode): ReactNode {
  if (typeof children === 'string') {
    const trimmed = children.trim()
    const translated = zhText(trimmed) ?? trimmed

    return trimmed && translated !== trimmed ? children.replace(trimmed, translated) : children
  }

  if (Array.isArray(children)) {
    return children.map((child) => translateChildren(child))
  }

  return children
}

export function ErrorNotice({ error, fallback }: { error: unknown; fallback?: string }) {
  return (
    <div className="flex gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
      <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
      <span>{errorMessage(error, fallback)}</span>
    </div>
  )
}

export function DataTable({ children }: { children: ReactNode }) {
  return (
    <div className="hidden overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm md:block">
      <table className="min-w-full divide-y divide-slate-200 text-sm">{children}</table>
    </div>
  )
}
