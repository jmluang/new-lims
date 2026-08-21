import { describe, expect, it } from 'vitest'
import {
  addEquipmentSnapshot,
  buildIntegratingSphereEquipmentListParams,
  buildIntegratingSphereInspectionListParams,
  buildIntegratingSphereInspectionPayload,
  compareDecimalStrings,
  emptyIntegratingSphereInspectionForm,
  emptyIntegratingSphereEquipmentFilters,
  emptyIntegratingSphereInspectionFilters,
  equipmentEntryKey,
  integratingSphereFieldErrors,
  selectedSystem,
  integratingSphereMeasurementFields,
  inspectionFormFromRecord,
  measurementValueError,
  normalizeMeasurementInput,
  removeEquipmentSnapshot,
  type IntegratingSphereInspectionRecord,
} from '../integratingSphereInspectionSchema'

const liveDevice = {
  child_id: null,
  equipment_id: 7,
  equipment_no: 'XPD-S-001',
  equipment_name: '积分球',
  manufacturer: '杭州远方',
  model: 'HAAS-2000',
  serial_no: 'SN-XPD-001',
  next_calibration_date: '2027-03-01',
}
const secondDevice = { ...liveDevice, equipment_id: 8, equipment_no: 'XPD-S-002', equipment_name: '光谱仪' }

// What the lookup endpoint returns: a live ledger row keyed by `id`.
const firstLookup = {
  id: 7,
  equipment_no: 'XPD-S-001',
  equipment_name: '积分球',
  manufacturer: '杭州远方',
  model: 'HAAS-2000',
  serial_no: 'SN-XPD-001',
  next_calibration_date: '2027-03-01',
}
const secondLookup = { ...firstLookup, id: 8, equipment_no: 'XPD-S-002', equipment_name: '光谱仪' }

// What the system lookup returns: a live, active equipment system keyed by `id`.
const systemLookup = { id: 5, code: 'sys-01', name: '系统1', status: 'active' }

const validForm = {
  ...emptyIntegratingSphereInspectionForm(),
  sample: { source: 'selected' as const, id: 3, sample_no: '26010058874-1-1/1', sample_name: '灯具', model: 'LD-1' },
  system: { source: 'selected' as const, id: 5, code: 'sys-01', name: '系统1' },
  equipment: [liveDevice, secondDevice],
  recorded_at: '2026-08-20T12:27',
  chromaticity_x: '0.3633',
  chromaticity_y: '0.3549',
  dominant_wavelength: '580.5',
  peak_wavelength: '601.2',
  color_temperature: '4360',
  color_rendering_index: '88.4',
  luminous_flux: '1234.5',
  voltage: '220.0',
  current: '0.0451',
  power: '9.8765',
  power_factor: '0.9876',
  frequency: '50',
  remark: '  首件点检  ',
}

const storedRecord: IntegratingSphereInspectionRecord = {
  id: 1,
  sample_id: 3,
  sample_no: '26010058874-1-1/1',
  equipment_system_id: 5,
  system_code: 'sys-01',
  chromaticity_x: '0.3633',
  chromaticity_y: '0.3549',
  dominant_wavelength: '580.5',
  peak_wavelength: '601.2',
  color_temperature: 4360,
  color_rendering_index: '88.4',
  luminous_flux: '1234.5',
  voltage: '220.0',
  current: '0.0451',
  power: '9.8765',
  power_factor: '0.9876',
  frequency: 50,
  remark: null,
  recorded_at: '2026-08-20 12:27:00',
  operator_id: 2,
  operator_name: '点检员',
  equipment: [
    {
      id: 11,
      equipment_id: 7,
      equipment_no: 'XPD-S-001',
      equipment_name: '积分球',
      manufacturer: '杭州远方',
      model: 'HAAS-2000',
      serial_no: 'SN-XPD-001',
      next_calibration_date: '2027-03-01',
    },
  ],
}

describe('integrating sphere measurement fields', () => {
  it('describes every measurement from the form in order with its scale and column bounds', () => {
    expect(integratingSphereMeasurementFields.map((field) => [field.name, field.scale, field.min, field.max])).toEqual([
      ['chromaticity_x', 4, '0', '99.9999'],
      ['chromaticity_y', 4, '0', '99.9999'],
      ['dominant_wavelength', 1, '0', '999999.9'],
      ['peak_wavelength', 1, '0', '999999.9'],
      ['color_temperature', 0, '0', '1000000'],
      ['color_rendering_index', 1, '-9999.9', '9999.9'],
      ['luminous_flux', 1, '0', '99999999999.9'],
      ['voltage', 1, '0', '99999999.9'],
      ['current', 4, '0', '99999999.9999'],
      ['power', 4, '0', '99999999.9999'],
      ['power_factor', 4, '0', '99.9999'],
      ['frequency', 0, '0', '1000000'],
    ])
  })
})

