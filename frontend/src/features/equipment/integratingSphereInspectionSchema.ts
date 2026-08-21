import { z } from 'zod'
import {
  addEquipmentSnapshot,
  apiDateTime,
  buildInspectionEquipmentListParams,
  compareDecimalStrings,
  emptyInspectionEquipmentFilters,
  equipmentEntryKey,
  formEquipmentFromSnapshots,
  formInputDateTime,
  inspectionFieldErrors,
  normalizeMeasurementInput,
  removeEquipmentSnapshot,
  selectedSample,
  selectedSystem,
  type InspectionEquipmentFilters,
  type InspectionEquipmentLedgerRow,
  type InspectionEquipmentOption,
  type InspectionEquipmentSnapshot,
  type InspectionFormEquipment,
  type InspectionFormIssue,
  type InspectionFormSample,
  type InspectionFormSystem,
  type InspectionSampleOption,
  type InspectionSystemOption,
} from './inspectionShared'

export {
  addEquipmentSnapshot,
  compareDecimalStrings,
  equipmentEntryKey,
  normalizeMeasurementInput,
  removeEquipmentSnapshot,
  selectedSample,
  selectedSystem,
}

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

export type IntegratingSphereEquipmentOption = InspectionEquipmentOption
export type IntegratingSphereSystemOption = InspectionSystemOption
export type IntegratingSphereSampleOption = InspectionSampleOption
export type IntegratingSphereInspectionEquipment = InspectionEquipmentSnapshot

export type IntegratingSphereInspectionRecord = {
  id: number
  sample_id: number | null
  sample_no: string
  equipment_system_id: number | null
  system_code: string | null
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

export type IntegratingSphereFormEquipment = InspectionFormEquipment
export type IntegratingSphereFormSample = InspectionFormSample
export type IntegratingSphereFormSystem = InspectionFormSystem

export type IntegratingSphereInspectionForm = {
  sample: IntegratingSphereFormSample | null
  system: IntegratingSphereFormSystem | null
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

export type IntegratingSphereEquipmentLedgerRow = InspectionEquipmentLedgerRow
export type IntegratingSphereEquipmentFilters = InspectionEquipmentFilters

export const emptyIntegratingSphereEquipmentFilters = emptyInspectionEquipmentFilters
export const buildIntegratingSphereEquipmentListParams = buildInspectionEquipmentListParams

/** The two page-level views sharing one route and one navigation entry. */
export type IntegratingSphereView = 'records' | 'equipment'

export function emptyIntegratingSphereInspectionForm(recordedAt = ''): IntegratingSphereInspectionForm {
  const measurements = Object.fromEntries(
    integratingSphereMeasurementFields.map((field) => [field.name, '']),
  ) as Record<IntegratingSphereMeasurementField, string>

  return { sample: null, system: null, equipment: [], recorded_at: recordedAt, remark: '', ...measurements }
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

type FormIssue = InspectionFormIssue

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
  equipment_system_id?: number
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
  // A record written before the system code existed carries no system at all, so the
  // field is nullable here and only `create` insists on a live one below.
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
  recorded_at: z.string(),
  remark: z.string(),
  ...measurementShape,
})


export function buildIntegratingSphereInspectionPayload(
  values: IntegratingSphereInspectionForm,
  mode: 'create' | 'update' = 'create',
): IntegratingSphereInspectionPayload {
  const result = integratingSphereInspectionSchema.safeParse(values)
  const issues: FormIssue[] = result.success ? [] : [...result.error.issues]

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

  if (!result.success || issues.length > 0) {
    throw new IntegratingSphereFormError(issues)
  }

  const parsed = result.data

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
    return {
      sample_id: parsed.sample.id as number,
      equipment_system_id: (parsed.system as IntegratingSphereFormSystem).id as number,
      equipment_ids: addedEquipmentIds,
      ...common,
    }
  }

  return {
    // Omitting `sample_id` tells the API to keep the snapshot it already holds. That
    // is the default for a sample loaded from the record, whether or not its ledger
    // row still exists — only an explicit re-scan asks for a replacement.
    ...(parsed.sample.source === 'selected' && parsed.sample.id !== null ? { sample_id: parsed.sample.id } : {}),
    // The same rule for the system: retained means the stored code stays, and only an
    // explicit scan or manual lookup asks the server to re-snapshot.
    ...(parsed.system !== null && parsed.system.source === 'selected' && parsed.system.id !== null
      ? { equipment_system_id: parsed.system.id }
      : {}),
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
  return inspectionFieldErrors(error)
}


export function buildIntegratingSphereInspectionListParams(
  filters: IntegratingSphereInspectionFilters,
  page: number,
  perPage: number,
) {
  const entries = Object.entries(filters).filter(([, value]) => value !== '')

  return { ...Object.fromEntries(entries), page, per_page: perPage }
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
    system: record.system_code === null ? null : { source: 'retained', id: record.equipment_system_id, code: record.system_code },
    equipment: formEquipmentFromSnapshots(record.equipment),
    recorded_at: formInputDateTime(record.recorded_at),
    remark: record.remark ?? '',
    ...measurements,
  }
}
