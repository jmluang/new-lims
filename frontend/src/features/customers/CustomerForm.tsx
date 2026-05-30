import { zodResolver } from '@hookform/resolvers/zod'
import { Save, X } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect } from 'react'
import { type UseFormRegisterReturn, useForm } from 'react-hook-form'
import { zhText } from '../../lib/zh'
import { Button, ErrorNotice, Field } from '../system/shared'
import { inputClass, textareaClass } from '../system/utils'
import { type Customer, type DictionarySet, type FieldPermissionMeta } from './CustomerListPage'
import { filterForbiddenCustomerFields } from './customerPermissions'
import { customerSchema, type CustomerFormValues } from './customerSchema'

type DictionaryCodes = 'customer.type' | 'customer.level' | 'customer.source' | 'customer.industry' | 'customer.status'

export function CustomerForm({
  customer,
  dictionaries,
  fieldPermissions,
  submitting,
  error,
  onSubmit,
  onCancel,
}: {
  customer?: Customer | null
  dictionaries: DictionarySet[]
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

        <SelectField label="Type" code="customer.type" dictionaries={dictionaries} valueProps={form.register('type')} />
        <SelectField label="Level" code="customer.level" dictionaries={dictionaries} valueProps={form.register('level')} />
        <SelectField label="Source" code="customer.source" dictionaries={dictionaries} valueProps={form.register('source')} />
        <SelectField label="Industry" code="customer.industry" dictionaries={dictionaries} valueProps={form.register('industry')} />

        <SensitiveField label="Phone" field="phone" permissions={fieldPermissions}>
          <input className={inputClass} disabled={!canUpdate(fieldPermissions, 'phone')} {...form.register('phone')} />
        </SensitiveField>

        <SensitiveField label="Email" field="email" permissions={fieldPermissions}>
          <input className={inputClass} type="email" disabled={!canUpdate(fieldPermissions, 'email')} {...form.register('email')} />
          {form.formState.errors.email ? <span className="mt-1 block text-xs text-red-600">{form.formState.errors.email.message}</span> : null}
        </SensitiveField>

        <SelectField label="Status" code="customer.status" dictionaries={dictionaries} valueProps={form.register('status')} fallbackOptions={['active', 'disabled']} />
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

function SelectField({
  label,
  code,
  dictionaries,
  valueProps,
  fallbackOptions = [],
}: {
  label: string
  code: DictionaryCodes
  dictionaries: DictionarySet[]
  valueProps: UseFormRegisterReturn
  fallbackOptions?: string[]
}) {
  const options = dictionaryOptions(dictionaries, code)
  const renderedOptions = options.length > 0 ? options : fallbackOptions.map((value) => ({ value, label: value, status: 'active' }))

  return (
    <Field label={label}>
      <select className={inputClass} {...valueProps}>
        <option value="">{zhText('Unset')}</option>
        {renderedOptions.map((option) => (
          <option value={option.value} disabled={option.status === 'disabled'} key={option.value}>
            {zhText(option.label)}
          </option>
        ))}
      </select>
    </Field>
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
    type: customer?.type ?? '',
    level: customer?.level ?? '',
    source: customer?.source ?? '',
    industry: customer?.industry ?? '',
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

function dictionaryOptions(dictionaries: DictionarySet[], code: string) {
  return dictionaries.find((dictionary) => dictionary.code === code)?.items ?? []
}
