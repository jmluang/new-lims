import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import { LoginPage } from '../LoginPage'
import { RegisterPage } from '../RegisterPage'
import { registerPayload, registerSchema } from '../registerSchema'

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => <a href={to}>{children}</a>,
  useNavigate: () => vi.fn(),
}))

vi.mock('../useCurrentUser', () => ({
  useLogin: () => ({ mutateAsync: async () => undefined, isPending: false, isError: false }),
}))

vi.mock('@tanstack/react-query', () => ({
  useMutation: () => ({
    mutateAsync: async () => undefined,
    isPending: false,
    isError: false,
    isSuccess: true,
  }),
  useQuery: () => ({
    data: { departments: [] },
    isPending: false,
  }),
}))

describe('register entry and payload', () => {
  it('shows a register entry on the login page', () => {
    const html = renderToStaticMarkup(<LoginPage />)

    expect(html).toContain('/register')
    expect(html).toContain('注册')
  })

  it('normalizes public registration values without admin-controlled permissions', () => {
    const values = registerSchema.parse({
      name: 'New Operator',
      email: 'new-operator@example.test',
      password: 'Password123!',
      phone: '',
      department_id: '',
    })

    expect(registerPayload(values)).toEqual({
      name: 'New Operator',
      email: 'new-operator@example.test',
      password: 'Password123!',
      phone: null,
      department_id: null,
    })
  })

  it('shows the pending approval state after registration succeeds', () => {
    const html = renderToStaticMarkup(<RegisterPage />)

    expect(html).toContain('注册申请已提交')
    expect(html).toContain('等待管理员审核激活')
    expect(html).toContain('返回登录')
    expect(html).not.toContain('创建账号')
  })
})