describe('string-only measurement canonicalization', () => {
  it('pads to the exact scale without going through a binary float', () => {
    expect(normalizeMeasurementInput('0.36', 4)).toBe('0.3600')
    expect(normalizeMeasurementInput('220', 1)).toBe('220.0')
    expect(normalizeMeasurementInput('4360', 0)).toBe('4360')
    expect(normalizeMeasurementInput('88.4', 1)).toBe('88.4')
    expect(normalizeMeasurementInput('  0.36  ', 4)).toBe('0.3600')
    expect(normalizeMeasurementInput('0007.5', 1)).toBe('7.5')
    expect(normalizeMeasurementInput('-0.0000', 4)).toBe('0.0000')
    expect(normalizeMeasurementInput('-9999.9', 1)).toBe('-9999.9')
  })

  it('preserves digits that a float round trip would corrupt', () => {
    // These are the values `Number(value).toFixed(scale)` cannot be trusted with.
    expect(normalizeMeasurementInput('99999999999.9', 1)).toBe('99999999999.9')
    expect(normalizeMeasurementInput('99999999.9999', 4)).toBe('99999999.9999')
    expect(normalizeMeasurementInput('0.1235', 4)).toBe('0.1235')
    expect(normalizeMeasurementInput('1.0050', 4)).toBe('1.0050')
    expect(normalizeMeasurementInput('8.165', 4)).toBe('8.1650')
    expect(normalizeMeasurementInput('1.005', 2)).toBeNull()
  })

  it('is exact where a float round trip demonstrably is not', () => {
    // Far beyond any configured column bound, but it proves the canonicalizer is
    // string-only: a double cannot hold these digits, so `Number(v).toFixed(scale)`
    // returns something else entirely.
    const huge = '123456789012345678901234567890.12345'
    expect(normalizeMeasurementInput(huge, 5)).toBe(huge)
    expect(Number(huge).toFixed(5)).not.toBe(huge)

    const precise = '0.30000000000000004'
    expect(normalizeMeasurementInput(precise, 17)).toBe(precise)
    expect(normalizeMeasurementInput('0.1000000000000000055511151231257827', 34)).toBe(
      '0.1000000000000000055511151231257827',
    )
  })

  it('rejects excessive scale, non-numeric text and empty input', () => {
    expect(normalizeMeasurementInput('0.36335', 4)).toBeNull()
    expect(normalizeMeasurementInput('580.55', 1)).toBeNull()
    expect(normalizeMeasurementInput('4360.5', 0)).toBeNull()
    expect(normalizeMeasurementInput('abc', 1)).toBeNull()
    expect(normalizeMeasurementInput('', 1)).toBeNull()
    expect(normalizeMeasurementInput('1.', 1)).toBeNull()
    expect(normalizeMeasurementInput('1e3', 1)).toBeNull()
    expect(normalizeMeasurementInput('NaN', 1)).toBeNull()
    expect(normalizeMeasurementInput('Infinity', 1)).toBeNull()
  })

  it('compares canonical decimals exactly as strings', () => {
    expect(compareDecimalStrings('99999999999.9', '99999999999.9')).toBe(0)
    expect(compareDecimalStrings('99999999999.8', '99999999999.9')).toBe(-1)
    expect(compareDecimalStrings('100000000000.0', '99999999999.9')).toBe(1)
    expect(compareDecimalStrings('-9999.9', '9999.9')).toBe(-1)
    expect(compareDecimalStrings('-9999.9', '-10000.0')).toBe(1)
    expect(compareDecimalStrings('0.0000', '0.0000')).toBe(0)
  })
})

