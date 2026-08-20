import { z } from 'zod'

/**
 * The measurement grid of the integrating-sphere form. `scale` is the number of
 * decimal places the paper form promises and `min`/`max` mirror the column
 * precision of the migration, so the browser refuses the same values the database
 * would refuse instead of failing late on the server.
 */
export const integratingSphereMeasurementFields = [
  { name: 'chromaticity_x', label: '色品坐标 X', scale: 4, unit: '', min: '0', max: '99.9999' },
  { name: 'chromaticity_y', label: '色品坐标 Y', scale: 4, unit: '', min: '0', max: '99.9999' },
  { name: 'dominant_wavelength', label: '主波长', scale: 1, unit: 'nm', min: '0', max: '999999.9' },
  { name: 'peak_wavelength', label: '峰值波长', scale: 1, unit: 'nm', min: '0', max: '999999.9' },
  { name: 'color_temperature', label: '色温', scale: 0, unit: 'K', min: '0', max: '1000000' },
  { name: 'color_rendering_index', label: '显色指数 Ra', scale: 1, unit: '', min: '-9999.9', max: '9999.9' },
  { name: 'luminous_flux', label: '光通量', scale: 1, unit: 'lm', min: '0', max: '99999999999.9' },
  { name: 'voltage', label: '电压', scale: 1, unit: 'V', min: '0', max: '99999999.9' },
  { name: 'current', label: '电流', scale: 4, unit: 'A', min: '0', max: '99999999.9999' },
  { name: 'power', label: '功率', scale: 4, unit: 'W', min: '0', max: '99999999.9999' },
  { name: 'power_factor', label: '功率因数', scale: 4, unit: '', min: '0', max: '99.9999' },
  { name: 'frequency', label: '频率', scale: 0, unit: 'Hz', min: '0', max: '1000000' },
] as const

export type IntegratingSphereMeasurement = (typeof integratingSphereMeasurementFields)[number]
export type IntegratingSphereMeasurementField = IntegratingSphereMeasurement['name']

export type IntegratingSphereEquipmentOption = {
  id: number
  equipment_no: string
  equipment_name: string
  manufacturer?: string | null
  model?: string | null
  serial_no?: string | null
  next_calibration_date?: string | null
}

export type IntegratingSphereSampleOption = {
  id: number
  sample_no: string
  sample_name?: string | null
  model?: string | null
}

export type IntegratingSphereInspectionEquipment = {
  id: number
  equipment_id: number | null
  equipment_no: string
  equipment_name: string
  manufacturer?: string | null
  model?: string | null
  serial_no?: string | null
  next_calibration_date?: string | null
}

export type IntegratingSphereInspectionRecord = {
  id: number
  sample_id: number | null
  sample_no: string
  chromaticity_x: string
  chromaticity_y: string
  dominant_wavelength: string
  peak_wavelength: string
  color_temperature: number
  color_rendering_index: string
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
  equipment: IntegratingSphereInspectionEquipment[]
}

/**
 * One device row of the editor. `child_id` is the stored snapshot this entry stands
 * for (null for a freshly scanned device) and `equipment_id` is the live ledger row
 * (null once that row has been deleted). A snapshot with neither a live ledger row
 * nor a way to be retained would be historical evidence the operator cannot save.
 */
export type IntegratingSphereFormEquipment = {
  child_id: number | null
  equipment_id: number | null
  equipment_no: string
  equipment_name: string
  manufacturer?: string | null
  model?: string | null
  serial_no?: string | null
  next_calibration_date?: string | null
}

/**
 * The sample of the editor, with the same retained/new distinction the device rows
 * carry through `child_id`.
 *
 * `retained` is the snapshot already stored on the record: it is kept verbatim and
 * never re-declared, so renaming the sample in the ledger cannot rewrite the number
 * a past measurement was filed under. `selected` is a fresh scan or manual lookup,
 * which is the operator explicitly asking for that replacement.
 */
export type IntegratingSphereFormSample = {
  source: 'retained' | 'selected'
  id: number | null
  sample_no: string
  sample_name?: string | null
  model?: string | null
}

