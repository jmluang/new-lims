import { describe, expect, it } from 'vitest'
import { loginSchema } from '../loginSchema'

describe('login schema', () => {
  it('returns Chinese email validation text', () => {
    const result = loginSchema.safeParse({
      email: 'not-an-email',
      password: 'secret',
    })

    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('请输入有效邮箱')
    }
  })
})