describe('measurement range checks against the configured column limits', () => {
  const fieldFor = (name: string) => integratingSphereMeasurementFields.find((field) => field.name === name)!

  it('accepts values sitting exactly on the limit', () => {
    expect(measurementValueError(fieldFor('chromaticity_x'), '99.9999')).toBeNull()
    expect(measurementValueError(fieldFor('luminous_flux'), '99999999999.9')).toBeNull()
    expect(measurementValueError(fieldFor('current'), '99999999.9999')).toBeNull()
    expect(measurementValueError(fieldFor('color_rendering_index'), '-9999.9')).toBeNull()
    expect(measurementValueError(fieldFor('color_temperature'), '1000000')).toBeNull()
    expect(measurementValueError(fieldFor('frequency'), '0')).toBeNull()
  })

  it('rejects the first value past the limit', () => {
    expect(measurementValueError(fieldFor('chromaticity_x'), '100.0000')).not.toBeNull()
    expect(measurementValueError(fieldFor('luminous_flux'), '100000000000.0')).not.toBeNull()
    expect(measurementValueError(fieldFor('current'), '100000000.0000')).not.toBeNull()
    expect(measurementValueError(fieldFor('color_rendering_index'), '-10000.0')).not.toBeNull()
    expect(measurementValueError(fieldFor('color_temperature'), '1000001')).not.toBeNull()
    expect(measurementValueError(fieldFor('frequency'), '-1')).not.toBeNull()
  })

  it('reports missing input and excessive scale separately from range', () => {
    expect(measurementValueError(fieldFor('chromaticity_x'), '')).toBe('请填写测量值')
    expect(measurementValueError(fieldFor('chromaticity_x'), '0.36335')).toContain('4')
    expect(measurementValueError(fieldFor('frequency'), '50.5')).toBe('请输入整数')
  })
})

describe('buildIntegratingSphereInspectionPayload', () => {
  it('sends canonical decimal strings and range-checked integer numbers on create', () => {
    const payload = buildIntegratingSphereInspectionPayload({ ...validForm, chromaticity_x: '0.36', voltage: '220' }, 'create')

    expect(payload).toEqual({
      sample_id: 3,
      equipment_system_id: 5,
      equipment_ids: [7, 8],
      recorded_at: '2026-08-20 12:27:00',
      chromaticity_x: '0.3600',
      chromaticity_y: '0.3549',
      dominant_wavelength: '580.5',
      peak_wavelength: '601.2',
      color_temperature: 4360,
      color_rendering_index: '88.4',
      luminous_flux: '1234.5',
      voltage: '220.0',
      current: '0.0451',
      power: '9.8765',
      power_factor: '0.9876',
      frequency: 50,
      remark: '首件点检',
    })
    expect(typeof payload.chromaticity_x).toBe('string')
    expect(typeof payload.color_temperature).toBe('number')
    expect(typeof payload.frequency).toBe('number')
  })

  it('sends a null remark when the operator left it blank', () => {
    expect(buildIntegratingSphereInspectionPayload({ ...validForm, remark: '   ' }, 'create').remark).toBeNull()
  })

  it('rejects a missing sample, an empty device list and invalid measurements', () => {
    expect(() => buildIntegratingSphereInspectionPayload({ ...validForm, sample: null }, 'create')).toThrow()
    expect(() => buildIntegratingSphereInspectionPayload({ ...validForm, equipment: [] }, 'create')).toThrow()
    expect(() => buildIntegratingSphereInspectionPayload({ ...validForm, chromaticity_x: '0.36335' }, 'create')).toThrow()
    expect(() => buildIntegratingSphereInspectionPayload({ ...validForm, frequency: '50.5' }, 'create')).toThrow()
    expect(() => buildIntegratingSphereInspectionPayload({ ...validForm, luminous_flux: '' }, 'create')).toThrow()
    expect(() => buildIntegratingSphereInspectionPayload({ ...validForm, power_factor: '100.0000' }, 'create')).toThrow()
  })

  it('refuses to create a record against a sample whose ledger row is gone', () => {
    expect(() =>
      buildIntegratingSphereInspectionPayload({ ...validForm, sample: { source: 'selected', id: null, sample_no: 'S-GONE' } }, 'create'),
    ).toThrow()
  })

  it('requires a live sample id on create regardless of how the sample was sourced', () => {
    expect(
      buildIntegratingSphereInspectionPayload(
        { ...validForm, sample: { source: 'retained', id: 3, sample_no: '26010058874-1-1/1' } },
        'create',
      ).sample_id,
    ).toBe(3)
    expect(() =>
      buildIntegratingSphereInspectionPayload(
        { ...validForm, sample: { source: 'retained', id: null, sample_no: 'S-GONE' } },
        'create',
      ),
    ).toThrow()
  })

  it('requires a live active system on create and sends its ledger id', () => {
    expect(buildIntegratingSphereInspectionPayload(validForm, 'create').equipment_system_id).toBe(5)
    expect(() => buildIntegratingSphereInspectionPayload({ ...validForm, system: null }, 'create')).toThrow()
    expect(() =>
      buildIntegratingSphereInspectionPayload({ ...validForm, system: { source: 'selected', id: null, code: 'sys-gone' } }, 'create'),
    ).toThrow()
    expect(() =>
      buildIntegratingSphereInspectionPayload({ ...validForm, system: { source: 'retained', id: null, code: 'sys-gone' } }, 'create'),
    ).toThrow()
  })

  it('accepts a retained system with a live id on create', () => {
    expect(
      buildIntegratingSphereInspectionPayload(
        { ...validForm, system: { source: 'retained', id: 5, code: 'sys-01' } },
        'create',
      ).equipment_system_id,
    ).toBe(5)
  })

  it('maps thrown issues back to the field that has to be corrected', () => {
    try {
      buildIntegratingSphereInspectionPayload(
        { ...validForm, sample: null, system: { source: 'selected', id: null, code: 'sys-gone' }, chromaticity_y: '0.35491', equipment: [] },
        'create',
      )
      throw new Error('expected the payload build to fail')
    } catch (error) {
      const fieldErrors = integratingSphereFieldErrors(error)

      expect(Object.keys(fieldErrors).sort()).toEqual(['chromaticity_y', 'equipment', 'sample', 'system'])
      expect(fieldErrors.chromaticity_y).toContain('4')
    }
  })
})

