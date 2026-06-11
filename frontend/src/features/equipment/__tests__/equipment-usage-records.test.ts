import { describe, expect, it } from 'vitest'
import { buildEquipmentUsageStartPayload, equipmentUsageStatus } from '../equipmentUsageSchema'

describe('equipment usage records', () => {
  it('normalizes selected equipment and sample ids into a start payload', () => {
    expect(
      buildEquipmentUsageStartPayload({
        equipment_ids: [1, 2],
        sample_ids: [8],
        start_time: '2026-06-12T09:30',
        remark: '',
      }),
    ).toEqual({
      equipment_ids: [1, 2],
      sample_ids: [8],
      start_time: '2026-06-12T09:30',
      remark: null,
    })
  })

  it('derives usage status from the end time', () => {
    expect(equipmentUsageStatus(null)).toBe('using')
    expect(equipmentUsageStatus('2026-06-12 11:00:00')).toBe('finished')
  })
})
