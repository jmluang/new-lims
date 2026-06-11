export const temporaryResetPassword = 'ChangeMe123!'

export function resetPasswordSuccessMessage(userName: string, temporaryPassword: string) {
  return `已重置 ${userName} 的密码，临时密码：${temporaryPassword}。用户下次登录后必须修改密码。`
}
