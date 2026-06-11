import type { Equipment, FieldPermissionMeta } from './EquipmentListPage'

export type EquipmentColumn = {
  key: keyof Equipment
  label: string
  sensitive?: boolean
}

export const equipmentColumns: EquipmentColumn[] = [
  { key: 'equipment_no', label: 'No.' },
  { key: 'name', label: 'Name' },
  { key: 'manufacturer', label: 'Manufacturer' },
  { key: 'model', label: 'Model' },
  { key: 'measurement_range', label: 'Measurement range' },
  { key: 'accuracy', label: 'Accuracy' },
  { key: 'serial_no', label: 'Serial no', sensitive: true },
  { key: 'next_calibration_date', label: 'Next calibration' },
  { key: 'status', label: 'Status' },
]

export function visibleEquipmentColumns(fields?: FieldPermissionMeta) {
  return equipmentColumns.filter((column) => !column.sensitive || !fields?.[column.key as string]?.hidden)
}
