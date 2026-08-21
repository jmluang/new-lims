/**
 * The parts of an inspection form that every inspection workflow shares: the three
 * ledger subjects an operator scans, the used-equipment snapshots a record carries,
 * and the exact-decimal helpers the measurement grids are built on.
 *
 * Measurement definitions, list columns, validation schemas and payload builders
 * deliberately stay with each domain — only the pieces that are identical between
 * workflows, and whose subtle rules are worth writing once, live here.
 */

export type InspectionEquipmentOption = {
  id: number
  equipment_no: string
  equipment_name: string
  manufacturer?: string | null
  model?: string | null
  serial_no?: string | null
  next_calibration_date?: string | null
}

export type InspectionSystemOption = {
  id: number
  code: string
  name?: string | null
  status?: string | null
}

export type InspectionSampleOption = {
  id: number
  sample_no: string
  sample_name?: string | null
  model?: string | null
}

/** A stored used-equipment snapshot as the API serializes it. */
export type InspectionEquipmentSnapshot = {
  id: number
  equipment_id: number | null
  equipment_no: string
  equipment_name: string
  manufacturer?: string | null
  model?: string | null
  serial_no?: string | null
  next_calibration_date?: string | null
}

/**
 * One device row of an editor. `child_id` is the stored snapshot this entry stands
 * for (null for a freshly scanned device) and `equipment_id` is the live ledger row
 * (null once that row has been deleted). A snapshot with neither a live ledger row
 * nor a way to be retained would be historical evidence the operator cannot save.
 */
export type InspectionFormEquipment = {
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
 * The sample of an editor, with the same retained/new distinction the device rows
 * carry through `child_id`.
 *
 * `retained` is the snapshot already stored on the record: it is kept verbatim and
 * never re-declared, so renaming the sample in the ledger cannot rewrite the number
 * a past measurement was filed under. `selected` is a fresh scan or manual lookup,
 * which is the operator explicitly asking for that replacement.
 */
export type InspectionFormSample = {
  source: 'retained' | 'selected'
  id: number | null
  sample_no: string
  sample_name?: string | null
  model?: string | null
}

/**
 * The equipment system of an editor, carrying the same retained/selected split as
 * the sample.
 *
 * The code is an independent operator input: it is scanned or typed on its own and
 * is never derived from the selected devices. `retained` is the snapshot already
 * stored on the record, kept verbatim so renaming, disabling or deleting the system
 * cannot rewrite the code a past measurement was filed under; `selected` is a fresh
 * lookup, which is the operator explicitly asking for that replacement.
 */
export type InspectionFormSystem = {
  source: 'retained' | 'selected'
  id: number | null
  code: string
  name?: string | null
}

/**
 * One row of a global used-equipment ledger: an existing equipment snapshot
 * flattened with the date and operator of the record it belongs to. Nothing here is
 * stored separately — the API joins the child snapshot to its parent.
 */
export type InspectionEquipmentLedgerRow = InspectionEquipmentSnapshot & {
  inspection_record_id: number
  recorded_at: string | null
  operator_name?: string | null
}

export type InspectionEquipmentFilters = {
  search: string
  inspection_record_id: string
  equipment_id: string
  date_from: string
  date_to: string
}

export const emptyInspectionEquipmentFilters: InspectionEquipmentFilters = {
  search: '',
  inspection_record_id: '',
  equipment_id: '',
  date_from: '',
  date_to: '',
}

export function buildInspectionEquipmentListParams(
  filters: InspectionEquipmentFilters,
  page: number,
  perPage: number,
) {
  const entries = Object.entries(filters).filter(([, value]) => value.trim() !== '')

  return { ...Object.fromEntries(entries.map(([key, value]) => [key, value.trim()])), page, per_page: perPage }
}

export type InspectionFormIssue = { path: PropertyKey[]; message: string }

/** Wraps a lookup result as the operator's explicit replacement for the sample. */
export function selectedSample(sample: InspectionSampleOption): InspectionFormSample {
  return { source: 'selected', id: sample.id, sample_no: sample.sample_no, sample_name: sample.sample_name, model: sample.model }
}

/** Wraps a lookup result as the operator's explicit replacement for the system. */
export function selectedSystem(system: InspectionSystemOption): InspectionFormSystem {
  return { source: 'selected', id: system.id, code: system.code, name: system.name }
}

/** Stable identity for a device row, whether it is a stored snapshot or a new scan. */
export function equipmentEntryKey(device: InspectionFormEquipment) {
  return device.child_id !== null ? `child:${device.child_id}` : `equipment:${device.equipment_id}`
}

/**
 * Scanning the same label twice must not add a second row for one device, and a
 * device already covered by a retained snapshot must not be re-added either — the
 * API rejects that pairing because it would duplicate the child row.
 */
export function addEquipmentSnapshot(list: InspectionFormEquipment[], device: InspectionEquipmentOption): InspectionFormEquipment[] {
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

export function removeEquipmentSnapshot(list: InspectionFormEquipment[], key: string): InspectionFormEquipment[] {
  return list.filter((device) => equipmentEntryKey(device) !== key)
}

/** Rebuilds the editor rows from the snapshots a stored record carries. */
export function formEquipmentFromSnapshots(snapshots: InspectionEquipmentSnapshot[]): InspectionFormEquipment[] {
  return snapshots.map((device) => ({
    child_id: device.id,
    equipment_id: device.equipment_id,
    equipment_no: device.equipment_no,
    equipment_name: device.equipment_name,
    manufacturer: device.manufacturer,
    model: device.model,
    serial_no: device.serial_no,
    next_calibration_date: device.next_calibration_date,
  }))
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

/**
 * Local `datetime-local` values carry no seconds; the API stores a full timestamp,
 * so the missing seconds are filled in rather than left to the server's parser.
 */
export function apiDateTime(value: string) {
  const trimmed = value.trim()

  if (trimmed === '') {
    return null
  }

  const normalized = trimmed.replace('T', ' ')

  return normalized.length === 16 ? `${normalized}:00` : normalized
}

export function formInputDateTime(value: string) {
  return value.replace(' ', 'T').slice(0, 16)
}

/**
 * Flattens a thrown schema error into `field -> message` so a modal can mark the
 * exact input that has to be corrected instead of showing one opaque banner.
 */
export function inspectionFieldErrors(error: unknown): Record<string, string> {
  const issues = (error as { issues?: InspectionFormIssue[] } | null)?.issues

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
