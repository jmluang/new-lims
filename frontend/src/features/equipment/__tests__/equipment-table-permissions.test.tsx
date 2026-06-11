import { describe, expect, it } from 'vitest'
import { visibleEquipmentColumns } from '../equipmentColumns'

describe('equipment table permissions', () => {
  it('does not expose legacy placement in the equipment ledger columns', () => {
    const defaultColumns = visibleEquipmentColumns().map((column) => column.key)

    expect(defaultColumns).toContain('equipment_no')
    expect(defaultColumns).toContain('name')
    expect(defaultColumns).toContain('measurement_range')
    expect(defaultColumns).toContain('accuracy')
    expect(defaultColumns).not.toContain('legacy_placement')
  })

  it('removes hidden sensitive equipment columns', () => {
    const columns = visibleEquipmentColumns({
      serial_no: { hidden: true, read: false },
    }).map((column) => column.key)

    expect(columns).toContain('equipment_no')
    expect(columns).toContain('name')
    expect(columns).not.toContain('serial_no')
  })
})
