import { useNavigate } from '@tanstack/react-router'
import { useState, type PropsWithChildren } from 'react'
import { LogOut } from 'lucide-react'
import { MobileNav } from './MobileNav'
import { PageHeaderContext, type PageHeaderContent } from './PageHeaderContext'
import { Sidebar } from './Sidebar'
import { ToastViewport } from './ToastViewport'
import { useCurrentUser, useLogout } from '../../features/auth/useCurrentUser'
import { MessageCenter } from '../../features/messages/MessageCenter'
import { zhText } from '../../lib/zh'

export function AppLayout({ children }: PropsWithChildren) {
  const navigate = useNavigate()
  const currentUser = useCurrentUser()
  const logout = useLogout()
  const [pageHeader, setPageHeader] = useState<PageHeaderContent | null>(null)

  async function handleLogout() {
    try {
      await logout.mutateAsync()
    } catch {
      // The local session is cleared in useLogout.onSettled; keep logout navigation deterministic.
    }

    await navigate({ to: '/login', replace: true })
  }

  return (
    <div className="min-h-svh text-slate-950">
      <ToastViewport />
      <Sidebar />

      <div className="lg:pl-64">
        <header className="sticky top-0 z-10 border-b border-emerald-900/10 bg-white/95 backdrop-blur">
          <div className="flex min-h-16 items-center justify-between gap-3 px-4 py-2.5 sm:min-h-20 sm:px-6">
            <div className="flex min-w-0 items-center gap-3">
              <MobileNav />
              <div className="min-w-0">
                <h1 className="truncate text-base font-semibold tracking-normal text-slate-950 sm:text-lg">
                  {zhText(pageHeader?.title ?? 'New LIMS 管理后台')}
                </h1>
                <p className="mt-0.5 line-clamp-2 text-xs leading-4 text-slate-500 sm:text-sm sm:leading-5">
                  {zhText(pageHeader?.description ?? '实验室信息管理系统')}
                </p>
              </div>
            </div>
            <div className="flex shrink-0 items-center gap-2">
              <MessageCenter />
              <div className="hidden rounded-md border border-emerald-900/10 bg-emerald-50/60 px-3 py-1.5 text-sm text-emerald-900 sm:block">
                {currentUser.data?.name ?? '未登录'}
              </div>
              <button
                className="inline-flex size-9 items-center justify-center rounded-md border border-emerald-900/10 bg-white text-slate-700 transition-colors hover:bg-emerald-50 hover:text-emerald-800"
                type="button"
                aria-label="退出登录"
                disabled={logout.isPending}
                onClick={() => void handleLogout()}
              >
                <LogOut className="size-4" aria-hidden="true" />
              </button>
            </div>
          </div>
        </header>
        <PageHeaderContext.Provider value={setPageHeader}>
          <main className="p-4 sm:p-6">{children}</main>
        </PageHeaderContext.Provider>
      </div>
    </div>
  )
}
