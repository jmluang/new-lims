import { z } from 'zod'
import {
  buildInspectionEquipmentListParams,
  compareDecimalStrings,
  emptyInspectionEquipmentFilters,
  formEquipmentFromSnapshots,
  inspectionFieldErrors,
  inspectionMediaLimits,
  normalizeMeasurementInput,
  validateInspectionMediaLimits,
  type InspectionEquipmentFilters,
  type InspectionEquipmentLedgerRow,
  type InspectionEquipmentOption,
  type InspectionEquipmentSnapshot,
  type InspectionFormEquipment,
  type InspectionFormIssue,
  type InspectionFormSample,
  type InspectionFormSystem,
  type InspectionMedia,
  type InspectionSampleOption,
  type InspectionSystemOption,
} from './inspectionShared'
import { type PhotometricCurveProbe } from './photometricCurveProbes'

export {
  addEquipmentSnapshot,
  equipmentEntryKey,
  normalizeMeasurementInput,
  removeEquipmentSnapshot,
  selectedSample,
  selectedSystem,
} from './inspectionShared'

export type PhotometricCurveEquipmentOption = InspectionEquipmentOption
export type PhotometricCurveSystemOption = InspectionSystemOption
export type PhotometricCurveSampleOption = InspectionSampleOption
export type PhotometricCurveInspectionEquipment = InspectionEquipmentSnapshot
export type PhotometricCurveFormEquipment = InspectionFormEquipment
export type PhotometricCurveFormSample = InspectionFormSample
export type PhotometricCurveFormSystem = InspectionFormSystem
export type PhotometricCurveEquipmentLedgerRow = InspectionEquipmentLedgerRow
export type PhotometricCurveEquipmentFilters = InspectionEquipmentFilters

export const emptyPhotometricCurveEquipmentFilters = emptyInspectionEquipmentFilters
export const buildPhotometricCurveEquipmentListParams = buildInspectionEquipmentListParams
export const photometricCurveMediaLimits = inspectionMediaLimits

/**
 * The four measured angles. They are kept apart from the rest of the grid because
 * the average angle is derived from exactly these and from nothing else.
 */
export const photometricCurveAngleFields = [
  { name: 'c0_180', label: 'C0/180', scale: 1, unit: '°', min: '0', max: '9999.9' },
  { name: 'c30_210', label: 'C30/210', scale: 1, unit: '°', min: '0', max: '9999.9' },
  { name: 'c60_240', label: 'C60/240', scale: 1, unit: '°', min: '0', max: '9999.9' },
  { name: 'c90_270', label: 'C90/270', scale: 1, unit: '°', min: '0', max: '9999.9' },
] as const

/**
 * The remaining measurement grid. `scale` is the number of decimal places the
 * workbook promises and `min`/`max` mirror the column precision of the migration, so
 * the browser refuses the same values the database would refuse instead of failing
 * late on the server. The power factor is a ratio, so its range is tighter than the
 * column that holds it.
 *
 * The workbook's `I`, `F` and `Φ` headings are presentation shorthand, not names:
 * the labels below say what each value is and the units say `A`, `Hz` and `lm`.
 */
export const photometricCurveMeasurementFields = [
  ...photometricCurveAngleFields,
  { name: 'test_distance', label: '测试距离', scale: 4, unit: 'm', min: '0', max: '99999999.9999' },
  { name: 'peak_luminous_intensity', label: '峰值光强', scale: 1, unit: 'cd', min: '0', max: '99999999999.9' },
  { name: 'luminous_flux', label: '光通量', scale: 1, unit: 'lm', min: '0', max: '99999999999.9' },
  { name: 'voltage', label: '电压', scale: 1, unit: 'V', min: '0', max: '99999999.9' },
  { name: 'current', label: '电流', scale: 4, unit: 'A', min: '0', max: '99999999.9999' },
  { name: 'power', label: '功率', scale: 4, unit: 'W', min: '0', max: '99999999.9999' },
  { name: 'power_factor', label: '功率因数', scale: 4, unit: '', min: '0', max: '1' },
  { name: 'frequency', label: '频率', scale: 0, unit: 'Hz', min: '0', max: '1000000' },
] as const

