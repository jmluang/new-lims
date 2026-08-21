import { describe, expect, it } from 'vitest'
import {
  buildIntegratingSphereCalibrationEquipmentListParams,
  buildIntegratingSphereCalibrationListParams,
  buildIntegratingSphereCalibrationPayload,
  calibrationFormFromRecord,
  emptyIntegratingSphereCalibrationEquipmentFilters,
  emptyIntegratingSphereCalibrationFilters,
  emptyIntegratingSphereCalibrationForm,
  integratingSphereCalibrationFieldErrors,
  IntegratingSphereCalibrationFormError,
  measurementValueError,
  type IntegratingSphereCalibrationRecord,
} from '../integratingSphereCalibrationSchema'
import { validateInspectionMediaLimits, type InspectionMedia } from '../inspectionShared'

const liveDevice = {
  child_id: null,
  equipment_id: 10,
  equipment_no: 'EQ-001',
  equipment_name: '数字高压表',
  manufacturer: '杭州远方',
  model: 'DPS1060',
  serial_no: 'SN123',
  next_calibration_date: '2027-01-01',
}

const mockRecord: IntegratingSphereCalibrationRecord = {
  id: 1,
  standard_equipment_id: 5,
  standard_no: 'STD-100',
  standard_name: '标准灯',
  standard_manufacturer: 'OSRAM',
  standard_model: '400W',
  standard_serial_no: 'SN-STD',
  standard_next_calibration_date: '2027-06-01',
  equipment_system_id: 2,
  system_code: 'SYS-01',
  system_name: '系统1',
  mode_code: 'fast',
  mode_label: '快速',
  sensitivity_code: 'low',
  sensitivity_label: '低',
  color_temperature: 4360,
  color_rendering_index: '88.4',
  luminous_flux: '1674.0',
  voltage: '220.8',
  current: '0.1189',
  power: '14.2400',
  power_factor: '0.5422',
  frequency: 50,
  remark: '快照备注',
  recorded_at: '2026-08-21 12:00:00',
  operator_id: 1,
  operator_name: '张工',
  created_at: '2026-08-21T12:00:00Z',
  updated_at: '2026-08-21T12:00:00Z',
  equipment: [
    {
      id: 101,
      equipment_id: 10,
      equipment_no: 'EQ-001',
      equipment_name: '数字高压表',
      manufacturer: '杭州远方',
      model: 'DPS1060',
      serial_no: 'SN123',
      next_calibration_date: '2027-01-01',
    },
  ],
  photos: [],
  files: [],
}

