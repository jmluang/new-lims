import { describe, expect, it } from 'vitest'
import { resetPasswordSuccessMessage, temporaryResetPassword } from '../userPasswordReset'

describe('user password reset helpers', () => {
  it('shows the reset password that should be shared with the user', () => {
    expect(temporaryResetPassword).toBe('ChangeMe123!')
    expect(resetPasswordSuccessMessage('New Operator', temporaryResetPassword)).toBe(
      '已重置 New Operator 的密码，临时密码：ChangeMe123!。用户下次登录后必须修改密码。',
    )
  })
})
