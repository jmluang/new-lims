import { describe, expect, it } from 'vitest'
import { validateInspectionMediaLimits, type InspectionMedia } from '../inspectionShared'
import {
  buildPhotometricCurveCalibrationEquipmentListParams,
  buildPhotometricCurveCalibrationListParams,
  buildPhotometricCurveCalibrationPayload,
  calibrationFormFromRecord,
  emptyPhotometricCurveCalibrationEquipmentFilters,
  emptyPhotometricCurveCalibrationFilters,
  emptyPhotometricCurveCalibrationForm,
  measurementValueError,
  photometricCurveCalibrationFieldErrors,
  PhotometricCurveCalibrationFormError,
  photometricCurveCalibrationMeasurementFields,
  photometricCurveProbes,
  probeLabel,
  type PhotometricCurveCalibrationForm,
  type PhotometricCurveCalibrationRecord,
} from '../photometricCurveCalibrationSchema'
import { photometricCurveProbes as inspectionProbes } from '../photometricCurveInspectionSchema'

const mockRecord: PhotometricCurveCalibrationRecord = {
  id: 7,
  standard_equipment_id: 5,
  standard_no: 'XPD-L-030',
  standard_name: '标准灯',
  standard_manufacturer: 'OSRAM',
  standard_model: '400W',
  standard_serial_no: 'SN-STD',
  standard_next_calibration_date: '2027-06-01',
  equipment_system_id: 2,
  system_code: 'sys-01',
  system_name: '系统1',
  probe: 'far_field',
  test_distance: '26.2314',
  calibration_coefficient: '1.0024',
  peak_luminous_intensity: '221.0',
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
  created_at: '2026-08-21 12:00:00',
  updated_at: '2026-08-21 12:00:00',
  equipment: [
    {
      id: 101,
      equipment_id: 10,
      equipment_no: 'XPD-S-001',
      equipment_name: '智能电源',
      manufacturer: '杭州远方',
      model: 'DPS1060',
      serial_no: 'SN123',
      next_calibration_date: '2027-01-01',
    },
  ],
  photos: [],
  files: [],
}

function validCreateForm(): PhotometricCurveCalibrationForm {
  return {
    ...emptyPhotometricCurveCalibrationForm(),
    equipment: [
      {
        child_id: null,
        equipment_id: 10,
        equipment_no: 'XPD-S-001',
        equipment_name: '智能电源',
        manufacturer: '杭州远方',
        model: 'DPS1060',
        serial_no: 'SN123',
        next_calibration_date: '2027-01-01',
      },
      {
        child_id: null,
        equipment_id: 11,
        equipment_no: 'XPD-S-002',
        equipment_name: '光度计',
        manufacturer: '杭州远方',
        model: 'PM100',
        serial_no: 'SN456',
        next_calibration_date: '2027-02-01',
      },
    ],
    system: { source: 'selected', id: 2, code: 'sys-01', name: '系统1' },
    standard: {
      source: 'selected',
      equipment_id: 5,
      standard_no: 'XPD-L-030',
      standard_name: '标准灯',
      manufacturer: 'OSRAM',
      model: '400W',
      serial_no: 'SN-STD',
      next_calibration_date: '2027-06-01',
    },
    probe: 'far_field',
    test_distance: '26.2314',
    calibration_coefficient: '1.0024',
    peak_luminous_intensity: '221.0',
    luminous_flux: '1674.0',
    voltage: '220.8',
    current: '0.1189',
    power: '14.24',
    power_factor: '0.5422',
    frequency: '50',
    remark: ' 定标备注 ',
  }
}

