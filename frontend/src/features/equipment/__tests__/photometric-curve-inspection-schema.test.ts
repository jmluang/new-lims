import { describe, expect, it } from 'vitest'
import {
  addEquipmentSnapshot,
  buildPhotometricCurveEquipmentListParams,
  buildPhotometricCurveInspectionListParams,
  buildPhotometricCurveInspectionPayload,
  deriveAverageAngle,
  emptyPhotometricCurveEquipmentFilters,
  emptyPhotometricCurveInspectionFilters,
  emptyPhotometricCurveInspectionForm,
  equipmentEntryKey,
  inspectionFormFromRecord,
  measurementValueError,
  mediaSelectionError,
  normalizeMeasurementInput,
  photometricCurveFieldErrors,
  photometricCurveMeasurementFields,
  probeLabel,
  removeEquipmentSnapshot,
  selectedSample,
  selectedSystem,
  type PhotometricCurveInspectionRecord,
} from '../photometricCurveInspectionSchema'

const liveDevice = {
  child_id: null,
  equipment_id: 7,
  equipment_no: 'XPD-S-001',
  equipment_name: '智能交流测试专用电源',
  manufacturer: '杭州远方',
  model: 'DPS1060-V200',
  serial_no: 'G117422CJ1361114',
  next_calibration_date: '2027-03-01',
}
const secondDevice = { ...liveDevice, equipment_id: 8, equipment_no: 'XPD-S-004', equipment_name: '数字功率计' }

// What the lookup endpoint returns: a live ledger row keyed by `id`.
const firstLookup = {
  id: 7,
  equipment_no: 'XPD-S-001',
  equipment_name: '智能交流测试专用电源',
  manufacturer: '杭州远方',
  model: 'DPS1060-V200',
  serial_no: 'G117422CJ1361114',
  next_calibration_date: '2027-03-01',
}
const secondLookup = { ...firstLookup, id: 8, equipment_no: 'XPD-S-004', equipment_name: '数字功率计' }
const systemLookup = { id: 5, code: 'sys-01', name: '系统1', status: 'active' }
const sampleLookup = { id: 3, sample_no: '26010058874-1-1/1', sample_name: '灯具', model: 'LD-1' }

const validForm = {
  ...emptyPhotometricCurveInspectionForm(),
  sample: { source: 'selected' as const, id: 3, sample_no: '26010058874-1-1/1', sample_name: '灯具', model: 'LD-1' },
  system: { source: 'selected' as const, id: 5, code: 'sys-01', name: '系统1' },
  equipment: [liveDevice, secondDevice],
  probe: 'far_field' as const,
  c0_180: '60.2',
  c30_210: '60.3',
  c60_240: '64.5',
  c90_270: '60.8',
  test_distance: '26.0000',
  peak_luminous_intensity: '221.0',
  luminous_flux: '1674.0',
  voltage: '220.80',
  current: '0.1189',
  power: '14.2400',
  power_factor: '0.5422',
  frequency: '50',
  remark: '  首件点检  ',
}

const storedRecord: PhotometricCurveInspectionRecord = {
  id: 1,
  sample_id: 3,
  sample_no: '26010058874-1-1/1',
  equipment_system_id: 5,
  system_code: 'sys-01',
  system_name: '系统1',
  c0_180: '60.2',
  c30_210: '60.3',
  c60_240: '64.5',
  c90_270: '60.8',
  average_angle: '61.5',
  probe: 'near_field',
  test_distance: '26.0000',
  peak_luminous_intensity: '221.0',
  luminous_flux: '1674.0',
  voltage: '220.80',
  current: '0.1189',
  power: '14.2400',
  power_factor: '0.5422',
  frequency: 50,
  remark: '首件点检',
  recorded_at: '2026-08-21 10:29:00',
  operator_id: 2,
  operator_name: '张三',
  equipment: [
    {
      id: 11,
      equipment_id: 7,
      equipment_no: 'XPD-S-001',
      equipment_name: '智能交流测试专用电源',
      manufacturer: '杭州远方',
      model: 'DPS1060-V200',
      serial_no: 'G117422CJ1361114',
      next_calibration_date: '2027-03-01',
    },
  ],
  photos: [{ id: 21, collection: 'photos', file_name: 'curve.jpg', mime_type: 'image/jpeg', size: 2048, sha256: 'a'.repeat(64) }],
  files: [{ id: 22, collection: 'files', file_name: 'report.pdf', mime_type: 'application/pdf', size: 4096, sha256: 'b'.repeat(64) }],
}

