import { zodResolver } from '@hookform/resolvers/zod'
import { Save, X } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { Button, ErrorNotice, Field } from '../system/shared'
import { inputClass, textareaClass } from '../system/utils'
import { zhText } from '../../lib/zh'
import { type Equipment, type EquipmentLocation, type FieldPermissionMeta } from './EquipmentListPage'
import { activeLocationOptions } from './equipmentLocationOptions'
import { equipmentSchema, type EquipmentFormValues } from './equipmentSchema'
import type { EquipmentSystem } from './EquipmentSystemPage'

export function EquipmentForm({
  equipment,
  locations,
  systems,
  fieldPermissions,
  submitting,
  error,
  onSubmit,
  onCancel,
}: {
  equipment?: Equipment | null
  locations: EquipmentLocation[]
  systems: EquipmentSystem[]
  fieldPermissions?: FieldPermissionMeta
  submitting: boolean
  error: unknown
  onSubmit: (values: EquipmentFormValues) => Promise<void>
  onCancel: () => void
}) {
  const form = useForm<EquipmentFormValues>({
    resolver: zodResolver(equipmentSchema),
    defaultValues: defaultValues(equipment),
  })
  const locationOptions = activeLocationOptions(locations)
  const systemOptions = systems.filter((system) => system.status === 'active' || system.id === equipment?.system_id)

  useEffect(() => {
    form.reset(defaultValues(equipment))
  }, [equipment, form])

  async function submit(values: EquipmentFormValues) {
    await onSubmit(filterForbidden(values, fieldPermissions))
  }

  return (
    <form className="space-y-4" onSubmit={form.handleSubmit(submit)}>
      {error ? <ErrorNotice error={error} fallback="Unable to save equipment" /> : null}

      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Equipment no">
          <input className={inputClass} {...form.register('equipment_no')} />
        </Field>
        <Field label="Name">
          <input className={inputClass} {...form.register('name')} />
        </Field>
        <Field label="Manufacturer">
          <input className={inputClass} {...form.register('manufacturer')} />
        </Field>
        <Field label="Model">
          <input className={inputClass} {...form.register('model')} />
        </Field>
        <SensitiveField label="Serial no" field="serial_no" permissions={fieldPermissions}>
          <input className={inputClass} disabled={!canUpdate(fieldPermissions, 'serial_no')} {...form.register('serial_no')} />
        </SensitiveField>
        <Field label="Location">
          <select className={inputClass} {...form.register('location_id')}>
            <option value="">{zhText('No location')}</option>
            {locationOptions.map((location) => (
              <option value={location.id} key={location.id}>
                {location.label}
              </option>
            ))}
          </select>
        </Field>
        <Field label="System">
          <select className={inputClass} {...form.register('system_id')}>
            <option value="">No system</option>
            {systemOptions.map((system) => (
              <option value={system.id} key={system.id}>
                {system.name}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Status">
          <select className={inputClass} {...form.register('status')}>
            {['active', 'maintenance', 'retired', 'disabled'].map((status) => (
              <option value={status} key={status}>
                {zhText(status)}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Purchase date">
          <input className={inputClass} type="date" {...form.register('purchase_date')} />
        </Field>
        <Field label="Enable date">
          <input className={inputClass} type="date" {...form.register('enable_date')} />
        </Field>
        <Field label="Calibration date">
          <input className={inputClass} type="date" {...form.register('calibration_date')} />
        </Field>
        <Field label="Next calibration">
          <input className={inputClass} type="date" {...form.register('next_calibration_date')} />
        </Field>
        <Field label="Calibration duration">
          <input className={inputClass} {...form.register('calibration_duration')} />
        </Field>
        <SensitiveField label="Device image" field="device_image" permissions={fieldPermissions}>
          <input className={inputClass} disabled={!canUpdate(fieldPermissions, 'device_image')} {...form.register('device_image')} />
        </SensitiveField>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        {(['manual_files', 'instruction_files', 'calibration_files', 'other_files'] as const).map((field) =>
          fieldPermissions?.[field]?.hidden ? null : (
            <Field label={fileFieldLabels[field]} key={field}>
              <input className={inputClass} disabled={!canUpdate(fieldPermissions, field)} placeholder={zhText('comma separated file refs') ?? undefined} {...form.register(field)} />
            </Field>
          ),
        )}
      </div>

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

const fileFieldLabels = {
  manual_files: '说明书文件',
  instruction_files: '操作规程文件',
  calibration_files: '校准文件',
  other_files: '其他文件',
} as const

function defaultValues(equipment?: Equipment | null): EquipmentFormValues {
  return {
    equipment_no: equipment?.equipment_no ?? '',
    name: equipment?.name ?? '',
    manufacturer: equipment?.manufacturer ?? '',
    model: equipment?.model ?? '',
    serial_no: equipment?.serial_no ?? '',
    location_id: equipment?.location_id ? String(equipment.location_id) : '',
    system_id: equipment?.system_id ? String(equipment.system_id) : '',
    purchase_date: equipment?.purchase_date ?? '',
    enable_date: equipment?.enable_date ?? '',
    calibration_date: equipment?.calibration_date ?? '',
    calibration_duration: equipment?.calibration_duration ?? '',
    next_calibration_date: equipment?.next_calibration_date ?? '',
    status: equipment?.status ?? 'active',
    device_image: equipment?.device_image ?? '',
    manual_files: (equipment?.manual_files ?? []).join(', '),
    instruction_files: (equipment?.instruction_files ?? []).join(', '),
    calibration_files: (equipment?.calibration_files ?? []).join(', '),
    other_files: (equipment?.other_files ?? []).join(', '),
    remark: equipment?.remark ?? '',
  }
}

function filterForbidden(values: EquipmentFormValues, permissions?: FieldPermissionMeta): EquipmentFormValues {
  const next: Partial<EquipmentFormValues> = { ...values }

  for (const field of ['serial_no', 'device_image', 'manual_files', 'instruction_files', 'calibration_files', 'other_files']) {
    if (!canUpdate(permissions, field)) {
      delete next[field as keyof EquipmentFormValues]
    }
  }

  return next as EquipmentFormValues
}

function canUpdate(permissions: FieldPermissionMeta | undefined, field: string) {
  const fieldPermission = permissions?.[field]

  return fieldPermission ? fieldPermission.update === true : true
}

function SensitiveField({
  label,
  field,
  permissions,
  children,
}: {
  label: string
  field: string
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
