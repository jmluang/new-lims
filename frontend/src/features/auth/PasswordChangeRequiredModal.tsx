import { AlertCircle, Save } from 'lucide-react'
import type { FormEvent } from 'react'
import { useState } from 'react'
import type { CurrentUser } from './useCurrentUser'
import { useChangePassword } from './useCurrentUser'
import { Button, ErrorNotice, Field } from '../system/shared'
import { inputClass } from '../system/utils'

export function PasswordChangeRequiredModal({ user }: { user: CurrentUser }) {
  const changePassword = useChangePassword()
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [validationError, setValidationError] = useState('')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    if (password.length < 8) {
      setValidationError('新密码至少需要 8 位')
      return
    }

    if (password !== passwordConfirmation) {
      setValidationError('两次输入的新密码不一致')
      return
    }

    setValidationError('')
    await changePassword.mutateAsync({
      current_password: currentPassword,
      password,
      password_confirmation: passwordConfirmation,
    })
  }

  return (
    <section className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 px-4 py-8">
      <form
        className="w-full max-w-lg rounded-lg border border-slate-200 bg-white p-5 shadow-xl"
        role="dialog"
        aria-modal="true"
        onSubmit={(event) => void submit(event)}
      >
        <div className="flex gap-3">
          <div className="flex size-10 shrink-0 items-center justify-center rounded-md bg-amber-50 text-amber-700">
            <AlertCircle className="size-5" aria-hidden="true" />
          </div>
          <div>
            <h1 className="text-base font-semibold text-slate-950">首次登录需修改密码</h1>
            <p className="mt-1 text-sm leading-6 text-slate-600">
              {user.email} 当前使用临时密码。请先设置新密码，再进入系统。
            </p>
          </div>
        </div>

        <div className="mt-5 space-y-3">
          <Field label="当前密码">
            <input
              className={inputClass}
              type="password"
              autoComplete="current-password"
              value={currentPassword}
              onChange={(event) => setCurrentPassword(event.target.value)}
            />
          </Field>
          <Field label="新密码">
            <input
              className={inputClass}
              type="password"
              autoComplete="new-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
          </Field>
          <Field label="确认新密码">
            <input
              className={inputClass}
              type="password"
              autoComplete="new-password"
              value={passwordConfirmation}
              onChange={(event) => setPasswordConfirmation(event.target.value)}
            />
          </Field>
        </div>

        {validationError ? <div className="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">{validationError}</div> : null}
        {changePassword.error ? <div className="mt-4"><ErrorNotice error={changePassword.error} fallback="修改密码失败" /></div> : null}

        <div className="mt-5 flex justify-end">
          <Button variant="primary" type="submit" disabled={changePassword.isPending || currentPassword === '' || password === '' || passwordConfirmation === ''}>
            <Save className="size-4" aria-hidden="true" />
            修改密码
          </Button>
        </div>
      </form>
    </section>
  )
}