export type PhotometricCurveMeasurement = (typeof photometricCurveMeasurementFields)[number]
export type PhotometricCurveMeasurementField = PhotometricCurveMeasurement['name']
export type PhotometricCurveAngleField = (typeof photometricCurveAngleFields)[number]['name']

export { photometricCurveProbes, probeLabel, type PhotometricCurveProbe } from './photometricCurveProbes'

export type PhotometricCurveMedia = InspectionMedia

export type PhotometricCurveInspectionRecord = {
  id: number
  sample_id: number | null
  sample_no: string
  equipment_system_id: number | null
  system_code: string | null
  system_name: string | null
  c0_180: string
  c30_210: string
  c60_240: string
  c90_270: string
  average_angle: string
  probe: PhotometricCurveProbe
  test_distance: string
  peak_luminous_intensity: string
  luminous_flux: string
  voltage: string
  current: string
  power: string
  power_factor: string
  frequency: number
  remark?: string | null
  recorded_at: string
  operator_id?: number | null
  operator_name?: string | null
  equipment: PhotometricCurveInspectionEquipment[]
  photos: PhotometricCurveMedia[]
  files: PhotometricCurveMedia[]
}

export type PhotometricCurveInspectionForm = {
  sample: PhotometricCurveFormSample | null
  system: PhotometricCurveFormSystem | null
  equipment: PhotometricCurveFormEquipment[]
  probe: PhotometricCurveProbe
  remark: string
  /** Attachments already stored on the record that the operator has kept. */
  retained_media: PhotometricCurveMedia[]
  /** Attachments picked in this editor session and not yet uploaded. */
  new_photos: File[]
  new_files: File[]
} & Record<PhotometricCurveMeasurementField, string>

export type PhotometricCurveInspectionFilters = {
  search: string
  probe: string
  date_from: string
  date_to: string
}

export const emptyPhotometricCurveInspectionFilters: PhotometricCurveInspectionFilters = {
  search: '',
  probe: '',
  date_from: '',
  date_to: '',
}

/** The two page-level views sharing one route and one navigation entry. */
export type PhotometricCurveView = 'records' | 'equipment'



/**
 * The recorded time is deliberately absent from the form. It is a server-owned audit
 * value: the API stamps it once on create and never lets an edit move it, so the
 * editor has nothing to hold and nothing to send.
 */
export function emptyPhotometricCurveInspectionForm(): PhotometricCurveInspectionForm {
  const measurements = Object.fromEntries(
    photometricCurveMeasurementFields.map((field) => [field.name, '']),
  ) as Record<PhotometricCurveMeasurementField, string>

  return {
    sample: null,
    system: null,
    equipment: [],
    probe: 'far_field',
    remark: '',
    retained_media: [],
    new_photos: [],
    new_files: [],
    ...measurements,
  }
}

/**
 * The average of the four measured angles, derived exactly the way the API derives
 * it so the read-only field an operator watches is the value that will be stored.
 *
 * The arithmetic runs on integer tenths and never touches a float: each angle is
 * exactly one decimal place, and a quarter of their sum is rounded half up back to
 * one decimal place. An angle that is not yet a valid one-decimal value leaves the
 * average blank rather than showing a number derived from a partial entry.
 */
export function deriveAverageAngle(values: Record<PhotometricCurveAngleField, string>): string {
  let total = 0

  for (const field of photometricCurveAngleFields) {
    const canonical = normalizeMeasurementInput(values[field.name] ?? '', 1)

    if (canonical === null || canonical.startsWith('-')) {
      return ''
    }

    const [integerPart, fractionPart] = canonical.split('.')
    total += Number(integerPart) * 10 + Number(fractionPart)
  }

  const count = photometricCurveAngleFields.length
  const quotient = Math.floor(total / count)
  const remainder = total - quotient * count
  const rounded = quotient + (remainder * 2 >= count ? 1 : 0)

  return `${Math.floor(rounded / 10)}.${rounded % 10}`
}