describe('update payload retention contract', () => {
  it('retains stored snapshots by child id and only sends newly scanned devices', () => {
    const form = {
      ...validForm,
      sample: { source: 'selected' as const, id: 3, sample_no: '26010058874-1-1/1' },
      equipment: [
        { ...liveDevice, child_id: 11 },
        { ...secondDevice, child_id: 12, equipment_id: null, equipment_no: 'XPD-S-002' },
        { ...liveDevice, child_id: null, equipment_id: 9, equipment_no: 'XPD-S-003' },
      ],
    }
    const payload = buildIntegratingSphereInspectionPayload(form, 'update')

    expect(payload.retained_equipment_ids).toEqual([11, 12])
    expect(payload.equipment_ids).toEqual([9])
    expect(payload.sample_id).toBe(3)
  })

  it('omits sample_id so the server keeps the snapshot when the ledger row was deleted', () => {
    const payload = buildIntegratingSphereInspectionPayload(
      {
        ...validForm,
        sample: { source: 'retained' as const, id: null, sample_no: 'S-ORPHAN' },
        equipment: [{ ...liveDevice, child_id: 11, equipment_id: null }],
      },
      'update',
    )

    expect('sample_id' in payload).toBe(false)
    expect(payload.retained_equipment_ids).toEqual([11])
    expect(payload.equipment_ids).toEqual([])
  })

  it('omits sample_id for a retained sample even when its ledger row still exists', () => {
    // The ledger row is alive and its id is known, but the operator did not re-scan
    // it. Sending the id would let the server re-snapshot a renamed sample number
    // over the one this measurement was actually filed under.
    const payload = buildIntegratingSphereInspectionPayload(
      {
        ...validForm,
        sample: { source: 'retained', id: 3, sample_no: '26010058874-1-1/1' },
        equipment: [{ ...liveDevice, child_id: 11 }],
      },
      'update',
    )

    expect('sample_id' in payload).toBe(false)
  })

  it('sends sample_id only once the operator scans or types a replacement', () => {
    const payload = buildIntegratingSphereInspectionPayload(
      {
        ...validForm,
        sample: { source: 'selected', id: 9, sample_no: '26010058874-2-1/1' },
        equipment: [{ ...liveDevice, child_id: 11 }],
      },
      'update',
    )

    expect(payload.sample_id).toBe(9)
  })

  it('omits equipment_system_id for a retained system so the stored code survives', () => {
    // The ledger row is alive and its id is known, but the operator did not re-scan
    // it. Sending the id would let the server re-snapshot a renamed system code over
    // the one this measurement was actually filed under.
    const live = buildIntegratingSphereInspectionPayload(
      { ...validForm, system: { source: 'retained', id: 5, code: 'sys-01' }, equipment: [{ ...liveDevice, child_id: 11 }] },
      'update',
    )
    const orphaned = buildIntegratingSphereInspectionPayload(
      { ...validForm, system: { source: 'retained', id: null, code: 'sys-01' }, equipment: [{ ...liveDevice, child_id: 11 }] },
      'update',
    )

    expect('equipment_system_id' in live).toBe(false)
    expect('equipment_system_id' in orphaned).toBe(false)
  })

  it('sends equipment_system_id only once the operator scans or types a replacement', () => {
    const payload = buildIntegratingSphereInspectionPayload(
      { ...validForm, system: { source: 'selected', id: 9, code: 'sys-02' }, equipment: [{ ...liveDevice, child_id: 11 }] },
      'update',
    )

    expect(payload.equipment_system_id).toBe(9)
  })

  it('leaves a legacy record without a system editable and re-declares nothing', () => {
    const payload = buildIntegratingSphereInspectionPayload(
      { ...validForm, system: null, equipment: [{ ...liveDevice, child_id: 11 }] },
      'update',
    )

    expect('equipment_system_id' in payload).toBe(false)
    expect(payload.retained_equipment_ids).toEqual([11])
  })

  it('still refuses an edit that would leave the record without any device', () => {
    expect(() => buildIntegratingSphereInspectionPayload({ ...validForm, equipment: [] }, 'update')).toThrow()
  })
})

