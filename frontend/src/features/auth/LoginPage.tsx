import { zodResolver } from '@hookform/resolvers/zod'
import { useNavigate } from '@tanstack/react-router'
import { AlertCircle, FlaskConical, LogIn } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { useLogin } from './useCurrentUser'

const loginSchema = z.object({
  email: z.email(),
  password: z.string().min(1),
})

type LoginForm = z.infer<typeof loginSchema>

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
                <div className="text-xs text-slate-300">Laboratory operations console</div>
              </div>
            </div>
            <div className="mt-20 max-w-md">
              <h1 className="text-3xl font-semibold tracking-normal">LIMS Admin Console</h1>
              <p className="mt-4 text-sm leading-6 text-slate-300">
                Manage customers, equipment, permissions, backups, and audit records from one API-first workspace.
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
                  <div className="text-xs text-slate-500">Admin Console</div>
                </div>
              </div>
            </div>

            <div className="mt-8 lg:mt-0">
              <h2 className="text-xl font-semibold">Sign in</h2>
              <p className="mt-1 text-sm text-slate-500">Use your LIMS operator account.</p>
            </div>

            {login.isError ? (
              <div className="mt-5 flex gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>Invalid credentials or the account is locked.</span>
              </div>
            ) : null}

            <div className="mt-6 space-y-4">
              <label className="block">
                <span className="text-sm font-medium text-slate-700">Email</span>
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
                <span className="text-sm font-medium text-slate-700">Password</span>
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
              {login.isPending ? 'Signing in' : 'Sign in'}
            </button>
          </form>
        </section>
      </div>
    </main>
  )
}