export function measurementValueError(field: PhotometricCurveMeasurement, raw: string): string | null {
  if (raw.trim() === '') {
    return '请填写测量值'
  }

  const canonical = normalizeMeasurementInput(raw, field.scale)

  if (canonical === null) {
    return field.scale === 0 ? '请输入整数' : `最多保留 ${field.scale} 位小数`
  }

  const min = normalizeMeasurementInput(field.min, field.scale)
  const max = normalizeMeasurementInput(field.max, field.scale)

  if (min === null || max === null) {
    return null
  }

  if (compareDecimalStrings(canonical, min) < 0 || compareDecimalStrings(canonical, max) > 0) {
    return `请输入 ${min} 到 ${max} 之间的数值`
  }

  return null
}

export class PhotometricCurveFormError extends Error {
  readonly issues: InspectionFormIssue[]

  constructor(issues: InspectionFormIssue[]) {
    super('photometric_curve_form_invalid')
    this.name = 'PhotometricCurveFormError'
    this.issues = issues
  }
}

/**
 * Decimals stay strings all the way to the wire, so the scale the operator typed is
 * the scale the column stores. An integer is emitted as a canonical digit string too
 * — a multipart body carries text either way — after the range check above proved it
 * sits inside the configured column limits.
 */
function measurementPayloadValue(field: PhotometricCurveMeasurement, raw: string): string {
  const canonical = normalizeMeasurementInput(raw, field.scale) ?? ''

  if (field.scale === 0 && !Number.isSafeInteger(Number(canonical))) {
    throw new PhotometricCurveFormError([{ path: [field.name], message: '请输入整数' }])
  }

  return canonical
}

const measurementShape = photometricCurveMeasurementFields.reduce<Record<string, z.ZodType<string, string>>>(
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
) as Record<PhotometricCurveMeasurementField, z.ZodType<string, string>>

export const photometricCurveInspectionSchema = z.object({
  sample: z.object(
    {
      source: z.enum(['retained', 'selected']),
      id: z.number().int().positive().nullable(),
      sample_no: z.string().min(1),
    },
    { error: '请先录入样品编号' },
  ),
  system: z
    .object({
      source: z.enum(['retained', 'selected']),
      id: z.number().int().positive().nullable(),
      code: z.string().min(1),
    })
    .nullable(),
  equipment: z
    .array(z.object({ child_id: z.number().int().positive().nullable(), equipment_id: z.number().int().positive().nullable() }))
    .min(1, '请至少录入一台设备'),
  probe: z.enum(['near_field', 'far_field'], { error: '请选择探头' }),
  remark: z.string(),
  ...measurementShape,
})

export function mediaSelectionError(
  form: PhotometricCurveInspectionForm,
  collection: 'photos' | 'files',
): string | null {
  const errors = validateInspectionMediaLimits(form)

  return errors[collection] ?? null
}

/**
 * Builds the multipart body.
 *
 * Attachments make a JSON body impossible, so every field travels as form data.
 * `retained_equipment_ids` and `retained_media_ids` are always appended, as an empty
 * string when the list is empty, because a multipart body cannot carry an empty
 * array and an absent field means "keep everything" to the API — the opposite of
 * what an operator who cleared the list asked for.
 */
