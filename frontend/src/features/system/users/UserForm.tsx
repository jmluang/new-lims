import { zodResolver } from '@hookform/resolvers/zod'
import { Save, X } from 'lucide-react'
import { useEffect } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'
import { useEffectivePermissions } from '../../auth/useCurrentUser'
import { Button, ErrorNotice, Field } from '../shared'
import { inputClass } from '../utils'
import { canUpdateUserPhone } from './userPermissions'

export type UserGroupOption = {
  id: number
  key?: string
  name: string
  status?: string
}

export type DepartmentOption = {
  id: number
  name: string
  status?: string
}

export type SystemUser = {
  id: number
  name: string
  email: string
  phone?: string | null
  status: 'active' | 'disabled' | 'locked'
  must_change_password?: boolean
  locked_at?: string | null
  lock_reason?: string | null
  department?: { id: number; name: string } | null
  groups: UserGroupOption[]
}

const baseSchema = z.object({
  name: z.string().min(1, '请填写名称'),
  email: z.email('请输入有效邮箱'),
  phone: z.string().optional(),
  department_id: z.string().optional(),
  status: z.enum(['active', 'disabled', 'locked']),
  must_change_password: z.boolean(),
  password: z.string().optional(),
  group_ids: z.array(z.number()),
})

export type UserFormValues = z.infer<typeof baseSchema>

export function UserForm({
  user,
  groups,
  departments,
  submitting,
  error,
  onSubmit,
  onCancel,
}: {
  user?: SystemUser | null
  groups: UserGroupOption[]
  departments: DepartmentOption[]
  submitting: boolean
  error: unknown
  onSubmit: (values: UserFormValues) => Promise<void>
  onCancel: () => void
}) {
  const permissions = useEffectivePermissions()
  const canUpdatePhone = canUpdateUserPhone(permissions.data)
  const isEditing = Boolean(user)
  const form = useForm<UserFormValues>({
    resolver: zodResolver(
      baseSchema.superRefine((value, context) => {
        if (!isEditing && (!value.password || value.password.length < 8)) {
          context.addIssue({
            code: 'custom',
            path: ['password'],
            message: '密码至少 8 位',
          })
        }
      }),
    ),
    defaultValues: defaultValues(user),
  })
  const selectedGroupIds = useWatch({ control: form.control, name: 'group_ids' }) ?? []

  useEffect(() => {
    form.reset(defaultValues(user))
  }, [form, user])

  async function submit(values: UserFormValues) {
    const payload = canUpdatePhone ? values : { ...values, phone: undefined }
    await onSubmit(payload)
  }

  return (
    <form className="space-y-4" onSubmit={form.handleSubmit(submit)}>
      {error ? <ErrorNotice error={error} fallback="Unable to save user" /> : null}

      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Name">
          <input className={inputClass} {...form.register('name')} />
          {form.formState.errors.name ? (
            <span className="mt-1 block text-xs text-red-600">{form.formState.errors.name.message}</span>
          ) : null}
        </Field>

        <Field label="Email">
          <input className={inputClass} type="email" {...form.register('email')} />
          {form.formState.errors.email ? (
            <span className="mt-1 block text-xs text-red-600">{form.formState.errors.email.message}</span>
          ) : null}
        </Field>

        <Field label="Phone">
          <input className={inputClass} disabled={!canUpdatePhone} {...form.register('phone')} />
          {!canUpdatePhone ? <span className="mt-1 block text-xs text-slate-500">No update permission</span> : null}
        </Field>

        <Field label="Department">
          <select className={inputClass} {...form.register('department_id')}>
            <option value="">No department</option>
            {departments.map((department) => (
              <option value={department.id} key={department.id}>
                {department.name}
              </option>
            ))}
          </select>
        </Field>

        <Field label="Status">
          <select className={inputClass} {...form.register('status')}>
            <option value="active">active</option>
            <option value="disabled">disabled</option>
            <option value="locked">locked</option>
          </select>
        </Field>

        {!isEditing ? (
          <Field label="Initial password">
            <input className={inputClass} type="password" autoComplete="new-password" {...form.register('password')} />
            {form.formState.errors.password ? (
              <span className="mt-1 block text-xs text-red-600">{form.formState.errors.password.message}</span>
            ) : null}
          </Field>
        ) : null}

        <label className="flex items-center gap-2 pt-6 text-sm text-slate-700">
          <input className="size-4 rounded border-slate-300 text-emerald-600" type="checkbox" {...form.register('must_change_password')} />
          Must change password
        </label>
      </div>

      <div>
        <div className="text-xs font-medium uppercase tracking-normal text-slate-500">Groups</div>
        <div className="mt-2 grid gap-2 sm:grid-cols-2">
          {groups.map((group) => (
            <label
              className="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700"
              key={group.id}
            >
              <input
                className="size-4 rounded border-slate-300 text-emerald-600"
                type="checkbox"
                value={group.id}
                checked={selectedGroupIds.includes(group.id)}
                onChange={(event) => {
                  const selected = new Set(form.getValues('group_ids'))
                  if (event.target.checked) {
                    selected.add(group.id)
                  } else {
                    selected.delete(group.id)
                  }
                  form.setValue('group_ids', Array.from(selected), { shouldDirty: true })
                }}
              />
              <span className="truncate">{group.name}</span>
            </label>
          ))}
        </div>
      </div>

      <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
        <Button type="button" variant="ghost" onClick={onCancel}>
          <X className="size-4" aria-hidden="true" />
          Cancel
        </Button>
        <Button type="submit" variant="primary" disabled={submitting}>
          <Save className="size-4" aria-hidden="true" />
          Save
        </Button>
      </div>
    </form>
  )
}

function defaultValues(user?: SystemUser | null): UserFormValues {
  return {
    name: user?.name ?? '',
    email: user?.email ?? '',
    phone: user?.phone ?? '',
    department_id: user?.department?.id ? String(user.department.id) : '',
    status: user?.status ?? 'active',
    must_change_password: user?.must_change_password ?? true,
    password: '',
    group_ids: user?.groups.map((group) => group.id) ?? [],
  }
}
