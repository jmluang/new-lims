import { Link, useRouterState } from '@tanstack/react-router'
import { ChevronDown, FlaskConical } from 'lucide-react'
import { useState } from 'react'
import { useEffectivePermissions } from '../../features/auth/useCurrentUser'
import { cn } from '../../lib/utils'
import { isActivePath, navGroups, visibleNavGroups } from './navigation'

const defaultClosedGroups = Object.fromEntries(
  navGroups.filter((group) => group.label !== '工作台').map((group) => [group.label, true]),
)

export function Sidebar() {
  const pathname = useRouterState({ select: (state) => state.location.pathname })
  const permissions = useEffectivePermissions()
  const groups = visibleNavGroups(permissions.data)
  const [closedGroups, setClosedGroups] = useState<Record<string, boolean>>(() => defaultClosedGroups)

  return (
    <aside className="fixed inset-y-0 left-0 hidden h-svh w-64 flex-col border-r border-emerald-900/10 bg-white/95 lg:flex">
      <div className="flex h-16 shrink-0 items-center gap-3 border-b border-emerald-900/10 px-5">
        <div className="flex size-9 items-center justify-center rounded-md bg-emerald-700 text-white shadow-sm">
          <FlaskConical size={20} aria-hidden="true" />
        </div>
        <div>
          <div className="text-sm font-semibold text-slate-950">New LIMS</div>
          <div className="text-xs text-slate-500">管理后台</div>
        </div>
      </div>
      <nav className="flex-1 overflow-y-auto space-y-2 p-3">
        {groups.map((group) => {
          const hasActiveItem = group.items.some((item) => isActivePath(pathname, item.to))
          const open = hasActiveItem || !closedGroups[group.label]

          return (
            <div key={group.label}>
              <button
                className={cn(
                  'flex h-9 w-full items-center justify-between rounded-md px-3 text-xs font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-700',
                  hasActiveItem && 'text-emerald-800',
                )}
                type="button"
                onClick={() =>
                  setClosedGroups((current) => ({
                    ...current,
                    [group.label]: open && !hasActiveItem,
                  }))
                }
                aria-expanded={open}
              >
                <span>{group.label}</span>
                <ChevronDown
                  className={cn('size-4 transition-transform', open && 'rotate-180')}
                  aria-hidden="true"
                />
              </button>
              {open ? (
                <div className="mt-1 space-y-1">
                  {group.items.map((item) => {
                    const Icon = item.icon
                    const active = isActivePath(pathname, item.to)

                    return (
                      <Link
                        className={cn(
                          'flex h-10 items-center gap-3 rounded-md px-3 text-sm text-slate-700 hover:bg-slate-100',
                          active && 'bg-emerald-50 font-medium text-emerald-800 ring-1 ring-emerald-900/5',
                        )}
                        to={item.to}
                        key={item.to}
                      >
                        <Icon className="size-4" aria-hidden="true" />
                        {item.label}
                      </Link>
                    )
                  })}
                </div>
              ) : null}
            </div>
          )
        })}
      </nav>
    </aside>
  )
}