export function buildPhotometricCurveInspectionPayload(
  values: PhotometricCurveInspectionForm,
  mode: 'create' | 'update' = 'create',
): FormData {
  const result = photometricCurveInspectionSchema.safeParse(values)
  const issues: InspectionFormIssue[] = result.success ? [] : [...result.error.issues]

  // A new record must point at a live sample and a live active system; an edit may
  // keep the snapshot of either after its ledger row was renamed or removed. These
  // checks are collected rather than thrown so one failed save marks every field the
  // operator still has to fix, not just the first one.
  if (mode === 'create') {
    if (values.sample !== null && values.sample.id === null) {
      issues.push({ path: ['sample'], message: '请先录入样品编号' })
    }

    if (values.system === null || values.system.id === null) {
      issues.push({ path: ['system'], message: '请先录入系统编码' })
    }
  }

  for (const collection of ['photos', 'files'] as const) {
    const message = mediaSelectionError(values, collection)

    if (message !== null) {
      issues.push({ path: [collection], message })
    }
  }

  if (!result.success || issues.length > 0) {
    throw new PhotometricCurveFormError(issues)
  }

  const parsed = result.data
  const body = new FormData()
  const remark = parsed.remark.trim()

  for (const field of photometricCurveMeasurementFields) {
    body.append(field.name, measurementPayloadValue(field, values[field.name]))
  }

  body.append('probe', parsed.probe)

  if (remark !== '') {
    body.append('remark', remark)
  }

  const addedEquipmentIds = values.equipment
    .filter((device) => device.child_id === null && device.equipment_id !== null)
    .map((device) => device.equipment_id as number)

  for (const id of addedEquipmentIds) {
    body.append('equipment_ids[]', String(id))
  }

  for (const file of values.new_photos) {
    body.append('photos[]', file)
  }

  for (const file of values.new_files) {
    body.append('files[]', file)
  }

  if (mode === 'create') {
    body.append('sample_id', String(parsed.sample.id))
    body.append('equipment_system_id', String((parsed.system as PhotometricCurveFormSystem).id))

    return body
  }

  // Method spoofing: PHP does not populate the file bodies of a real PUT, so an edit
  // that carries attachments is posted and re-labelled here.
  body.append('_method', 'PUT')

  // Omitting `sample_id` tells the API to keep the snapshot it already holds. That is
  // the default for a sample loaded from the record, whether or not its ledger row
  // still exists — only an explicit re-scan asks for a replacement. The same rule
  // applies to the system.
  if (parsed.sample.source === 'selected' && parsed.sample.id !== null) {
    body.append('sample_id', String(parsed.sample.id))
  }

  if (parsed.system !== null && parsed.system.source === 'selected' && parsed.system.id !== null) {
    body.append('equipment_system_id', String(parsed.system.id))
  }

  appendRetainedIds(
    body,
    'retained_equipment_ids',
    values.equipment.filter((device) => device.child_id !== null).map((device) => device.child_id as number),
  )
  appendRetainedIds(body, 'retained_media_ids', values.retained_media.map((media) => media.id))

  return body
}

function appendRetainedIds(body: FormData, field: string, ids: number[]) {
  if (ids.length === 0) {
    body.append(field, '')

    return
  }

  for (const id of ids) {
    body.append(`${field}[]`, String(id))
  }
}

export function photometricCurveFieldErrors(error: unknown): Record<string, string> {
  return inspectionFieldErrors(error)
}

export function buildPhotometricCurveInspectionListParams(
  filters: PhotometricCurveInspectionFilters,
  page: number,
  perPage: number,
) {
  const entries = Object.entries(filters).filter(([, value]) => value !== '')

  return { ...Object.fromEntries(entries), page, per_page: perPage }
}

/**
 * Rebuilds the form from a stored record. Every child snapshot and every attachment
 * is carried over, including the device snapshots whose ledger row has been deleted:
 * they have no live id to re-snapshot, but they can still be retained by their child
 * id, so saving an unrelated measurement edit leaves the history untouched.
 */
export function inspectionFormFromRecord(record: PhotometricCurveInspectionRecord): PhotometricCurveInspectionForm {
  const measurements = Object.fromEntries(
    photometricCurveMeasurementFields.map((field) => [field.name, String(record[field.name] ?? '')]),
  ) as Record<PhotometricCurveMeasurementField, string>

  return {
    sample: { source: 'retained', id: record.sample_id, sample_no: record.sample_no },
    system: record.system_code === null
      ? null
      : { source: 'retained', id: record.equipment_system_id, code: record.system_code, name: record.system_name },
    equipment: formEquipmentFromSnapshots(record.equipment),
    probe: record.probe,
    remark: record.remark ?? '',
    retained_media: [...record.photos, ...record.files],
    new_photos: [],
    new_files: [],
    ...measurements,
  }
}