describe('equipment selection', () => {
  it('never adds the same live device twice', () => {
    const once = addEquipmentSnapshot([], firstLookup)
    const twice = addEquipmentSnapshot(once, { ...firstLookup, equipment_name: '积分球（重复扫码）' })

    expect(once).toHaveLength(1)
    expect(once[0]).toMatchObject({ child_id: null, equipment_id: 7, equipment_no: 'XPD-S-001' })
    expect(twice).toHaveLength(1)
    expect(twice[0].equipment_name).toBe('积分球')
    expect(addEquipmentSnapshot(twice, secondLookup).map((device) => device.equipment_id)).toEqual([7, 8])
  })

  it('will not add a device that a retained snapshot already covers', () => {
    const retained = [{ ...liveDevice, child_id: 11 }]

    expect(addEquipmentSnapshot(retained, firstLookup)).toHaveLength(1)
    expect(addEquipmentSnapshot(retained, firstLookup)[0].child_id).toBe(11)
  })

  it('keys retained snapshots and live selections apart so removal hits one entry', () => {
    const list = [
      { ...liveDevice, child_id: 11 },
      { ...secondDevice, child_id: null },
    ]

    expect(list.map(equipmentEntryKey)).toEqual(['child:11', 'equipment:8'])
    expect(removeEquipmentSnapshot(list, 'child:11').map(equipmentEntryKey)).toEqual(['equipment:8'])
    expect(removeEquipmentSnapshot(list, 'equipment:8').map(equipmentEntryKey)).toEqual(['child:11'])
  })
})

describe('system selection', () => {
  it('wraps a lookup result as the operator\'s explicit replacement', () => {
    expect(selectedSystem(systemLookup)).toEqual({ source: 'selected', id: 5, code: 'sys-01', name: '系统1' })
  })
})

