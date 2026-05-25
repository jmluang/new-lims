import type { PropsWithChildren } from 'react'
import { ShieldCheck } from 'lucide-react'

const navItems = ['System', 'Customers', 'Equipment', 'Audit Logs']

export function AppLayout({ children }: PropsWithChildren) {
  return (
    <div className="min-h-svh bg-slate-50 text-slate-950">
      <aside className="fixed inset-y-0 left-0 hidden w-64 border-r border-slate-200 bg-white lg:block">
        <div className="flex h-16 items-center gap-3 border-b border-slate-200 px-5">
          <div className="flex size-9 items-center justify-center rounded-md bg-emerald-600 text-white">
            <ShieldCheck size={20} aria-hidden="true" />
          </div>
          <div>
            <div className="text-sm font-semibold">New LIMS</div>
            <div className="text-xs text-slate-500">Admin Console</div>
          </div>
        </div>
        <nav className="space-y-1 p-3">
          {navItems.map((item) => (
            <a
              className="block rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-100"
              href="/"
              key={item}
            >
              {item}
            </a>
          ))}
        </nav>
      </aside>

      <div className="lg:pl-64">
        <header className="sticky top-0 z-10 border-b border-slate-200 bg-white">
          <div className="flex h-16 items-center justify-between px-4 sm:px-6">
            <div>
              <div className="text-base font-semibold">Dashboard</div>
              <div className="text-xs text-slate-500">React SPA + Laravel API</div>
            </div>
            <div className="rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-600">
              API-first
            </div>
          </div>
        </header>
        <main className="p-4 sm:p-6">{children}</main>
      </div>
    </div>
  )
}
