import type { PropsWithChildren } from 'react'
import { LogOut } from 'lucide-react'
import { MobileNav } from './MobileNav'
import { Sidebar } from './Sidebar'
import { useCurrentUser, useLogout } from '../../features/auth/useCurrentUser'

export function AppLayout({ children }: PropsWithChildren) {
  const currentUser = useCurrentUser()
  const logout = useLogout()

  return (
    <div className="min-h-svh bg-slate-50 text-slate-950">
      <Sidebar />

      <div className="lg:pl-64">
        <header className="sticky top-0 z-10 border-b border-slate-200 bg-white">
          <div className="flex h-16 items-center justify-between px-4 sm:px-6">
            <div className="flex items-center gap-3">
              <MobileNav />
              <div>
                <div className="text-base font-semibold">Dashboard</div>
                <div className="text-xs text-slate-500">React SPA + Laravel API</div>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <div className="hidden rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-600 sm:block">
                {currentUser.data?.name ?? 'Not signed in'}
              </div>
              <button
                className="inline-flex size-9 items-center justify-center rounded-md border border-slate-200 text-slate-700 hover:bg-slate-100"
                type="button"
                aria-label="Sign out"
                onClick={() => logout.mutate()}
              >
                <LogOut className="size-4" aria-hidden="true" />
              </button>
            </div>
          </div>
        </header>
        <main className="p-4 sm:p-6">{children}</main>
      </div>
    </div>
  )
}
