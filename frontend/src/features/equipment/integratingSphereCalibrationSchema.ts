import { z } from 'zod'
import {
  buildCalibrationEquipmentListParams,
  compareDecimalStrings,
  emptyCalibrationEquipmentFilters,
  inspectionFieldErrors,
  normalizeMeasurementInput,
  validateInspectionMediaLimits,
  type CalibrationEquipmentFilters,
  type InspectionEquipmentLedgerRow,
  type InspectionEquipmentOption,
  type InspectionEquipmentSnapshot,
  type InspectionFormEquipment,
  type InspectionFormIssue,
  type InspectionFormStandard,
  type InspectionFormSystem,
  type InspectionMedia,
  type InspectionSystemOption,
} from './inspectionShared'

export type IntegratingSphereCalibrationView = 'records' | 'equipment'

export type CatalogOption = {
  code: string
  label: string
}

export type CatalogFormOptions = {
  modes: CatalogOption[]
  sensitivities: CatalogOption[]
}

export type InspectionFormCatalogOption = {
  source: 'retained' | 'selected'
  code: string
  label: string
}

export type IntegratingSphereCalibrationRecord = {
  id: number
  standard_equipment_id: number | null
  standard_no: string
  standard_name: string
  standard_manufacturer: string | null
  standard_model: string | null
  standard_serial_no: string | null
  standard_next_calibration_date: string | null
  equipment_system_id: number | null
  system_code: string
  system_name: string | null
  mode_code: string
  mode_label: string
  sensitivity_code: string
  sensitivity_label: string
  color_temperature: number
  color_rendering_index: string
  luminous_flux: string
  voltage: string
  current: string
  power: string
  power_factor: string
  frequency: number
  remark: string | null
  recorded_at: string
  operator_id: number | null
  operator_name: string | null
  created_at: string
  updated_at: string
  equipment: InspectionEquipmentSnapshot[]
  photos: InspectionMedia[]
  files: InspectionMedia[]
}

export type IntegratingSphereCalibrationForm = {
  equipment: InspectionFormEquipment[]
  system: InspectionFormSystem | null
  standard: InspectionFormStandard | null
  mode: InspectionFormCatalogOption | null
  sensitivity: InspectionFormCatalogOption | null
  color_temperature: string
  color_rendering_index: string
  luminous_flux: string
  voltage: string
  current: string
  power: string
  power_factor: string
  frequency: string
  remark: string
  retained_media: InspectionMedia[]
  new_photos: File[]
  new_files: File[]
}

export type IntegratingSphereCalibrationFilters = {
  search: string
  mode_code: string
  sensitivity_code: string
  date_from: string
  date_to: string
}

export type IntegratingSphereCalibrationEquipmentFilters = CalibrationEquipmentFilters
export type IntegratingSphereCalibrationEquipmentLedgerRow = InspectionEquipmentLedgerRow
export type IntegratingSphereCalibrationEquipmentOption = InspectionEquipmentOption
export type IntegratingSphereCalibrationSystemOption = InspectionSystemOption

export const emptyIntegratingSphereCalibrationFilters: IntegratingSphereCalibrationFilters = {
  search: '',
  mode_code: '',
  sensitivity_code: '',
  date_from: '',
  date_to: '',
}

export const emptyIntegratingSphereCalibrationEquipmentFilters = emptyCalibrationEquipmentFilters
export const buildIntegratingSphereCalibrationEquipmentListParams = buildCalibrationEquipmentListParams

export const integratingSphereCalibrationMeasurementFields = [
  { name: 'color_temperature', label: '色温', unit: 'K', scale: 0, min: '0', max: '1000000' },
  { name: 'color_rendering_index', label: '显色指数 Ra', unit: '', scale: 1, min: '-9999.9', max: '9999.9' },
  { name: 'luminous_flux', label: '光通量', unit: 'lm', scale: 1, min: '0', max: '99999999999.9' },
  { name: 'voltage', label: '电压', unit: 'V', scale: 1, min: '0', max: '99999999.9' },
  { name: 'current', label: '电流', unit: 'A', scale: 4, min: '0', max: '99999999.9999' },
  { name: 'power', label: '功率', unit: 'W', scale: 4, min: '0', max: '99999999.9999' },
  { name: 'power_factor', label: '功率因数', unit: '', scale: 4, min: '0', max: '1' },
  { name: 'frequency', label: '频率', unit: 'Hz', scale: 0, min: '0', max: '1000000' },
] as const

