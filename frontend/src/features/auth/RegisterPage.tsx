import { zodResolver } from '@hookform/resolvers/zod'
import { Link, useNavigate } from '@tanstack/react-router'
import { useMutation, useQuery } from '@tanstack/react-query'
import { AlertCircle, ArrowLeft, FlaskConical, UserPlus } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { api } from '../../lib/api'
import { Field } from '../system/shared'
import { inputClass } from '../system/utils'
import type { ApiResource } from '../system/utils'
import type { DepartmentOption } from '../system/users/UserForm'
import { registerPayload, registerSchema, type RegisterForm } from './registerSchema'

type RegisterOptions = {
  departments: DepartmentOption[]
}

export function RegisterPage() {
  const navigate = useNavigate()
  const form = useForm<RegisterForm>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      name: '',
      email: '',
      password: '',
      phone: '',
      department_id: '',
    },
  })
  const optionsQuery = useQuery({
    queryKey: ['register-options'],
    queryFn: async () => {
      const response = await api.get<ApiResource<RegisterOptions>>('/api/register/options')

      return response.data.data
    },
  })
  const register = useMutation({
    mutationFn: async (values: RegisterForm) => {
      await api.post('/api/register', registerPayload(values))
    },
    onSuccess: async () => {
      await navigate({ to: '/login' })
    },
  })
  const departments = flattenDepartmentOptions(optionsQuery.data?.departments ?? [])

  async function onSubmit(values: RegisterForm) {
    await register.mutateAsync(values)
  }

  return (
    <main className="min-h-svh bg-slate-50 text-slate-950">
      <div className="mx-auto flex min-h-svh w-full max-w-6xl items-center justify-center px-4 py-8">
        <section className="grid w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm lg:grid-cols-[1fr_460px]">
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
              <h1 className="text-3xl font-semibold tracking-normal">注册操作员账号</h1>
              <p className="mt-4 text-sm leading-6 text-slate-300">
                注册后账号需管理员审核激活后方可登录；账号默认不属于任何角色组，需要管理员分配权限后才能访问业务模块。
              </p>
            </div>
          </div>

          <form className="p-6 sm:p-8" onSubmit={form.handleSubmit(onSubmit)}>
            <Link
              className="inline-flex h-9 items-center gap-2 rounded-md text-sm font-medium text-slate-600 hover:text-emerald-800"
              to="/login"
            >
              <ArrowLeft className="size-4" aria-hidden="true" />
              返回登录
            </Link>

            <div className="mt-6">
              <h2 className="text-xl font-semibold">注册</h2>
              <p className="mt-1 text-sm text-slate-500">填写基础账号信息。</p>
            </div>

            {register.isError ? (
              <div className="mt-5 flex gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>注册失败，请检查邮箱是否已存在。</span>
              </div>
            ) : null}

            <div className="mt-6 grid gap-4 sm:grid-cols-2">
              <Field label="Name">
                <input className={inputClass} autoComplete="name" {...form.register('name')} />
                {form.formState.errors.name ? (
                  <span className="mt-1 block text-xs text-red-600">{form.formState.errors.name.message}</span>
                ) : null}
              </Field>

              <Field label="Email">
                <input className={inputClass} type="email" autoComplete="email" {...form.register('email')} />
                {form.formState.errors.email ? (
                  <span className="mt-1 block text-xs text-red-600">{form.formState.errors.email.message}</span>
                ) : null}
              </Field>

              <Field label="Phone">
                <input className={inputClass} autoComplete="tel" {...form.register('phone')} />
              </Field>

              <Field label="Department">
                <select className={inputClass} disabled={optionsQuery.isPending} {...form.register('department_id')}>
                  <option value="">无部门</option>
                  {departments.map((department) => (
                    <option value={department.id} key={department.id}>
                      {department.label}
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="Initial password" className="sm:col-span-2">
                <input className={inputClass} type="password" autoComplete="new-password" {...form.register('password')} />
                {form.formState.errors.password ? (
                  <span className="mt-1 block text-xs text-red-600">{form.formState.errors.password.message}</span>
                ) : null}
              </Field>
            </div>

            <button
              className="mt-6 inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-emerald-600 px-4 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
              type="submit"
              disabled={register.isPending}
            >
              <UserPlus className="size-4" aria-hidden="true" />
              {register.isPending ? '注册中' : '创建账号'}
            </button>
          </form>
        </section>
      </div>
    </main>
  )
}

function flattenDepartmentOptions(
  departments: DepartmentOption[],
  parents: string[] = [],
): Array<{ id: number; label: string }> {
  return departments.flatMap((department) => {
    if (department.status === 'disabled') {
      return []
    }

    const path = [...parents, department.name]

    return [
      { id: department.id, label: path.join(' / ') },
      ...flattenDepartmentOptions(department.children ?? [], path),
    ]
  })
}