export type IntegratingSphereInspectionForm = {
  sample: IntegratingSphereFormSample | null
  equipment: IntegratingSphereFormEquipment[]
  recorded_at: string
  remark: string
} & Record<IntegratingSphereMeasurementField, string>

export type IntegratingSphereInspectionFilters = {
  search: string
  date_from: string
  date_to: string
}

export const emptyIntegratingSphereInspectionFilters: IntegratingSphereInspectionFilters = {
  search: '',
  date_from: '',
  date_to: '',
}

/**
 * One row of the global used-equipment ledger: an existing equipment snapshot
 * flattened with the date and operator of the record it belongs to. Nothing here is
 * stored separately — the API joins the child snapshot to its parent.
 */
export type IntegratingSphereEquipmentLedgerRow = {
  id: number
  inspection_record_id: number
  equipment_id: number | null
  equipment_no: string
  equipment_name: string
  manufacturer?: string | null
  model?: string | null
  serial_no?: string | null
  next_calibration_date?: string | null
  recorded_at: string | null
  operator_name?: string | null
}

export type IntegratingSphereEquipmentFilters = {
  search: string
  inspection_record_id: string
  equipment_id: string
  date_from: string
  date_to: string
}

export const emptyIntegratingSphereEquipmentFilters: IntegratingSphereEquipmentFilters = {
  search: '',
  inspection_record_id: '',
  equipment_id: '',
  date_from: '',
  date_to: '',
}

export function buildIntegratingSphereEquipmentListParams(
  filters: IntegratingSphereEquipmentFilters,
  page: number,
  perPage: number,
) {
  const entries = Object.entries(filters).filter(([, value]) => value.trim() !== '')

  return { ...Object.fromEntries(entries.map(([key, value]) => [key, value.trim()])), page, per_page: perPage }
}

/** The two page-level views sharing one route and one navigation entry. */
export type IntegratingSphereView = 'records' | 'equipment'

export function emptyIntegratingSphereInspectionForm(recordedAt = ''): IntegratingSphereInspectionForm {
  const measurements = Object.fromEntries(
    integratingSphereMeasurementFields.map((field) => [field.name, '']),
  ) as Record<IntegratingSphereMeasurementField, string>

  return { sample: null, equipment: [], recorded_at: recordedAt, remark: '', ...measurements }
}

/**
 * Canonicalizes a typed measurement using string operations only.
 *
 * The value never passes through `Number`: parsing to a double and formatting back
 * with `toFixed` silently re-rounds anything the binary representation cannot hold,
 * which is exactly the precision the record is supposed to preserve. Input carrying
 * more decimals than the form allows is refused rather than rounded, because a
 * silent round would hide an operator typo.
 */
export function normalizeMeasurementInput(raw: string, scale: number): string | null {
  const value = raw.trim()
  const pattern = scale === 0 ? /^([+-]?)(\d+)$/ : new RegExp(`^([+-]?)(\\d+)(?:\\.(\\d{1,${scale}}))?$`)
  const match = pattern.exec(value)

  if (!match) {
    return null
  }

  const [, sign, integerDigits, fractionDigits = ''] = match
  const integerPart = integerDigits.replace(/^0+(?=\d)/, '')
  const fractionPart = scale === 0 ? '' : fractionDigits.padEnd(scale, '0')
  const negative = sign === '-' && /[1-9]/.test(`${integerPart}${fractionPart}`)

  return `${negative ? '-' : ''}${integerPart}${scale === 0 ? '' : `.${fractionPart}`}`
}

/**
 * Orders two canonical decimals of the same scale exactly. Dropping the point
 * shifts both by the same power of ten, so the remaining digit strings can be
 * zero-padded and compared as integers without involving a float.
 */
export function compareDecimalStrings(a: string, b: string): number {
  const negativeA = a.startsWith('-')
  const negativeB = b.startsWith('-')

  if (negativeA !== negativeB) {
    return negativeA ? -1 : 1
  }

  const digitsA = a.replace('-', '').replace('.', '')
  const digitsB = b.replace('-', '').replace('.', '')
  const width = Math.max(digitsA.length, digitsB.length)
  const paddedA = digitsA.padStart(width, '0')
  const paddedB = digitsB.padStart(width, '0')
  const order = paddedA === paddedB ? 0 : paddedA < paddedB ? -1 : 1

  return negativeA ? -order : order
}

