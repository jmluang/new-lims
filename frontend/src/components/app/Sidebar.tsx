import { Link, useRouterState } from '@tanstack/react-router'
import { BookOpen, ClipboardList, DatabaseBackup, FlaskConical, LayoutDashboard, ScrollText, Settings, ShieldCheck, Users } from 'lucide-react'
import { cn } from '../../lib/utils'

const navItems = [
  { label: 'Dashboard', to: '/', icon: LayoutDashboard },
  { label: 'Users', to: '/system', icon: Settings },
  { label: 'Groups', to: '/system/groups', icon: ShieldCheck },
  { label: 'Dictionaries', to: '/system/dictionaries', icon: BookOpen },
  { label: 'Customers', to: '/customers', icon: Users },
  { label: 'Equipment', to: '/equipment', icon: ClipboardList },
  { label: 'Audit Logs', to: '/audit-logs', icon: ScrollText },
  { label: 'Backups', to: '/backups', icon: DatabaseBackup },
]

export function Sidebar() {
  const pathname = useRouterState({ select: (state) => state.location.pathname })

  return (
    <aside className="fixed inset-y-0 left-0 hidden w-64 border-r border-slate-200 bg-white lg:block">
      <div className="flex h-16 items-center gap-3 border-b border-slate-200 px-5">
        <div className="flex size-9 items-center justify-center rounded-md bg-emerald-600 text-white">
          <FlaskConical size={20} aria-hidden="true" />
        </div>
        <div>
          <div className="text-sm font-semibold">New LIMS</div>
          <div className="text-xs text-slate-500">Admin Console</div>
        </div>
      </div>
      <nav className="space-y-1 p-3">
        {navItems.map((item) => {
          const Icon = item.icon
          const active = pathname === item.to || (item.to !== '/' && pathname.startsWith(item.to))

          return (
            <Link
              className={cn(
                'flex h-10 items-center gap-3 rounded-md px-3 text-sm text-slate-700 hover:bg-slate-100',
                active && 'bg-emerald-50 font-medium text-emerald-700',
              )}
              to={item.to}
              key={item.to}
            >
              <Icon className="size-4" aria-hidden="true" />
              {item.label}
            </Link>
          )
        })}
      </nav>
    </aside>
  )
}