export type IntegratingSphereCalibrationMeasurementFieldName = (typeof integratingSphereCalibrationMeasurementFields)[number]['name']

export function measurementValueError(
  field: (typeof integratingSphereCalibrationMeasurementFields)[number],
  raw: string,
): string | null {
  if (raw.trim() === '') {
    return `${field.label}不能为空`
  }

  const canonical = normalizeMeasurementInput(raw, field.scale)

  if (canonical === null) {
    return field.scale === 0 ? `${field.label}必须为整数` : `最多保留 ${field.scale} 位小数`
  }

  const min = normalizeMeasurementInput(field.min, field.scale)
  const max = normalizeMeasurementInput(field.max, field.scale)

  if (min === null || max === null) {
    return null
  }

  if (compareDecimalStrings(canonical, min) < 0 || compareDecimalStrings(canonical, max) > 0) {
    return `${field.label}范围必须在 ${field.min} 至 ${field.max} 之间`
  }

  return null
}

export class IntegratingSphereCalibrationFormError extends Error {
  readonly issues: InspectionFormIssue[]

  constructor(issues: InspectionFormIssue[]) {
    super('integrating_sphere_calibration_form_invalid')
    this.name = 'IntegratingSphereCalibrationFormError'
    this.issues = issues
  }
}

export function emptyIntegratingSphereCalibrationForm(): IntegratingSphereCalibrationForm {
  return {
    equipment: [],
    system: null,
    standard: null,
    mode: null,
    sensitivity: null,
    color_temperature: '',
    color_rendering_index: '',
    luminous_flux: '',
    voltage: '',
    current: '',
    power: '',
    power_factor: '',
    frequency: '',
    remark: '',
    retained_media: [],
    new_photos: [],
    new_files: [],
  }
}

export function calibrationFormFromRecord(record: IntegratingSphereCalibrationRecord): IntegratingSphereCalibrationForm {
  return {
    equipment: record.equipment.map((device) => ({
      child_id: device.id,
      equipment_id: device.equipment_id,
      equipment_no: device.equipment_no,
      equipment_name: device.equipment_name,
      manufacturer: device.manufacturer,
      model: device.model,
      serial_no: device.serial_no,
      next_calibration_date: device.next_calibration_date,
    })),
    system: {
      source: 'retained',
      id: record.equipment_system_id,
      code: record.system_code,
      name: record.system_name,
    },
    standard: {
      source: 'retained',
      equipment_id: record.standard_equipment_id,
      standard_no: record.standard_no,
      standard_name: record.standard_name,
      manufacturer: record.standard_manufacturer,
      model: record.standard_model,
      serial_no: record.standard_serial_no,
      next_calibration_date: record.standard_next_calibration_date,
    },
    mode: {
      source: 'retained',
      code: record.mode_code,
      label: record.mode_label,
    },
    sensitivity: {
      source: 'retained',
      code: record.sensitivity_code,
      label: record.sensitivity_label,
    },
    color_temperature: String(record.color_temperature),
    color_rendering_index: String(record.color_rendering_index),
    luminous_flux: String(record.luminous_flux),
    voltage: String(record.voltage),
    current: String(record.current),
    power: String(record.power),
    power_factor: String(record.power_factor),
    frequency: String(record.frequency),
    remark: record.remark ?? '',
    retained_media: [...record.photos, ...record.files],
    new_photos: [],
    new_files: [],
  }
}

export function buildIntegratingSphereCalibrationListParams(
  filters: IntegratingSphereCalibrationFilters,
  page: number,
  perPage: number,
) {
  const entries = Object.entries(filters).filter(([, value]) => value.trim() !== '')

  return { ...Object.fromEntries(entries.map(([key, value]) => [key, value.trim()])), page, per_page: perPage }
}

const measurementShape = integratingSphereCalibrationMeasurementFields.reduce<Record<string, z.ZodType<string, string>>>(
  (shape, field) => ({
    ...shape,
    [field.name]: z.string().superRefine((value, context) => {
      const message = measurementValueError(field, value)

      if (message !== null) {
        context.addIssue({ code: 'custom', message })
      }
    }),
  }),
  {},
)