export function measurementValueError(field: IntegratingSphereMeasurement, raw: string): string | null {
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

/**
 * Decimals stay strings all the way to the wire. An integer becomes a number only
 * after the range check above proved it sits inside the configured column limits,
 * which are far below `Number.MAX_SAFE_INTEGER`.
 */
function measurementPayloadValue(field: IntegratingSphereMeasurement, raw: string): string | number {
  const canonical = normalizeMeasurementInput(raw, field.scale) ?? ''

  if (field.scale !== 0) {
    return canonical
  }

  const parsed = Number(canonical)

  if (!Number.isSafeInteger(parsed)) {
    throw new IntegratingSphereFormError([{ path: [field.name], message: '请输入整数' }])
  }

  return parsed
}

type FormIssue = { path: PropertyKey[]; message: string }

export class IntegratingSphereFormError extends Error {
  readonly issues: FormIssue[]

  constructor(issues: FormIssue[]) {
    super('integrating_sphere_form_invalid')
    this.name = 'IntegratingSphereFormError'
    this.issues = issues
  }
}

/** Decimals travel as canonical strings; only the integer fields become numbers. */
export type IntegratingSphereMeasurementPayload = {
  [K in IntegratingSphereMeasurementField]: Extract<IntegratingSphereMeasurement, { name: K }>['scale'] extends 0
    ? number
    : string
}

export type IntegratingSphereInspectionPayload = IntegratingSphereMeasurementPayload & {
  recorded_at: string | null
  remark: string | null
  equipment_ids: number[]
  sample_id?: number
  retained_equipment_ids?: number[]
}

const measurementShape = integratingSphereMeasurementFields.reduce<Record<string, z.ZodType<string, string>>>(
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
) as Record<IntegratingSphereMeasurementField, z.ZodType<string, string>>

export const integratingSphereInspectionSchema = z.object({
  sample: z.object(
    {
      source: z.enum(['retained', 'selected']),
      id: z.number().int().positive().nullable(),
      sample_no: z.string().min(1),
    },
    { error: '请先录入样品编号' },
  ),
  equipment: z
    .array(z.object({ child_id: z.number().int().positive().nullable(), equipment_id: z.number().int().positive().nullable() }))
    .min(1, '请至少录入一台设备'),
  recorded_at: z.string(),
  remark: z.string(),
  ...measurementShape,
})

/**
 * Local `datetime-local` values carry no seconds; the API stores a full timestamp,
 * so the missing seconds are filled in rather than left to the server's parser.
 */
function apiDateTime(value: string) {
  const trimmed = value.trim()

  if (trimmed === '') {
    return null
  }

  const normalized = trimmed.replace('T', ' ')

  return normalized.length === 16 ? `${normalized}:00` : normalized
}

export function buildIntegratingSphereInspectionPayload(
  values: IntegratingSphereInspectionForm,
  mode: 'create' | 'update' = 'create',
): IntegratingSphereInspectionPayload {
  const parsed = integratingSphereInspectionSchema.parse(values)

  // A new record must point at a live sample; an edit may keep the snapshot of one
  // that has since been removed from the ledger.
  if (mode === 'create' && parsed.sample.id === null) {
    throw new IntegratingSphereFormError([{ path: ['sample'], message: '请先录入样品编号' }])
  }

  const measurements = integratingSphereMeasurementFields.reduce<IntegratingSphereMeasurementPayload>(
    (carry, field) => ({ ...carry, [field.name]: measurementPayloadValue(field, values[field.name]) }),
    {} as IntegratingSphereMeasurementPayload,
  )
  const remark = parsed.remark.trim()
  const addedEquipmentIds = values.equipment
    .filter((device) => device.child_id === null && device.equipment_id !== null)
    .map((device) => device.equipment_id as number)
  const common = {
    recorded_at: apiDateTime(parsed.recorded_at),
    ...measurements,
    remark: remark === '' ? null : remark,
  }

  if (mode === 'create') {
    return { sample_id: parsed.sample.id as number, equipment_ids: addedEquipmentIds, ...common }
  }

  return {
    // Omitting `sample_id` tells the API to keep the snapshot it already holds. That
    // is the default for a sample loaded from the record, whether or not its ledger
    // row still exists — only an explicit re-scan asks for a replacement.
    ...(parsed.sample.source === 'selected' && parsed.sample.id !== null ? { sample_id: parsed.sample.id } : {}),
    equipment_ids: addedEquipmentIds,
    retained_equipment_ids: values.equipment
      .filter((device) => device.child_id !== null)
      .map((device) => device.child_id as number),
    ...common,
  }
}

/**
 * Flattens a thrown schema error into `field -> message` so the modal can mark the
 * exact input that has to be corrected instead of showing one opaque banner.
 */
export function integratingSphereFieldErrors(error: unknown): Record<string, string> {
  const issues = (error as { issues?: FormIssue[] } | null)?.issues

  if (!issues) {
    return {}
  }

  const fieldErrors: Record<string, string> = {}

  for (const issue of issues) {
    const field = String(issue.path[0] ?? '')

    if (field !== '' && !(field in fieldErrors)) {
      fieldErrors[field] = issue.message
    }
  }

  return fieldErrors
}

/** Wraps a lookup result as the operator's explicit replacement for the sample. */
export function selectedSample(sample: IntegratingSphereSampleOption): IntegratingSphereFormSample {
  return { source: 'selected', id: sample.id, sample_no: sample.sample_no, sample_name: sample.sample_name, model: sample.model }
}

/** Stable identity for a device row, whether it is a stored snapshot or a new scan. */
export function equipmentEntryKey(device: IntegratingSphereFormEquipment) {
  return device.child_id !== null ? `child:${device.child_id}` : `equipment:${device.equipment_id}`
}

/**
 * Scanning the same label twice must not add a second row for one device, and a
 * device already covered by a retained snapshot must not be re-added either — the
 * API rejects that pairing because it would duplicate the child row.
 */
export function addEquipmentSnapshot(list: IntegratingSphereFormEquipment[], device: IntegratingSphereEquipmentOption) {
  if (list.some((item) => item.equipment_id === device.id)) {
    return list
  }

  return [
    ...list,
    {
      child_id: null,
      equipment_id: device.id,
      equipment_no: device.equipment_no,
      equipment_name: device.equipment_name,
      manufacturer: device.manufacturer,
      model: device.model,
      serial_no: device.serial_no,
      next_calibration_date: device.next_calibration_date,
    },
  ]
}

export function removeEquipmentSnapshot(list: IntegratingSphereFormEquipment[], key: string) {
  return list.filter((device) => equipmentEntryKey(device) !== key)
}

export function buildIntegratingSphereInspectionListParams(
  filters: IntegratingSphereInspectionFilters,
  page: number,
  perPage: number,
) {
  const entries = Object.entries(filters).filter(([, value]) => value !== '')

  return { ...Object.fromEntries(entries), page, per_page: perPage }
}

function formInputDateTime(value: string) {
  return value.replace(' ', 'T').slice(0, 16)
}

/**
 * Rebuilds the form from a stored record. Every child snapshot is carried over,
 * including the ones whose ledger row has been deleted: they have no live id to
 * re-snapshot, but they can still be retained by their child id, so saving an
 * unrelated measurement edit leaves the device history untouched.
 */
export function inspectionFormFromRecord(record: IntegratingSphereInspectionRecord): IntegratingSphereInspectionForm {
  const measurements = Object.fromEntries(
    integratingSphereMeasurementFields.map((field) => [field.name, String(record[field.name] ?? '')]),
  ) as Record<IntegratingSphereMeasurementField, string>

  return {
    sample: { source: 'retained', id: record.sample_id, sample_no: record.sample_no },
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
    recorded_at: formInputDateTime(record.recorded_at),
    remark: record.remark ?? '',
    ...measurements,
  }
}
