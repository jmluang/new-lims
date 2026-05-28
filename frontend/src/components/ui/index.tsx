import type { ComponentProps, PropsWithChildren } from 'react'
import { cn } from '../../lib/utils'

export function Button({ className, ...props }: ComponentProps<'button'>) {
  return (
    <button
      className={cn(
        'inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-900 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60',
        className,
      )}
      {...props}
    />
  )
}

export function Input({ className, ...props }: ComponentProps<'input'>) {
  return (
    <input
      className={cn('h-10 min-w-0 w-full rounded-md border border-slate-300 px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100', className)}
      {...props}
    />
  )
}

export function Label({ className, ...props }: ComponentProps<'label'>) {
  return <label className={cn('text-sm font-medium text-slate-700', className)} {...props} />
}

export function Select({ className, ...props }: ComponentProps<'select'>) {
  return (
    <select
      className={cn('h-10 min-w-0 w-full rounded-md border border-slate-300 bg-white px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100', className)}
      {...props}
    />
  )
}

export function Checkbox({ className, ...props }: ComponentProps<'input'>) {
  return <input className={cn('size-4 rounded border-slate-300 text-emerald-600', className)} type="checkbox" {...props} />
}

export function Textarea({ className, ...props }: ComponentProps<'textarea'>) {
  return (
    <textarea
      className={cn('min-h-24 min-w-0 w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100', className)}
      {...props}
    />
  )
}

export function Badge({ className, ...props }: ComponentProps<'span'>) {
  return <span className={cn('inline-flex items-center rounded-md border border-slate-200 px-2 py-0.5 text-xs font-medium text-slate-700', className)} {...props} />
}

export function Table({ className, ...props }: ComponentProps<'table'>) {
  return <table className={cn('w-full border-collapse text-sm', className)} {...props} />
}

export function Dialog({ open, children }: PropsWithChildren<{ open?: boolean }>) {
  return open ? <div role="dialog">{children}</div> : null
}

export function Sheet({ open, children }: PropsWithChildren<{ open?: boolean }>) {
  return open ? <aside>{children}</aside> : null
}

export function DropdownMenu({ children }: PropsWithChildren) {
  return <div>{children}</div>
}

export function Form({ className, ...props }: ComponentProps<'form'>) {
  return <form className={cn('space-y-4', className)} {...props} />
}

export function Toast({ className, ...props }: ComponentProps<'div'>) {
  return <div className={cn('rounded-md border border-slate-200 bg-white p-3 text-sm shadow-sm', className)} {...props} />
}
