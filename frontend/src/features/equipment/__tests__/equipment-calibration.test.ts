import { describe, expect, it } from 'vitest'
import { buildEquipmentCalibrationPayload, EquipmentCalibrationValidationError } from '../equipmentCalibrationSchema'

describe('buildEquipmentCalibrationPayload', () => {
  it('builds an equipment calibration payload with device and standard rows', () => {
    expect(
      buildEquipmentCalibrationPayload({
        calibration_name: '积分球定标',
        calibration_time: '2026-06-12T09:00',
        result: 'qualified',
        devices: [{ equipment_id: 1, remark: 'device' }],
        standards: [{ equipment_id: 2, remark: 'standard' }],
        remark: '',
      }),
    ).toMatchObject({
      calibration_name: '积分球定标',
      result: 'qualified',
      devices: [{ equipment_id: 1, remark: 'device' }],
      standards: [{ equipment_id: 2, remark: 'standard' }],
    })
  })

  it('defaults the result and nulls an empty remark', () => {
    const payload = buildEquipmentCalibrationPayload({
      calibration_name: '色温定标',
      calibration_time: '2026-06-12T09:00',
    })

    expect(payload.result).toBe('qualified')
    expect(payload.remark).toBeNull()
    expect(payload.devices).toEqual([])
  })

  it('rejects a missing calibration name', () => {
    expect(() => buildEquipmentCalibrationPayload({ calibration_name: '', calibration_time: '2026-06-12T09:00' })).toThrow(EquipmentCalibrationValidationError)
  })
})