describe('integratingSphereCalibrationSchema', () => {
  it('initializes an empty form with null mode and sensitivity', () => {
    const form = emptyIntegratingSphereCalibrationForm()

    expect(form.equipment).toEqual([])
    expect(form.system).toBeNull()
    expect(form.standard).toBeNull()
    expect(form.mode).toBeNull()
    expect(form.sensitivity).toBeNull()
  })

  it('populates form state from a record with retained catalog option sources', () => {
    const form = calibrationFormFromRecord(mockRecord)

    expect(form.equipment).toHaveLength(1)
    expect(form.system).toEqual({ source: 'retained', id: 2, code: 'SYS-01', name: '系统1' })
    expect(form.standard).toEqual({
      source: 'retained',
      equipment_id: 5,
      standard_no: 'STD-100',
      standard_name: '标准灯',
      manufacturer: 'OSRAM',
      model: '400W',
      serial_no: 'SN-STD',
      next_calibration_date: '2027-06-01',
    })
    expect(form.mode).toEqual({ source: 'retained', code: 'fast', label: '快速' })
    expect(form.sensitivity).toEqual({ source: 'retained', code: 'low', label: '低' })
  })

  it('omits mode_code and sensitivity_code on update when options are retained so backend does not reject removed options', () => {
    const form = calibrationFormFromRecord(mockRecord)
    const payload = buildIntegratingSphereCalibrationPayload(form, 'update')

    expect(payload.get('_method')).toBe('PUT')
    expect(payload.has('mode_code')).toBe(false)
    expect(payload.has('sensitivity_code')).toBe(false)
    expect(payload.get('color_temperature')).toBe('4360')
  })

  it('appends mode_code and sensitivity_code when options are explicitly selected', () => {
    const form = calibrationFormFromRecord(mockRecord)
    form.mode = { source: 'selected', code: 'precise', label: '精准' }
    form.sensitivity = { source: 'selected', code: 'high', label: '高' }

    const payload = buildIntegratingSphereCalibrationPayload(form, 'update')

    expect(payload.get('mode_code')).toBe('precise')
    expect(payload.get('sensitivity_code')).toBe('high')
  })

  it('enforces exact decimal string bounds without converting through Number', () => {
    const fluxField = { name: 'luminous_flux', label: '光通量', unit: 'lm', scale: 1, min: '0', max: '99999999999.9' } as const
    expect(measurementValueError(fluxField, '99999999999.9')).toBeNull()
    expect(measurementValueError(fluxField, '99999999999.91')).toContain('最多保留 1 位小数')
    expect(measurementValueError(fluxField, '100000000000.0')).toContain('范围必须在 0 至 99999999999.9 之间')

    const pfField = { name: 'power_factor', label: '功率因数', unit: '', scale: 4, min: '0', max: '1' } as const
    expect(measurementValueError(pfField, '0.5422')).toBeNull()
    expect(measurementValueError(pfField, '1.0000')).toBeNull()
    expect(measurementValueError(pfField, '1.0001')).toContain('范围必须在 0 至 1 之间')
    expect(measurementValueError(pfField, '-0.0001')).toContain('范围必须在 0 至 1 之间')
  })

  it('enforces shared media limit validation for count and size limits, throwing domain form issue for inline rendering', () => {
    const retainedMedia: InspectionMedia[] = Array.from({ length: 9 }, (_, i) => ({
      id: i + 1,
      collection: 'photos',
      file_name: `p${i}.jpg`,
      mime_type: 'image/jpeg',
      size: 1000,
    }))

    const newPhotos = [new File(['a'], 'new1.jpg'), new File(['b'], 'new2.jpg')]

    const errors = validateInspectionMediaLimits({ retained_media: retainedMedia, new_photos: newPhotos, new_files: [] })
    expect(errors.photos).toContain('最多保留 10 个附件')

    const form = {
      equipment: [liveDevice],
      system: { source: 'selected' as const, id: 2, code: 'SYS-01', name: '系统1' },
      standard: {
        source: 'selected' as const,
        equipment_id: 5,
        standard_no: 'STD-100',
        standard_name: '标准灯',
        manufacturer: 'OSRAM',
        model: '400W',
        serial_no: 'SN-STD',
        next_calibration_date: '2027-06-01',
      },
      mode: { source: 'selected' as const, code: 'precise', label: '精准' },
      sensitivity: { source: 'selected' as const, code: 'high', label: '高' },
      color_temperature: '4360',
      color_rendering_index: '88.4',
      luminous_flux: '1674',
      voltage: '220.8',
      current: '0.1189',
      power: '14.24',
      power_factor: '0.5422',
      frequency: '50',
      remark: '',
      retained_media: retainedMedia,
      new_photos: newPhotos,
      new_files: [],
    }

    try {
      buildIntegratingSphereCalibrationPayload(form, 'create')
      expect.unreachable('Should have thrown IntegratingSphereCalibrationFormError')
    } catch (err) {
      expect(err).toBeInstanceOf(IntegratingSphereCalibrationFormError)
      const fieldErrs = integratingSphereCalibrationFieldErrors(err)
      expect(fieldErrs.photos).toContain('最多保留 10 个附件')
    }
  })

  it('builds list params filtering out blank values', () => {
    const filters = { ...emptyIntegratingSphereCalibrationFilters, search: ' STD-100 ', mode_code: 'precise' }
    const params = buildIntegratingSphereCalibrationListParams(filters, 2, 20)

    expect(params).toEqual({
      search: 'STD-100',
      mode_code: 'precise',
      page: 2,
      per_page: 20,
    })
  })

  it('builds equipment list params correctly', () => {
    const filters = { ...emptyIntegratingSphereCalibrationEquipmentFilters, search: ' EQ-001 ' }
    const params = buildIntegratingSphereCalibrationEquipmentListParams(filters, 1, 15)

    expect(params).toEqual({
      search: 'EQ-001',
      page: 1,
      per_page: 15,
    })
  })

  it('keys the ledger parent filter on calibration_record_id, never the inspection key', () => {
    expect(emptyIntegratingSphereCalibrationEquipmentFilters).toEqual({
      search: '',
      calibration_record_id: '',
      equipment_id: '',
      date_from: '',
      date_to: '',
    })
    expect(emptyIntegratingSphereCalibrationEquipmentFilters).not.toHaveProperty('inspection_record_id')

    const params = buildIntegratingSphereCalibrationEquipmentListParams(
      { ...emptyIntegratingSphereCalibrationEquipmentFilters, calibration_record_id: ' 12 ' },
      1,
      15,
    )

    expect(params).toEqual({ calibration_record_id: '12', page: 1, per_page: 15 })
    expect(params).not.toHaveProperty('inspection_record_id')
  })

  it('drops a blank parent id instead of sending an empty filter', () => {
    expect(
      buildIntegratingSphereCalibrationEquipmentListParams(
        { ...emptyIntegratingSphereCalibrationEquipmentFilters, calibration_record_id: '  ' },
        3,
        50,
      ),
    ).toEqual({ page: 3, per_page: 50 })
  })
})
