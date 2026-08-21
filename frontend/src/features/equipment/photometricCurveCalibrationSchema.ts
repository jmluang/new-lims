import { z } from 'zod'
import {
  buildCalibrationEquipmentListParams,
  compareDecimalStrings,
  emptyCalibrationEquipmentFilters,
  formEquipmentFromSnapshots,
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
import { photometricCurveProbes, type PhotometricCurveProbe } from './photometricCurveProbes'

export { photometricCurveProbes, probeLabel, type PhotometricCurveProbe } from './photometricCurveProbes'

/** The two page-level views sharing one route and one navigation entry. */
export type PhotometricCurveCalibrationView = 'records' | 'equipment'

export type PhotometricCurveCalibrationEquipmentOption = InspectionEquipmentOption
export type PhotometricCurveCalibrationSystemOption = InspectionSystemOption
export type PhotometricCurveCalibrationEquipmentSnapshot = InspectionEquipmentSnapshot
export type PhotometricCurveCalibrationEquipmentLedgerRow = InspectionEquipmentLedgerRow
export type PhotometricCurveCalibrationEquipmentFilters = CalibrationEquipmentFilters

/**
 * Every measurement of this form, with the exact scale, unit and physical bounds
 * the API enforces. The list drives the editor grid, the validation schema and the
 * payload builder, so a field can never be present in one and missing from another.
 */
export const photometricCurveCalibrationMeasurementFields = [
  { name: 'test_distance', label: '测试距离', unit: 'm', scale: 4, min: '0', max: '99999999.9999' },
  { name: 'calibration_coefficient', label: '定标系数', unit: '', scale: 4, min: '0', max: '99999999.9999' },
  { name: 'peak_luminous_intensity', label: '峰值光强', unit: 'cd', scale: 1, min: '0', max: '99999999999.9' },
  { name: 'luminous_flux', label: '光通量', unit: 'lm', scale: 1, min: '0', max: '99999999999.9' },
  { name: 'voltage', label: '电压', unit: 'V', scale: 1, min: '0', max: '99999999.9' },
  { name: 'current', label: '电流', unit: 'A', scale: 4, min: '0', max: '99999999.9999' },
  { name: 'power', label: '功率', unit: 'W', scale: 4, min: '0', max: '99999999.9999' },
  { name: 'power_factor', label: '功率因数', unit: '', scale: 4, min: '0', max: '1' },
  { name: 'frequency', label: '频率', unit: 'Hz', scale: 0, min: '0', max: '1000000' },
] as const

export type PhotometricCurveCalibrationMeasurement = (typeof photometricCurveCalibrationMeasurementFields)[number]
export type PhotometricCurveCalibrationMeasurementField = PhotometricCurveCalibrationMeasurement['name']

export type PhotometricCurveCalibrationRecord = {
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
  probe: PhotometricCurveProbe
  test_distance: string
  calibration_coefficient: string
  peak_luminous_intensity: string
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
  equipment: PhotometricCurveCalibrationEquipmentSnapshot[]
  photos: InspectionMedia[]
  files: InspectionMedia[]
}

export type PhotometricCurveCalibrationForm = {
  equipment: InspectionFormEquipment[]
  system: InspectionFormSystem | null
  standard: InspectionFormStandard | null
  probe: PhotometricCurveProbe
  remark: string
  /** Attachments already stored on the record that the operator has kept. */
  retained_media: InspectionMedia[]
  /** Attachments picked in this editor session and not yet uploaded. */
  new_photos: File[]
  new_files: File[]
} & Record<PhotometricCurveCalibrationMeasurementField, string>

export type PhotometricCurveCalibrationFilters = {
  search: string
  probe: string
  date_from: string
  date_to: string
}

export const emptyPhotometricCurveCalibrationFilters: PhotometricCurveCalibrationFilters = {
  search: '',
  probe: '',
  date_from: '',
  date_to: '',
}

export const emptyPhotometricCurveCalibrationEquipmentFilters = emptyCalibrationEquipmentFilters
export const buildPhotometricCurveCalibrationEquipmentListParams = buildCalibrationEquipmentListParams

/**
 * The recorded time is deliberately absent from the form. It is a server-owned audit
 * value: the API stamps it once on create and never lets an edit move it, so the
 * editor has nothing to hold and nothing to send.
 */
export function emptyPhotometricCurveCalibrationForm(): PhotometricCurveCalibrationForm {
  const measurements = Object.fromEntries(
    photometricCurveCalibrationMeasurementFields.map((field) => [field.name, '']),
  ) as Record<PhotometricCurveCalibrationMeasurementField, string>

  return {
    equipment: [],
    system: null,
    standard: null,
    probe: 'far_field',
    remark: '',
    retained_media: [],
    new_photos: [],
    new_files: [],
    ...measurements,
  }
}

/**
 * Rebuilds the editor from a stored record. Every ledger subject comes back as a
 * `retained` snapshot, so saving an untouched form re-declares nothing and the
 * record keeps the exact evidence it was filed with.
 */
export function calibrationFormFromRecord(record: PhotometricCurveCalibrationRecord): PhotometricCurveCalibrationForm {
  const measurements = Object.fromEntries(
    photometricCurveCalibrationMeasurementFields.map((field) => [field.name, String(record[field.name])]),
  ) as Record<PhotometricCurveCalibrationMeasurementField, string>

  return {
    equipment: formEquipmentFromSnapshots(record.equipment),
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
    probe: record.probe,
    remark: record.remark ?? '',
    retained_media: [...record.photos, ...record.files],
    new_photos: [],
    new_files: [],
    ...measurements,
  }
}

/**
 * Validates one typed measurement against the scale and bounds the API enforces,
 * comparing canonical decimal strings so nothing is decided by a float.
 */
export function measurementValueError(field: PhotometricCurveCalibrationMeasurement, raw: string): string | null {
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

export class PhotometricCurveCalibrationFormError extends Error {
  readonly issues: InspectionFormIssue[]

  constructor(issues: InspectionFormIssue[]) {
    super('photometric_curve_calibration_form_invalid')
    this.name = 'PhotometricCurveCalibrationFormError'
    this.issues = issues
  }
}

export function buildPhotometricCurveCalibrationListParams(
  filters: PhotometricCurveCalibrationFilters,
  page: number,
  perPage: number,
) {
  const entries = Object.entries(filters).filter(([, value]) => value.trim() !== '')

  return { ...Object.fromEntries(entries.map(([key, value]) => [key, value.trim()])), page, per_page: perPage }
}

const measurementShape = photometricCurveCalibrationMeasurementFields.reduce<Record<string, z.ZodType<string, string>>>(
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

export const photometricCurveCalibrationSchema = z.object({
  equipment: z.array(z.unknown()).min(1, '请至少录入一台设备'),
  system: z
    .object({ code: z.string().min(1) }, { message: '请录入系统编码' })
    .nullable()
    .refine((value) => value !== null, '请录入系统编码'),
  standard: z
    .object({ standard_no: z.string().min(1) }, { message: '请录入标准件编号' })
    .nullable()
    .refine((value) => value !== null, '请录入标准件编号'),
  probe: z.enum(
    photometricCurveProbes.map((option) => option.value) as [PhotometricCurveProbe, ...PhotometricCurveProbe[]],
    { error: '请选择探头' },
  ),
  remark: z.string().max(2000, '备注最多2000字').optional(),
  ...measurementShape,
})

/**
 * Builds the multipart body the API expects.
 *
 * A ledger reference is only declared when the operator selected it in this session:
 * an untouched `retained` standard or system is left out entirely so the record keeps
 * its stored snapshot even after the ledger row was renamed or deleted. Measurements
 * are canonicalized as strings so the scale the operator typed is the scale stored.
 */
export function buildPhotometricCurveCalibrationPayload(
  form: PhotometricCurveCalibrationForm,
  mode: 'create' | 'update',
): FormData {
  const mediaErrors = validateInspectionMediaLimits(form)

  if (Object.keys(mediaErrors).length > 0) {
    throw new PhotometricCurveCalibrationFormError(
      Object.entries(mediaErrors).map(([path, message]) => ({ path: [path], message })),
    )
  }

  const validated = photometricCurveCalibrationSchema.parse(form)
  const body = new FormData()

  if (mode === 'update') {
    body.append('_method', 'PUT')
  }

  if ((mode === 'create' || form.system?.source === 'selected') && form.system?.id != null) {
    body.append('equipment_system_id', String(form.system.id))
  }

  if ((mode === 'create' || form.standard?.source === 'selected') && form.standard?.equipment_id != null) {
    body.append('standard_equipment_id', String(form.standard.equipment_id))
  }

  const addedIds = form.equipment
    .filter((device) => device.child_id === null && device.equipment_id !== null)
    .map((device) => device.equipment_id as number)
  const retainedIds = form.equipment
    .filter((device) => device.child_id !== null)
    .map((device) => device.child_id as number)

  for (const id of addedIds) {
    body.append('equipment_ids[]', String(id))
  }

  if (mode === 'update') {
    if (retainedIds.length > 0) {
      for (const id of retainedIds) {
        body.append('retained_equipment_ids[]', String(id))
      }
    } else {
      // An empty list cannot be spelled in a multipart body, so the field is sent
      // blank and the API reads it as "retain nothing".
      body.append('retained_equipment_ids', '')
    }
  }

  body.append('probe', validated.probe)

  for (const field of photometricCurveCalibrationMeasurementFields) {
    const raw = form[field.name]
    body.append(field.name, normalizeMeasurementInput(raw, field.scale) ?? raw.trim())
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

/**
 * Maps a thrown schema error or an API validation response onto the editor fields,
 * including the API's own names for the three scanned subjects.
 */
export function photometricCurveCalibrationFieldErrors(error: unknown): Record<string, string> {
  const errors = inspectionFieldErrors(error)

  if (error instanceof PhotometricCurveCalibrationFormError) {
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