describe('list params and edit prefill', () => {
  it('drops blank filters and keeps pagination', () => {
    expect(
      buildIntegratingSphereInspectionListParams({ ...emptyIntegratingSphereInspectionFilters, search: 'XPD' }, 2, 30),
    ).toEqual({ search: 'XPD', page: 2, per_page: 30 })
    expect(buildIntegratingSphereInspectionListParams(emptyIntegratingSphereInspectionFilters, 1, 15)).toEqual({
      page: 1,
      per_page: 15,
    })
    expect(
      buildIntegratingSphereInspectionListParams({ search: '', date_from: '2026-08-01', date_to: '2026-08-20' }, 1, 15),
    ).toEqual({ date_from: '2026-08-01', date_to: '2026-08-20', page: 1, per_page: 15 })
  })

  it('prefills the edit form from a saved record without losing decimal scale', () => {
    const form = inspectionFormFromRecord(storedRecord)

    expect(form.sample).toEqual({ source: 'retained', id: 3, sample_no: '26010058874-1-1/1' })
    expect(form.system).toEqual({ source: 'retained', id: 5, code: 'sys-01' })
    expect(form.equipment).toHaveLength(1)
    expect(form.equipment[0]).toMatchObject({ child_id: 11, equipment_id: 7, equipment_no: 'XPD-S-001' })
    expect(form.chromaticity_x).toBe('0.3633')
    expect(form.color_temperature).toBe('4360')
    expect(form.frequency).toBe('50')
    expect(form.recorded_at).toBe('2026-08-20T12:27')
    expect(form.remark).toBe('')
  })

  it('round trips a record with a live sample and system without ever re-declaring them', () => {
    const form = inspectionFormFromRecord(storedRecord)
    const payload = buildIntegratingSphereInspectionPayload(
      { ...form, ...validForm, sample: form.sample, system: form.system, equipment: form.equipment },
      'update',
    )

    expect(form.sample).toMatchObject({ source: 'retained', id: 3 })
    expect(form.system).toMatchObject({ source: 'retained', id: 5 })
    expect('sample_id' in payload).toBe(false)
    expect('equipment_system_id' in payload).toBe(false)
    expect(payload.retained_equipment_ids).toEqual([11])
    expect(payload.equipment_ids).toEqual([])
  })

  it('keeps the sample and device snapshots of deleted ledger rows so the record stays editable', () => {
    const orphaned: IntegratingSphereInspectionRecord = {
      ...storedRecord,
      sample_id: null,
      sample_no: 'S-HISTORY',
      equipment_system_id: null,
      equipment: [
        storedRecord.equipment[0],
        {
          id: 12,
          equipment_id: null,
          equipment_no: 'XPD-S-002',
          equipment_name: '光谱仪',
          manufacturer: '虹昌电子',
          model: 'SPC-100',
          serial_no: 'SN-XPD-002',
          next_calibration_date: '2027-05-20',
        },
      ],
    }
    const form = inspectionFormFromRecord(orphaned)

    expect(form.sample).toEqual({ source: 'retained', id: null, sample_no: 'S-HISTORY' })
    expect(form.system).toEqual({ source: 'retained', id: null, code: 'sys-01' })
    expect(form.equipment.map((device) => device.equipment_no)).toEqual(['XPD-S-001', 'XPD-S-002'])
    expect(form.equipment[1]).toMatchObject({
      child_id: 12,
      equipment_id: null,
      equipment_name: '光谱仪',
      manufacturer: '虹昌电子',
      serial_no: 'SN-XPD-002',
      next_calibration_date: '2027-05-20',
    })

    // Editing only a measurement must send every snapshot back for retention.
    const payload = buildIntegratingSphereInspectionPayload(
      { ...form, ...validForm, sample: form.sample, system: form.system, equipment: form.equipment },
      'update',
    )

    expect(payload.retained_equipment_ids).toEqual([11, 12])
    expect(payload.equipment_ids).toEqual([])
    expect('sample_id' in payload).toBe(false)
    expect('equipment_system_id' in payload).toBe(false)
  })

  it('prefills no system for a legacy record that never had one', () => {
    const form = inspectionFormFromRecord({ ...storedRecord, equipment_system_id: null, system_code: null })

    expect(form.system).toBeNull()
  })
})

describe('global used-equipment ledger params', () => {
  it('drops blank filters, trims values and keeps pagination', () => {
    expect(buildIntegratingSphereEquipmentListParams(emptyIntegratingSphereEquipmentFilters, 1, 15)).toEqual({
      page: 1,
      per_page: 15,
    })
    expect(
      buildIntegratingSphereEquipmentListParams(
        { search: '  XPD  ', inspection_record_id: '4', equipment_id: '', date_from: '2026-08-01', date_to: '' },
        3,
        50,
      ),
    ).toEqual({ search: 'XPD', inspection_record_id: '4', date_from: '2026-08-01', page: 3, per_page: 50 })
    expect(
      buildIntegratingSphereEquipmentListParams(
        { ...emptyIntegratingSphereEquipmentFilters, equipment_id: '7', date_to: '2026-08-20' },
        1,
        15,
      ),
    ).toEqual({ equipment_id: '7', date_to: '2026-08-20', page: 1, per_page: 15 })
    expect(
      buildIntegratingSphereEquipmentListParams({ ...emptyIntegratingSphereEquipmentFilters, search: '   ' }, 1, 15),
    ).toEqual({ page: 1, per_page: 15 })
  })

  it('keys the ledger parent filter on inspection_record_id, never the calibration key', () => {
    expect(emptyIntegratingSphereEquipmentFilters).toEqual({
      search: '',
      inspection_record_id: '',
      equipment_id: '',
      date_from: '',
      date_to: '',
    })
    expect(emptyIntegratingSphereEquipmentFilters).not.toHaveProperty('calibration_record_id')

    const params = buildIntegratingSphereEquipmentListParams(
      { ...emptyIntegratingSphereEquipmentFilters, inspection_record_id: '4' },
      1,
      15,
    )

    expect(params).toEqual({ inspection_record_id: '4', page: 1, per_page: 15 })
    expect(params).not.toHaveProperty('calibration_record_id')
  })
})