function entries(body: FormData, key: string) {
  return body.getAll(key).map((value) => (value instanceof File ? value.name : String(value)))
}

function fields(body: FormData) {
  return Object.fromEntries([...body.keys()].map((key) => [key, body.getAll(key).length]))
}

describe('measurement scales and units', () => {
  it('states the workbook precision and unambiguous units for every field', () => {
    expect(photometricCurveMeasurementFields.map((field) => [field.name, field.scale, field.unit])).toEqual([
      ['c0_180', 1, '°'],
      ['c30_210', 1, '°'],
      ['c60_240', 1, '°'],
      ['c90_270', 1, '°'],
      ['test_distance', 4, 'm'],
      ['peak_luminous_intensity', 1, 'cd'],
      ['luminous_flux', 1, 'lm'],
      ['voltage', 2, 'V'],
      ['current', 4, 'A'],
      ['power', 4, 'W'],
      ['power_factor', 4, ''],
      ['frequency', 0, 'Hz'],
    ])
  })

  it('normalizes to the exact scale without going through a float', () => {
    expect(normalizeMeasurementInput('26', 4)).toBe('26.0000')
    expect(normalizeMeasurementInput(' 60.2 ', 1)).toBe('60.2')
    expect(normalizeMeasurementInput('14.24', 4)).toBe('14.2400')
    expect(normalizeMeasurementInput('0.5422', 4)).toBe('0.5422')
    expect(normalizeMeasurementInput('50', 0)).toBe('50')
    expect(normalizeMeasurementInput('60.25', 1)).toBeNull()
    expect(normalizeMeasurementInput('1e-2', 4)).toBeNull()
    expect(normalizeMeasurementInput('.5', 4)).toBeNull()
    expect(normalizeMeasurementInput('50.5', 0)).toBeNull()
  })

  it('reports a field specific message for empty, over-precise and out-of-range values', () => {
    const angle = photometricCurveMeasurementFields[0]
    const powerFactor = photometricCurveMeasurementFields.find((field) => field.name === 'power_factor')!
    const frequency = photometricCurveMeasurementFields.find((field) => field.name === 'frequency')!

    expect(measurementValueError(angle, '')).toBe('请填写测量值')
    expect(measurementValueError(angle, '60.25')).toBe('最多保留 1 位小数')
    expect(measurementValueError(angle, '-1.0')).toBe('请输入 0.0 到 9999.9 之间的数值')
    expect(measurementValueError(angle, '60.2')).toBeNull()
    // The power factor is a ratio, so its ceiling is 1 rather than the column width.
    expect(measurementValueError(powerFactor, '1.0001')).toBe('请输入 0.0000 到 1.0000 之间的数值')
    expect(measurementValueError(powerFactor, '1.0000')).toBeNull()
    expect(measurementValueError(frequency, '50.5')).toBe('请输入整数')
  })
})

