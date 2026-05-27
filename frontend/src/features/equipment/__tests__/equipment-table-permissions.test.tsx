import { describe, expect, it } from 'vitest'
import { visibleEquipmentColumns } from '../equipmentColumns'

describe('equipment table permissions', () => {
  it('removes hidden sensitive equipment columns', () => {
    const columns = visibleEquipmentColumns({
      serial_no: { hidden: true, read: false },
      legacy_placement: { hidden: true, read: false },
    }).map((column) => column.key)

    expect(columns).toContain('equipment_no')
    expect(columns).toContain('name')
    expect(columns).not.toContain('serial_no')
    expect(columns).not.toContain('legacy_placement')
  })
})
