import { zodResolver } from '@hookform/resolvers/zod'
import { Link, useNavigate } from '@tanstack/react-router'
import { AlertCircle, FlaskConical, LogIn } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { useLogin } from './useCurrentUser'
import { loginSchema, type LoginForm } from './loginSchema'

export function LoginPage() {
  const navigate = useNavigate()
  const login = useLogin()
  const form = useForm<LoginForm>({
    resolver: zodResolver(loginSchema),
    defaultValues: {
      email: '',
      password: '',
    },
  })

  async function onSubmit(values: LoginForm) {
    await login.mutateAsync(values)
    await navigate({ to: '/' })
  }

  return (
    <main className="min-h-svh bg-slate-50 text-slate-950">
      <div className="mx-auto flex min-h-svh w-full max-w-6xl items-center justify-center px-4 py-8">
        <section className="grid w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm lg:grid-cols-[1fr_420px]">
          <div className="hidden border-r border-slate-200 bg-slate-950 p-8 text-white lg:block">
            <div className="flex items-center gap-3">
              <div className="flex size-10 items-center justify-center rounded-md bg-emerald-500">
                <FlaskConical size={22} aria-hidden="true" />
              </div>
              <div>
                <div className="text-base font-semibold">New LIMS</div>
                <div className="text-xs text-slate-300">实验室运营管理平台</div>
              </div>
            </div>
            <div className="mt-20 max-w-md">
              <h1 className="text-3xl font-semibold tracking-normal">LIMS 管理后台</h1>
              <p className="mt-4 text-sm leading-6 text-slate-300">
                统一管理客户、设备、权限、备份与审计记录。
              </p>
            </div>
          </div>

          <form className="p-6 sm:p-8" onSubmit={form.handleSubmit(onSubmit)}>
            <div className="lg:hidden">
              <div className="flex items-center gap-3">
                <div className="flex size-10 items-center justify-center rounded-md bg-emerald-600 text-white">
                  <FlaskConical size={22} aria-hidden="true" />
                </div>
                <div>
                  <div className="text-base font-semibold">New LIMS</div>
                  <div className="text-xs text-slate-500">管理后台</div>
                </div>
              </div>
            </div>

            <div className="mt-8 lg:mt-0">
              <h2 className="text-xl font-semibold">登录</h2>
              <p className="mt-1 text-sm text-slate-500">请使用 LIMS 操作员账号登录。</p>
            </div>

            {login.isError ? (
              <div className="mt-5 flex gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>账号或密码错误，或账号已被锁定。</span>
              </div>
            ) : null}

            <div className="mt-6 space-y-4">
              <label className="block">
                <span className="text-sm font-medium text-slate-700">邮箱</span>
                <input
                  className="mt-1 h-10 w-full rounded-md border border-slate-300 px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"
                  type="email"
                  autoComplete="email"
                  {...form.register('email')}
                />
                {form.formState.errors.email ? (
                  <span className="mt-1 block text-xs text-red-600">{form.formState.errors.email.message}</span>
                ) : null}
              </label>

              <label className="block">
                <span className="text-sm font-medium text-slate-700">密码</span>
                <input
                  className="mt-1 h-10 w-full rounded-md border border-slate-300 px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"
                  type="password"
                  autoComplete="current-password"
                  {...form.register('password')}
                />
              </label>
            </div>

            <button
              className="mt-6 inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-emerald-600 px-4 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
              type="submit"
              disabled={login.isPending}
            >
              <LogIn className="size-4" aria-hidden="true" />
              {login.isPending ? '登录中' : '登录'}
            </button>

            <div className="mt-4 text-center text-sm text-slate-500">
              没有账号？{' '}
              <Link className="font-medium text-emerald-700 hover:text-emerald-800" to="/register">
                注册
              </Link>
            </div>
          </form>
        </section>
      </div>
    </main>
  )
}