describe('derived average angle', () => {
  it('rounds a quarter of the four angles half up on exact tenths', () => {
    // 60.2 + 60.3 + 64.5 + 60.8 = 245.8; a quarter is exactly 61.45, so the tie rounds
    // up. The workbook's hand-typed 61.1 is the drift this removes.
    expect(deriveAverageAngle({ c0_180: '60.2', c30_210: '60.3', c60_240: '64.5', c90_270: '60.8' })).toBe('61.5')
    expect(deriveAverageAngle({ c0_180: '60.2', c30_210: '60.2', c60_240: '60.2', c90_270: '60.2' })).toBe('60.2')
    expect(deriveAverageAngle({ c0_180: '0.1', c30_210: '0.1', c60_240: '0.1', c90_270: '0.2' })).toBe('0.1')
    expect(deriveAverageAngle({ c0_180: '0.0', c30_210: '0.0', c60_240: '0.0', c90_270: '0.0' })).toBe('0.0')
    expect(deriveAverageAngle({ c0_180: '0.3', c30_210: '0.3', c60_240: '0.3', c90_270: '0.4' })).toBe('0.3')
  })

  it('reacts to every one of the four angles', () => {
    const base = { c0_180: '60.0', c30_210: '60.0', c60_240: '60.0', c90_270: '60.0' }

    expect(deriveAverageAngle(base)).toBe('60.0')
    expect(deriveAverageAngle({ ...base, c0_180: '64.0' })).toBe('61.0')
    expect(deriveAverageAngle({ ...base, c30_210: '64.0' })).toBe('61.0')
    expect(deriveAverageAngle({ ...base, c60_240: '64.0' })).toBe('61.0')
    expect(deriveAverageAngle({ ...base, c90_270: '64.0' })).toBe('61.0')
  })

  it('stays blank until all four angles are valid one-decimal values', () => {
    expect(deriveAverageAngle({ c0_180: '60.2', c30_210: '', c60_240: '60.2', c90_270: '60.2' })).toBe('')
    expect(deriveAverageAngle({ c0_180: '60.2', c30_210: '60.', c60_240: '60.2', c90_270: '60.2' })).toBe('')
    expect(deriveAverageAngle({ c0_180: '60.25', c30_210: '60.2', c60_240: '60.2', c90_270: '60.2' })).toBe('')
    expect(deriveAverageAngle({ c0_180: '-60.2', c30_210: '60.2', c60_240: '60.2', c90_270: '60.2' })).toBe('')
  })

  it('agrees with the value the API derives for the stored record', () => {
    const record = inspectionFormFromRecord(storedRecord)

    expect(deriveAverageAngle(record)).toBe(storedRecord.average_angle)
  })
})

describe('create payload', () => {
  it('encodes every measurement as a canonical string in multipart form data', () => {
    const body = buildPhotometricCurveInspectionPayload(validForm, 'create')

    expect(body).toBeInstanceOf(FormData)
    expect(body.get('c0_180')).toBe('60.2')
    expect(body.get('c30_210')).toBe('60.3')
    expect(body.get('c60_240')).toBe('64.5')
    expect(body.get('c90_270')).toBe('60.8')
    expect(body.get('test_distance')).toBe('26.0000')
    expect(body.get('peak_luminous_intensity')).toBe('221.0')
    expect(body.get('luminous_flux')).toBe('1674.0')
    expect(body.get('voltage')).toBe('220.80')
    expect(body.get('current')).toBe('0.1189')
    expect(body.get('power')).toBe('14.2400')
    expect(body.get('power_factor')).toBe('0.5422')
    expect(body.get('frequency')).toBe('50')
    expect(body.get('probe')).toBe('far_field')
    expect(body.get('remark')).toBe('首件点检')
    expect(body.get('sample_id')).toBe('3')
    expect(body.get('equipment_system_id')).toBe('5')
    expect(entries(body, 'equipment_ids[]')).toEqual(['7', '8'])
  })

  it('never sends the derived average, the recorded time, or retention fields on create', () => {
    const body = buildPhotometricCurveInspectionPayload(validForm, 'create')

    expect(body.has('average_angle')).toBe(false)
    // The recorded time is a server-owned audit value; the editor has no field for it
    // and the payload must never carry one.
    expect(body.has('recorded_at')).toBe(false)
    expect(body.has('retained_equipment_ids')).toBe(false)
    expect(body.has('retained_equipment_ids[]')).toBe(false)
    expect(body.has('retained_media_ids')).toBe(false)
    expect(body.has('_method')).toBe(false)
  })

  it('omits an empty remark instead of sending a blank', () => {
    const body = buildPhotometricCurveInspectionPayload({ ...validForm, remark: '   ' }, 'create')

    expect(body.has('remark')).toBe(false)
  })

  it('drops a recorded time smuggled into the form object on create and on update', () => {
    // Nothing in the editor can set this, but a stray property must not become a field:
    // the API owns the timestamp and the payload builder is the last place to prove it.
    const smuggled = { ...validForm, recorded_at: '1999-01-01T00:00' } as typeof validForm

    expect(buildPhotometricCurveInspectionPayload(smuggled, 'create').has('recorded_at')).toBe(false)
    expect(buildPhotometricCurveInspectionPayload(smuggled, 'update').has('recorded_at')).toBe(false)
  })

  it('refuses a record without a live sample, a live system, a device or a valid probe', () => {
    expect(() => buildPhotometricCurveInspectionPayload({ ...validForm, sample: null }, 'create')).toThrow()
    expect(() => buildPhotometricCurveInspectionPayload({ ...validForm, system: null }, 'create')).toThrow()
    expect(() => buildPhotometricCurveInspectionPayload({ ...validForm, equipment: [] }, 'create')).toThrow()

    const orphanSample = { ...validForm, sample: { source: 'retained' as const, id: null, sample_no: 'S-GONE' } }
    expect(() => buildPhotometricCurveInspectionPayload(orphanSample, 'create')).toThrow()
  })

  it('marks every field that still has to be fixed, not only the first', () => {
    try {
      buildPhotometricCurveInspectionPayload({ ...validForm, system: null, c0_180: '', voltage: 'abc' }, 'create')
      expect.unreachable('the payload should not build')
    } catch (error) {
      const errors = photometricCurveFieldErrors(error)

      expect(errors.system).toBe('请先录入系统编码')
      expect(errors.c0_180).toBe('请填写测量值')
      expect(errors.voltage).toBe('最多保留 2 位小数')
    }
  })
})