describe('photometricCurveCalibrationSchema measurement contract', () => {
  it('declares every workbook measurement with its exact scale, unit and bounds', () => {
    expect(photometricCurveCalibrationMeasurementFields.map((field) => [field.name, field.scale, field.unit])).toEqual([
      ['test_distance', 4, 'm'],
      ['calibration_coefficient', 4, ''],
      ['peak_luminous_intensity', 1, 'cd'],
      ['luminous_flux', 1, 'lm'],
      ['voltage', 1, 'V'],
      ['current', 4, 'A'],
      ['power', 4, 'W'],
      ['power_factor', 4, ''],
      ['frequency', 0, 'Hz'],
    ])

    const powerFactor = photometricCurveCalibrationMeasurementFields.find((field) => field.name === 'power_factor')
    expect([powerFactor?.min, powerFactor?.max]).toEqual(['0', '1'])
  })

  it('enforces exact decimal string bounds without converting through Number', () => {
    const distance = photometricCurveCalibrationMeasurementFields[0]
    expect(measurementValueError(distance, '26.2314')).toBeNull()
    expect(measurementValueError(distance, '26.23145')).toContain('最多保留 4 位小数')
    expect(measurementValueError(distance, '-0.0001')).toContain('范围必须在 0 至 99999999.9999 之间')
    expect(measurementValueError(distance, '')).toContain('不能为空')

    const coefficient = photometricCurveCalibrationMeasurementFields[1]
    expect(measurementValueError(coefficient, '1.0024')).toBeNull()
    expect(measurementValueError(coefficient, '99999999.9999')).toBeNull()
    expect(measurementValueError(coefficient, '100000000.0000')).toContain('范围必须在 0 至 99999999.9999 之间')

    const peak = photometricCurveCalibrationMeasurementFields[2]
    expect(measurementValueError(peak, '221.0')).toBeNull()
    expect(measurementValueError(peak, '221.05')).toContain('最多保留 1 位小数')

    const powerFactor = photometricCurveCalibrationMeasurementFields[7]
    expect(measurementValueError(powerFactor, '1.0000')).toBeNull()
    expect(measurementValueError(powerFactor, '1.0001')).toContain('范围必须在 0 至 1 之间')
    expect(measurementValueError(powerFactor, '-0.0001')).toContain('范围必须在 0 至 1 之间')

    const frequency = photometricCurveCalibrationMeasurementFields[8]
    expect(measurementValueError(frequency, '50')).toBeNull()
    expect(measurementValueError(frequency, '50.5')).toContain('必须为整数')
    expect(measurementValueError(frequency, '1000001')).toContain('范围必须在 0 至 1000000 之间')
  })
})

describe('photometricCurveCalibrationSchema probe contract', () => {
  it('shares one probe mapping with the photometric-curve inspection workflow', () => {
    expect(photometricCurveProbes).toBe(inspectionProbes)
    expect(photometricCurveProbes.map((probe) => [probe.value, probe.label])).toEqual([
      ['near_field', '近场'],
      ['far_field', '远场'],
    ])
  })

  it('renders the Chinese label while the stable code is what gets serialized', () => {
    expect(probeLabel('near_field')).toBe('近场')
    expect(probeLabel('far_field')).toBe('远场')
    expect(probeLabel('unknown')).toBe('-')

    const form = { ...validCreateForm(), probe: 'near_field' as const }
    expect(buildPhotometricCurveCalibrationPayload(form, 'create').get('probe')).toBe('near_field')
  })

  it('rejects a probe outside the shared mapping', () => {
    const form = { ...validCreateForm(), probe: 'side_field' as never }

    expect(() => buildPhotometricCurveCalibrationPayload(form, 'create')).toThrowError()
  })
})

