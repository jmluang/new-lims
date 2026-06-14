import { zodResolver } from '@hookform/resolvers/zod'
import { Save, X } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zhText } from '../../lib/zh'
import { Button, ErrorNotice, Field } from '../system/shared'
import { inputClass, textareaClass } from '../system/utils'
import { type Customer, type FieldPermissionMeta } from './CustomerListPage'
import { filterForbiddenCustomerFields } from './customerPermissions'
import { customerSchema, type CustomerFormValues } from './customerSchema'

export function CustomerForm({
  customer,
  fieldPermissions,
  submitting,
  error,
  onSubmit,
  onCancel,
}: {
  customer?: Customer | null
  fieldPermissions?: FieldPermissionMeta
  submitting: boolean
  error: unknown
  onSubmit: (values: CustomerFormValues) => Promise<void>
  onCancel: () => void
}) {
  const form = useForm<CustomerFormValues>({
    resolver: zodResolver(customerSchema),
    defaultValues: defaultValues(customer),
  })

  useEffect(() => {
    form.reset(defaultValues(customer))
  }, [customer, form])

  async function submit(values: CustomerFormValues) {
    await onSubmit(filterForbiddenCustomerFields(values, fieldPermissions))
  }

  return (
    <form className="space-y-4" onSubmit={form.handleSubmit(submit)}>
      {error ? <ErrorNotice error={error} fallback="Unable to save customer" /> : null}

      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Name">
          <input className={inputClass} {...form.register('name')} />
          {form.formState.errors.name ? <span className="mt-1 block text-xs text-red-600">{form.formState.errors.name.message}</span> : null}
        </Field>

        <SensitiveField label="Credit code" field="credit_code" permissions={fieldPermissions}>
          <input className={inputClass} disabled={!canUpdate(fieldPermissions, 'credit_code')} {...form.register('credit_code')} />
        </SensitiveField>

        <SensitiveField label="Phone" field="phone" permissions={fieldPermissions}>
          <input className={inputClass} disabled={!canUpdate(fieldPermissions, 'phone')} {...form.register('phone')} />
        </SensitiveField>

        <SensitiveField label="Email" field="email" permissions={fieldPermissions}>
          <input className={inputClass} type="email" disabled={!canUpdate(fieldPermissions, 'email')} {...form.register('email')} />
          {form.formState.errors.email ? <span className="mt-1 block text-xs text-red-600">{form.formState.errors.email.message}</span> : null}
        </SensitiveField>

        <Field label="Status">
          <select className={inputClass} {...form.register('status')}>
            <option value="active">{zhText('active')}</option>
            <option value="disabled">{zhText('disabled')}</option>
          </select>
        </Field>
      </div>

      <Field label="Address">
        <input className={inputClass} {...form.register('address')} />
      </Field>

      <Field label="Remark">
        <textarea className={textareaClass} {...form.register('remark')} />
      </Field>

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

function SensitiveField({
  label,
  field,
  permissions,
  children,
}: {
  label: string
  field: 'credit_code' | 'phone' | 'email'
  permissions?: FieldPermissionMeta
  children: ReactNode
}) {
  if (permissions?.[field]?.hidden) {
    return null
  }

  return (
    <Field label={label}>
      {children}
      {!canUpdate(permissions, field) ? <span className="mt-1 block text-xs text-slate-500">{zhText('No update permission')}</span> : null}
    </Field>
  )
}

function defaultValues(customer?: Customer | null): CustomerFormValues {
  return {
    name: customer?.name ?? '',
    credit_code: customer?.credit_code ?? '',
    phone: customer?.phone ?? '',
    email: customer?.email ?? '',
    address: customer?.address ?? '',
    remark: customer?.remark ?? '',
    status: customer?.status ?? 'active',
  }
}

function canUpdate(permissions: FieldPermissionMeta | undefined, field: string) {
  return permissions?.[field]?.update !== false
}