describe('update payload', () => {
  it('spoofs the method and keeps a retained sample and system out of the body', () => {
    const form = inspectionFormFromRecord(storedRecord)
    const body = buildPhotometricCurveInspectionPayload(form, 'update')

    expect(body.get('_method')).toBe('PUT')
    expect(body.has('sample_id')).toBe(false)
    expect(body.has('equipment_system_id')).toBe(false)
    expect(entries(body, 'retained_equipment_ids[]')).toEqual(['11'])
    expect(entries(body, 'retained_media_ids[]')).toEqual(['21', '22'])
    expect(entries(body, 'equipment_ids[]')).toEqual([])
  })

  it('sends a re-scanned sample and system as an explicit replacement', () => {
    const form = {
      ...inspectionFormFromRecord(storedRecord),
      sample: selectedSample(sampleLookup),
      system: selectedSystem(systemLookup),
    }
    const body = buildPhotometricCurveInspectionPayload(form, 'update')

    expect(body.get('sample_id')).toBe('3')
    expect(body.get('equipment_system_id')).toBe('5')
  })

  it('always names the retained lists, as an empty string when they are empty', () => {
    // A multipart body cannot carry an empty array and an absent field means "keep
    // everything" to the API, which is the opposite of clearing the list.
    const form = { ...inspectionFormFromRecord(storedRecord), retained_media: [] }
    const body = buildPhotometricCurveInspectionPayload({ ...form, equipment: [liveDevice] }, 'update')

    expect(body.get('retained_media_ids')).toBe('')
    expect(body.get('retained_equipment_ids')).toBe('')
    expect(entries(body, 'equipment_ids[]')).toEqual(['7'])
  })

  it('keeps an orphaned device snapshot retained through an unrelated measurement edit', () => {
    const orphaned: PhotometricCurveInspectionRecord = {
      ...storedRecord,
      equipment: [{ ...storedRecord.equipment[0], equipment_id: null }],
    }
    const form = inspectionFormFromRecord(orphaned)
    const body = buildPhotometricCurveInspectionPayload({ ...form, voltage: '221.00' }, 'update')

    expect(entries(body, 'retained_equipment_ids[]')).toEqual(['11'])
    expect(entries(body, 'equipment_ids[]')).toEqual([])
    expect(body.get('voltage')).toBe('221.00')
  })

  it('still refuses an edit that would leave the record without any device', () => {
    expect(() => buildPhotometricCurveInspectionPayload({ ...validForm, equipment: [] }, 'update')).toThrow()
  })
})