describe('buildPhotometricCurveCalibrationPayload', () => {
  it('sends every measurement canonically, plus probe, subjects and remark on create', () => {
    const payload = buildPhotometricCurveCalibrationPayload(validCreateForm(), 'create')

    expect(payload.has('_method')).toBe(false)
    expect(payload.get('equipment_system_id')).toBe('2')
    expect(payload.get('standard_equipment_id')).toBe('5')
    expect(payload.getAll('equipment_ids[]')).toEqual(['10', '11'])
    expect(payload.has('retained_equipment_ids')).toBe(false)
    expect(payload.get('probe')).toBe('far_field')
    expect(payload.get('test_distance')).toBe('26.2314')
    expect(payload.get('calibration_coefficient')).toBe('1.0024')
    expect(payload.get('peak_luminous_intensity')).toBe('221.0')
    expect(payload.get('luminous_flux')).toBe('1674.0')
    expect(payload.get('voltage')).toBe('220.8')
    expect(payload.get('current')).toBe('0.1189')
    // A short entry is padded to the promised scale rather than sent as typed.
    expect(payload.get('power')).toBe('14.2400')
    expect(payload.get('power_factor')).toBe('0.5422')
    expect(payload.get('frequency')).toBe('50')
    expect(payload.get('remark')).toBe('定标备注')
  })

  it('never sends the server-owned audit fields', () => {
    const payload = buildPhotometricCurveCalibrationPayload(validCreateForm(), 'create')

    for (const field of ['recorded_at', 'operator_id', 'operator_name']) {
      expect(payload.has(field)).toBe(false)
    }
  })

  it('omits retained subjects on update so stored snapshots survive an edit', () => {
    const form = calibrationFormFromRecord(mockRecord)
    const payload = buildPhotometricCurveCalibrationPayload(form, 'update')

    expect(payload.get('_method')).toBe('PUT')
    expect(payload.has('equipment_system_id')).toBe(false)
    expect(payload.has('standard_equipment_id')).toBe(false)
    expect(payload.getAll('retained_equipment_ids[]')).toEqual(['101'])
    expect(payload.getAll('equipment_ids[]')).toEqual([])
    expect(payload.get('calibration_coefficient')).toBe('1.0024')
  })

  it('sends a selected standard and system on update, and only those', () => {
    const form = calibrationFormFromRecord(mockRecord)
    form.standard = {
      source: 'selected',
      equipment_id: 9,
      standard_no: 'XPD-L-031',
      standard_name: '新标准灯',
      manufacturer: null,
      model: null,
      serial_no: null,
      next_calibration_date: null,
    }

    const payload = buildPhotometricCurveCalibrationPayload(form, 'update')

    expect(payload.get('standard_equipment_id')).toBe('9')
    expect(payload.has('equipment_system_id')).toBe(false)
  })

  it('keeps an orphaned standard snapshot out of the payload so the record retains it', () => {
    const orphaned = { ...mockRecord, standard_equipment_id: null }
    const payload = buildPhotometricCurveCalibrationPayload(calibrationFormFromRecord(orphaned), 'update')

    expect(payload.has('standard_equipment_id')).toBe(false)
  })

  it('spells an emptied child or media list so the API can read it from a multipart body', () => {
    const photo: InspectionMedia = { id: 21, collection: 'photos', file_name: 'a.jpg', mime_type: 'image/jpeg', size: 100 }
    const form = calibrationFormFromRecord({ ...mockRecord, photos: [photo] })

    // Every stored device was dropped and replaced by a fresh scan, and the stored
    // photo was removed: both lists are now empty and must still reach the API.
    form.equipment = [
      {
        child_id: null,
        equipment_id: 11,
        equipment_no: 'XPD-S-002',
        equipment_name: '光度计',
        manufacturer: null,
        model: null,
        serial_no: null,
        next_calibration_date: null,
      },
    ]
    form.retained_media = []

    const payload = buildPhotometricCurveCalibrationPayload(form, 'update')

    expect(payload.getAll('equipment_ids[]')).toEqual(['11'])
    expect(payload.get('retained_equipment_ids')).toBe('')
    expect(payload.get('retained_media_ids')).toBe('')
  })

  it('carries retained media ids and newly picked attachments', () => {
    const photo: InspectionMedia = { id: 21, collection: 'photos', file_name: 'a.jpg', mime_type: 'image/jpeg', size: 100 }
    const doc: InspectionMedia = { id: 22, collection: 'files', file_name: 'b.pdf', mime_type: 'application/pdf', size: 200 }
    const form = {
      ...calibrationFormFromRecord({ ...mockRecord, photos: [photo], files: [doc] }),
      new_photos: [new File(['a'], 'new.jpg', { type: 'image/jpeg' })],
      new_files: [new File(['b'], 'new.pdf', { type: 'application/pdf' })],
    }

    const payload = buildPhotometricCurveCalibrationPayload(form, 'update')

    expect(payload.getAll('retained_media_ids[]')).toEqual(['21', '22'])
    expect(payload.getAll('photos[]')).toHaveLength(1)
    expect(payload.getAll('files[]')).toHaveLength(1)
  })

  it('reports missing subjects as field errors on the editor inputs', () => {
    const form = emptyPhotometricCurveCalibrationForm()

    try {
      buildPhotometricCurveCalibrationPayload(form, 'create')
      expect.unreachable('an empty form must not build a payload')
    } catch (error) {
      const errors = photometricCurveCalibrationFieldErrors(error)

      expect(errors.equipment).toBe('请至少录入一台设备')
      expect(errors.system).toBe('请录入系统编码')
      expect(errors.standard).toBe('请录入标准件编号')
      expect(errors.calibration_coefficient).toContain('定标系数不能为空')
    }
  })

  it('reports media limit breaches as inline field issues before anything is uploaded', () => {
    const retained: InspectionMedia[] = Array.from({ length: 9 }, (_, index) => ({
      id: index + 1,
      collection: 'photos',
      file_name: `p${index}.jpg`,
      mime_type: 'image/jpeg',
      size: 1000,
    }))
    const newPhotos = [new File(['a'], 'new1.jpg'), new File(['b'], 'new2.jpg')]

    expect(validateInspectionMediaLimits({ retained_media: retained, new_photos: newPhotos, new_files: [] }).photos).toContain(
      '最多保留 10 个附件',
    )

    const form = { ...validCreateForm(), retained_media: retained, new_photos: newPhotos }

    try {
      buildPhotometricCurveCalibrationPayload(form, 'create')
      expect.unreachable('the media limit must stop the payload')
    } catch (error) {
      expect(error).toBeInstanceOf(PhotometricCurveCalibrationFormError)
      expect(photometricCurveCalibrationFieldErrors(error).photos).toContain('最多保留 10 个附件')
    }
  })

  it('maps API validation names back onto the scanned subjects', () => {
    const errors = photometricCurveCalibrationFieldErrors({
      issues: [
        { path: ['equipment_ids'], message: '设备不存在' },
        { path: ['equipment_system_id'], message: '系统不可用' },
        { path: ['standard_equipment_id'], message: '标准件不存在' },
      ],
    })

    expect(errors.equipment).toBe('设备不存在')
    expect(errors.system).toBe('系统不可用')
    expect(errors.standard).toBe('标准件不存在')
  })
})

