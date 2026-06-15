import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import { LoginPage } from '../LoginPage'
import { registerPayload, registerSchema } from '../registerSchema'

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => <a href={to}>{children}</a>,
  useNavigate: () => vi.fn(),
}))

vi.mock('../useCurrentUser', () => ({
  useLogin: () => ({ mutateAsync: async () => undefined, isPending: false, isError: false }),
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
})