describe('attachments', () => {
  const photo = new File(['x'], 'curve.jpg', { type: 'image/jpeg' })
  const document = new File(['y'], 'report.pdf', { type: 'application/pdf' })

  it('appends newly picked files under the collection each one belongs to', () => {
    const body = buildPhotometricCurveInspectionPayload(
      { ...validForm, new_photos: [photo], new_files: [document] },
      'create',
    )

    expect(entries(body, 'photos[]')).toEqual(['curve.jpg'])
    expect(entries(body, 'files[]')).toEqual(['report.pdf'])
    expect(fields(body)['photos[]']).toBe(1)
  })

  it('refuses a file over the collection size limit', () => {
    const huge = new File([], 'huge.jpg', { type: 'image/jpeg' })
    Object.defineProperty(huge, 'size', { value: 10 * 1024 * 1024 + 1 })

    expect(mediaSelectionError({ ...validForm, new_photos: [huge] }, 'photos')).toBe('huge.jpg 超过 10 MB 上限')
    expect(() => buildPhotometricCurveInspectionPayload({ ...validForm, new_photos: [huge] }, 'create')).toThrow()
  })

  it('counts retained attachments against the collection limit', () => {
    const retained = Array.from({ length: 9 }, (_, index) => ({
      id: index + 1,
      collection: 'photos' as const,
      file_name: `p${index}.jpg`,
      mime_type: 'image/jpeg',
      size: 10,
      sha256: null,
    }))
    const form = { ...validForm, retained_media: retained, new_photos: [photo] }

    expect(mediaSelectionError(form, 'photos')).toBeNull()
    expect(mediaSelectionError({ ...form, new_photos: [photo, photo] }, 'photos')).toBe('最多保留 10 个附件')
  })

  it('reports the limit against the collection field so the picker can mark it', () => {
    const tooMany = Array.from({ length: 11 }, () => document)

    try {
      buildPhotometricCurveInspectionPayload({ ...validForm, new_files: tooMany }, 'create')
      expect.unreachable('the payload should not build')
    } catch (error) {
      expect(photometricCurveFieldErrors(error).files).toBe('最多保留 10 个附件')
    }
  })
})

describe('equipment selection', () => {
  it('never adds the same live device twice', () => {
    const once = addEquipmentSnapshot([], firstLookup)
    const twice = addEquipmentSnapshot(once, { ...firstLookup, equipment_name: '重复扫码' })

    expect(once).toHaveLength(1)
    expect(once[0]).toMatchObject({ child_id: null, equipment_id: 7, equipment_no: 'XPD-S-001' })
    expect(twice).toHaveLength(1)
    expect(twice[0].equipment_name).toBe('智能交流测试专用电源')
    expect(addEquipmentSnapshot(twice, secondLookup).map((device) => device.equipment_id)).toEqual([7, 8])
  })

  it('will not add a device that a retained snapshot already covers', () => {
    const retained = [{ ...liveDevice, child_id: 11 }]

    expect(addEquipmentSnapshot(retained, firstLookup)).toHaveLength(1)
    expect(addEquipmentSnapshot(retained, firstLookup)[0].child_id).toBe(11)
  })

  it('keys a stored snapshot by its child id and a fresh scan by its ledger id', () => {
    expect(equipmentEntryKey({ ...liveDevice, child_id: 11 })).toBe('child:11')
    expect(equipmentEntryKey(liveDevice)).toBe('equipment:7')
    expect(removeEquipmentSnapshot([liveDevice, secondDevice], 'equipment:7')).toEqual([secondDevice])
  })
})

describe('list parameters and stored record round trip', () => {
  it('drops empty filters and keeps pagination', () => {
    expect(buildPhotometricCurveInspectionListParams({ ...emptyPhotometricCurveInspectionFilters, probe: 'near_field' }, 2, 15)).toEqual({
      probe: 'near_field',
      page: 2,
      per_page: 15,
    })
    expect(buildPhotometricCurveEquipmentListParams({ ...emptyPhotometricCurveEquipmentFilters, search: '  XPD  ' }, 1, 20)).toEqual({
      search: 'XPD',
      page: 1,
      per_page: 20,
    })
  })

  it('rebuilds the editor from a stored record, attachments included', () => {
    const form = inspectionFormFromRecord(storedRecord)

    expect(form.sample).toEqual({ source: 'retained', id: 3, sample_no: '26010058874-1-1/1' })
    expect(form.system).toEqual({ source: 'retained', id: 5, code: 'sys-01', name: '系统1' })
    expect(form.equipment).toEqual([{ ...liveDevice, child_id: 11 }])
    expect(form.probe).toBe('near_field')
    // The stored record carries a recorded time, but the editor never holds one.
    expect('recorded_at' in form).toBe(false)
    expect(form.retained_media.map((media) => media.id)).toEqual([21, 22])
    expect(form.new_photos).toEqual([])
    expect(form.new_files).toEqual([])
    expect(form.c60_240).toBe('64.5')
    expect(form.frequency).toBe('50')
  })

  it('names the two probes in Chinese for display', () => {
    expect(probeLabel('near_field')).toBe('近场')
    expect(probeLabel('far_field')).toBe('远场')
    expect(probeLabel(null)).toBe('-')
  })
})