describe('photometricCurveCalibration form and list params', () => {
  it('starts empty with the far-field probe and no subjects', () => {
    const form = emptyPhotometricCurveCalibrationForm()

    expect(form.equipment).toEqual([])
    expect(form.system).toBeNull()
    expect(form.standard).toBeNull()
    expect(form.probe).toBe('far_field')
    expect(form.calibration_coefficient).toBe('')
    expect(form.retained_media).toEqual([])
  })

  it('rebuilds every stored value and subject as a retained snapshot', () => {
    const form = calibrationFormFromRecord(mockRecord)

    expect(form.system).toEqual({ source: 'retained', id: 2, code: 'sys-01', name: '系统1' })
    expect(form.standard).toMatchObject({ source: 'retained', equipment_id: 5, standard_no: 'XPD-L-030' })
    expect(form.equipment).toEqual([
      {
        child_id: 101,
        equipment_id: 10,
        equipment_no: 'XPD-S-001',
        equipment_name: '智能电源',
        manufacturer: '杭州远方',
        model: 'DPS1060',
        serial_no: 'SN123',
        next_calibration_date: '2027-01-01',
      },
    ])
    expect(form.probe).toBe('far_field')
    expect(form.test_distance).toBe('26.2314')
    expect(form.calibration_coefficient).toBe('1.0024')
    expect(form.frequency).toBe('50')
    expect(form.remark).toBe('快照备注')
  })

  it('builds record list params, dropping blank filters', () => {
    const filters = { ...emptyPhotometricCurveCalibrationFilters, search: ' XPD-L-030 ', probe: 'far_field' }

    expect(buildPhotometricCurveCalibrationListParams(filters, 2, 20)).toEqual({
      search: 'XPD-L-030',
      probe: 'far_field',
      page: 2,
      per_page: 20,
    })
  })

  it('builds equipment ledger params independently of the record filters', () => {
    const filters = { ...emptyPhotometricCurveCalibrationEquipmentFilters, search: ' XPD-S-001 ', equipment_id: '10' }

    expect(buildPhotometricCurveCalibrationEquipmentListParams(filters, 1, 15)).toEqual({
      search: 'XPD-S-001',
      equipment_id: '10',
      page: 1,
      per_page: 15,
    })
  })

  it('keys the ledger parent filter on calibration_record_id, never the inspection key', () => {
    expect(emptyPhotometricCurveCalibrationEquipmentFilters).toEqual({
      search: '',
      calibration_record_id: '',
      equipment_id: '',
      date_from: '',
      date_to: '',
    })
    expect(emptyPhotometricCurveCalibrationEquipmentFilters).not.toHaveProperty('inspection_record_id')

    const params = buildPhotometricCurveCalibrationEquipmentListParams(
      { ...emptyPhotometricCurveCalibrationEquipmentFilters, calibration_record_id: ' 7 ' },
      1,
      15,
    )

    expect(params).toEqual({ calibration_record_id: '7', page: 1, per_page: 15 })
    expect(params).not.toHaveProperty('inspection_record_id')
  })

  it('drops a blank parent id instead of sending an empty filter', () => {
    expect(
      buildPhotometricCurveCalibrationEquipmentListParams(
        { ...emptyPhotometricCurveCalibrationEquipmentFilters, calibration_record_id: '   ' },
        2,
        30,
      ),
    ).toEqual({ page: 2, per_page: 30 })
  })
})
