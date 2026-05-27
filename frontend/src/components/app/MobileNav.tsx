import { Link } from '@tanstack/react-router'
import { Menu, X } from 'lucide-react'
import { useState } from 'react'

const navItems = [
  { label: 'Dashboard', to: '/' },
  { label: 'Users', to: '/system' },
  { label: 'Groups', to: '/system/groups' },
  { label: 'Dictionaries', to: '/system/dictionaries' },
  { label: 'Customers', to: '/customers' },
  { label: 'Equipment', to: '/equipment' },
  { label: 'Locations', to: '/equipment/locations' },
  { label: 'Labels', to: '/equipment/labels' },
  { label: 'Audit Logs', to: '/audit-logs' },
  { label: 'Backups', to: '/backups' },
]

export function MobileNav() {
  const [open, setOpen] = useState(false)

  return (
    <div className="lg:hidden">
      <button
        className="inline-flex size-9 items-center justify-center rounded-md border border-slate-200 text-slate-700"
        type="button"
        onClick={() => setOpen(true)}
        aria-label="Open navigation"
      >
        <Menu className="size-4" aria-hidden="true" />
      </button>

      {open ? (
        <div className="fixed inset-0 z-50 bg-slate-950/30">
          <div className="h-full w-72 max-w-[85vw] border-r border-slate-200 bg-white shadow-xl">
            <div className="flex h-16 items-center justify-between border-b border-slate-200 px-4">
              <div>
                <div className="text-sm font-semibold">New LIMS</div>
                <div className="text-xs text-slate-500">Navigation</div>
              </div>
              <button
                className="inline-flex size-9 items-center justify-center rounded-md border border-slate-200 text-slate-700"
                type="button"
                onClick={() => setOpen(false)}
                aria-label="Close navigation"
              >
                <X className="size-4" aria-hidden="true" />
              </button>
            </div>
            <nav className="space-y-1 p-3">
              {navItems.map((item) => (
                <Link
                  className="block rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-100"
                  to={item.to}
                  key={item.to}
                  onClick={() => setOpen(false)}
                >
                  {item.label}
                </Link>
              ))}
            </nav>
          </div>
        </div>
      ) : null}
    </div>
  )
}
