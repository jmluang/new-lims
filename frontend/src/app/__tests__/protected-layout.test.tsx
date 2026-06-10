import { renderToStaticMarkup } from 'react-dom/server'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ProtectedLayout } from '../ProtectedLayout'

type QueryState<T> = {
  isPending: boolean
  isError: boolean
  data?: T
}

const authState = vi.hoisted(
  (): {
    currentUser: QueryState<{ id: number; name: string; email: string }>
    permissions: QueryState<{ resources: Record<string, unknown> }>
  } => ({
    currentUser: { isPending: false, isError: false, data: { id: 1, name: 'Admin', email: 'admin@example.com' } },
    permissions: { isPending: false, isError: false, data: { resources: {} } },
  }),
)

vi.mock('@tanstack/react-router', () => ({
  Outlet: () => <div>Protected child</div>,
  useNavigate: () => vi.fn(),
}))

vi.mock('../../components/app/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <section>{children}</section>,
}))

vi.mock('../../features/auth/useCurrentUser', () => ({
  useCurrentUser: () => authState.currentUser,
  useEffectivePermissions: () => authState.permissions,
}))

describe('ProtectedLayout', () => {
  beforeEach(() => {
    authState.currentUser = { isPending: false, isError: false, data: { id: 1, name: 'Admin', email: 'admin@example.com' } }
    authState.permissions = { isPending: false, isError: false, data: { resources: {} } }
  })

  it('does not mount protected child routes until the current user is loaded', () => {
    authState.currentUser = { isPending: true, isError: false, data: undefined }

    const html = renderToStaticMarkup(<ProtectedLayout />)

    expect(html).toContain('正在验证登录状态')
    expect(html).not.toContain('Protected child')
  })

  it('does not mount protected child routes until permissions are loaded', () => {
    authState.permissions = { isPending: true, isError: false, data: undefined }

    const html = renderToStaticMarkup(<ProtectedLayout />)

    expect(html).toContain('正在加载权限')
    expect(html).not.toContain('Protected child')
  })
})