export const integratingSphereCalibrationSchema = z.object({
  equipment: z.array(z.unknown()).min(1, '请至少录入一台设备'),
  system: z.object({ code: z.string().min(1) }, { message: '请录入系统编码' }).nullable().refine((val) => val !== null, '请录入系统编码'),
  standard: z.object({ standard_no: z.string().min(1) }, { message: '请录入标准件编号' }).nullable().refine((val) => val !== null, '请录入标准件编号'),
  mode: z.object({ code: z.string().trim().min(1) }, { message: '请选择模式' }).nullable().refine((val) => val !== null, '请选择模式'),
  sensitivity: z.object({ code: z.string().trim().min(1) }, { message: '请选择灵敏度' }).nullable().refine((val) => val !== null, '请选择灵敏度'),
  remark: z.string().max(2000, '备注最多2000字').optional(),
  ...measurementShape,
})

export function buildIntegratingSphereCalibrationPayload(
  form: IntegratingSphereCalibrationForm,
  mode: 'create' | 'update',
): FormData {
  const mediaErrors = validateInspectionMediaLimits(form)
  if (Object.keys(mediaErrors).length > 0) {
    const issues = Object.entries(mediaErrors).map(([path, message]) => ({ path: [path], message }))
    throw new IntegratingSphereCalibrationFormError(issues)
  }

  const validated = integratingSphereCalibrationSchema.parse(form)
  const body = new FormData()

  if (mode === 'update') {
    body.append('_method', 'PUT')
  }

  if (mode === 'create' || form.system?.source === 'selected') {
    if (form.system?.id !== null && form.system?.id !== undefined) {
      body.append('equipment_system_id', String(form.system.id))
    }
  }

  if (mode === 'create' || form.standard?.source === 'selected') {
    if (form.standard?.equipment_id !== null && form.standard?.equipment_id !== undefined) {
      body.append('standard_equipment_id', String(form.standard.equipment_id))
    }
  }

  if (mode === 'create' || form.mode?.source === 'selected') {
    body.append('mode_code', validated.mode.code)
  }

  if (mode === 'create' || form.sensitivity?.source === 'selected') {
    body.append('sensitivity_code', validated.sensitivity.code)
  }

  const addedIds = form.equipment.filter((item) => item.child_id === null && item.equipment_id !== null).map((item) => item.equipment_id as number)
  const retainedIds = form.equipment.filter((item) => item.child_id !== null).map((item) => item.child_id as number)

  for (const id of addedIds) {
    body.append('equipment_ids[]', String(id))
  }

  if (mode === 'update') {
    if (retainedIds.length > 0) {
      for (const id of retainedIds) {
        body.append('retained_equipment_ids[]', String(id))
      }
    } else {
      body.append('retained_equipment_ids', '')
    }
  }

  for (const field of integratingSphereCalibrationMeasurementFields) {
    const rawValue = form[field.name]
    const normalized = normalizeMeasurementInput(rawValue, field.scale) ?? rawValue.trim()
    body.append(field.name, normalized)
  }

  if (validated.remark && validated.remark.trim() !== '') {
    body.append('remark', validated.remark.trim())
  }

  if (mode === 'update') {
    if (form.retained_media.length > 0) {
      for (const media of form.retained_media) {
        body.append('retained_media_ids[]', String(media.id))
      }
    } else {
      body.append('retained_media_ids', '')
    }
  }

  for (const photo of form.new_photos) {
    body.append('photos[]', photo)
  }

  for (const file of form.new_files) {
    body.append('files[]', file)
  }

  return body
}

export function integratingSphereCalibrationFieldErrors(error: unknown): Record<string, string> {
  const errors = inspectionFieldErrors(error)

  if (error instanceof IntegratingSphereCalibrationFormError) {
    for (const issue of error.issues) {
      const field = issue.path[0]
      if (typeof field === 'string') {
        errors[field] = issue.message
      }
    }
  }

  if (errors.equipment_ids) {
    errors.equipment = errors.equipment_ids
  }
  if (errors.equipment_system_id) {
    errors.system = errors.equipment_system_id
  }
  if (errors.standard_equipment_id) {
    errors.standard = errors.standard_equipment_id
  }

  return errors
}
