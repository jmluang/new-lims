import { zodResolver } from '@hookform/resolvers/zod'
import { Save, X } from 'lucide-react'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { Button, ErrorNotice, Field } from '../system/shared'
import { inputClass } from '../system/utils'
import { filterForbiddenStandardFields } from './standardPermissions'
import { standardSchema, type StandardFormValues } from './standardSchema'
import type { FieldPermissionMeta, Standard } from './StandardListPage'

export function StandardForm({
  standard,
  fieldPermissions,
  submitting,
  error,
  onSubmit,
  onCancel,
}: {
  standard?: Standard | null
  fieldPermissions?: FieldPermissionMeta
  submitting: boolean
  error: unknown
  onSubmit: (values: Partial<StandardFormValues>) => Promise<void>
  onCancel: () => void
}) {
  const form = useForm<StandardFormValues>({
    resolver: zodResolver(standardSchema),
    defaultValues: defaultValues(standard),
  })

  useEffect(() => {
    form.reset(defaultValues(standard))
  }, [standard, form])

  async function submit(values: StandardFormValues) {
    await onSubmit(filterForbiddenStandardFields(values, fieldPermissions))
  }

  return (
    <form className="space-y-4" onSubmit={form.handleSubmit(submit)}>
      {error ? <ErrorNotice error={error} fallback="Unable to save standard" /> : null}

      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Standard number">
          <input className={inputClass} disabled={!canUpdate(fieldPermissions, 'std_no')} {...form.register('std_no')} />
          {form.formState.errors.std_no ? <span className="mt-1 block text-xs text-red-600">{form.formState.errors.std_no.message}</span> : null}
        </Field>
        <Field label="Chinese name">
          <input className={inputClass} disabled={!canUpdate(fieldPermissions, 'chinese_name')} {...form.register('chinese_name')} />
          {form.formState.errors.chinese_name ? <span className="mt-1 block text-xs text-red-600">{form.formState.errors.chinese_name.message}</span> : null}
        </Field>
        <Field label="Publish date">
          <input className={inputClass} type="date" {...form.register('publish_date')} />
        </Field>
        <Field label="Implement date">
          <input className={inputClass} type="date" {...form.register('implement_date')} />
        </Field>
        <Field label="Status">
          <select className={inputClass} {...form.register('status')}>
            {['active', 'pending', 'abolished', 'replaced', 'disabled'].map((status) => (
              <option value={status} key={status}>
                {status}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Abolish date">
          <input className={inputClass} type="date" {...form.register('abolish_date')} />
        </Field>
        <Field label="Replaced by">
          <input className={inputClass} {...form.register('replaced_by')} />
        </Field>
        <Field label="Corresponding standard">
          <input className={inputClass} {...form.register('corresponding_std')} />
        </Field>
        <Field label="Category">
          <input className={inputClass} {...form.register('category')} />
        </Field>
        <Field label="Language">
          <input className={inputClass} {...form.register('language')} />
        </Field>
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

function defaultValues(standard?: Standard | null): StandardFormValues {
  return {
    std_no: standard?.std_no ?? '',
    chinese_name: standard?.chinese_name ?? '',
    publish_date: standard?.publish_date ?? '',
    implement_date: standard?.implement_date ?? '',
    status: standard?.status ?? 'active',
    abolish_date: standard?.abolish_date ?? '',
    replaced_by: standard?.replaced_by ?? '',
    corresponding_std: standard?.corresponding_std ?? '',
    category: standard?.category ?? '',
    language: standard?.language ?? '',
  }
}

function canUpdate(permissions: FieldPermissionMeta | undefined, field: string) {
  return permissions?.[field]?.update !== false
}
