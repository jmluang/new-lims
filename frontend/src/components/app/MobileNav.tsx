import { Link } from '@tanstack/react-router'
import { ChevronDown, Menu, X } from 'lucide-react'
import { useState } from 'react'
import { cn } from '../../lib/utils'

const navGroups = [
  {
    label: '工作台',
    items: [{ label: '仪表盘', to: '/' }],
  },
  {
    label: '系统管理',
    items: [
      { label: '用户管理', to: '/system' },
      { label: '角色组', to: '/system/groups' },
      { label: '数据字典', to: '/system/dictionaries' },
    ],
  },
  {
    label: '业务管理',
    items: [{ label: '客户管理', to: '/customers' }],
  },
  {
    label: '设备管理',
    items: [
      { label: '设备台账', to: '/equipment' },
      { label: '设备位置', to: '/equipment/locations' },
      { label: '设备标签', to: '/equipment/labels' },
      { label: '温湿度记录', to: '/equipment/temp-humidity' },
    ],
  },
  {
    label: '运维审计',
    items: [
      { label: '审计日志', to: '/audit-logs' },
      { label: '备份管理', to: '/backups' },
    ],
  },
]

export function MobileNav() {
  const [open, setOpen] = useState(false)
  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>(() =>
    Object.fromEntries(navGroups.map((group) => [group.label, true])),
  )

  return (
    <div className="lg:hidden">
      <button
        className="inline-flex size-9 items-center justify-center rounded-md border border-slate-200 text-slate-700"
        type="button"
        onClick={() => setOpen(true)}
        aria-label="打开导航"
      >
        <Menu className="size-4" aria-hidden="true" />
      </button>

      {open ? (
        <div className="fixed inset-0 z-50 bg-slate-950/30">
          <div className="h-full w-72 max-w-[85vw] overflow-y-auto border-r border-slate-200 bg-white shadow-xl">
            <div className="flex h-16 items-center justify-between border-b border-slate-200 px-4">
              <div>
                <div className="text-sm font-semibold">New LIMS</div>
                <div className="text-xs text-slate-500">导航菜单</div>
              </div>
              <button
                className="inline-flex size-9 items-center justify-center rounded-md border border-slate-200 text-slate-700"
                type="button"
                onClick={() => setOpen(false)}
                aria-label="关闭导航"
              >
                <X className="size-4" aria-hidden="true" />
              </button>
            </div>
            <nav className="space-y-2 p-3">
              {navGroups.map((group) => {
                const expanded = openGroups[group.label] ?? true

                return (
                  <div key={group.label}>
                    <button
                      className="flex h-9 w-full items-center justify-between rounded-md px-3 text-xs font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                      type="button"
                      onClick={() =>
                        setOpenGroups((current) => ({ ...current, [group.label]: !expanded }))
                      }
                      aria-expanded={expanded}
                    >
                      <span>{group.label}</span>
                      <ChevronDown
                        className={cn('size-4 transition-transform', expanded && 'rotate-180')}
                        aria-hidden="true"
                      />
                    </button>
                    {expanded ? (
                      <div className="mt-1 space-y-1">
                        {group.items.map((item) => (
                          <Link
                            className="block rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-100"
                            to={item.to}
                            key={item.to}
                            onClick={() => setOpen(false)}
                          >
                            {item.label}
                          </Link>
                        ))}
                      </div>
                    ) : null}
                  </div>
                )
              })}
            </nav>
          </div>
        </div>
      ) : null}
    </div>
  )
}
