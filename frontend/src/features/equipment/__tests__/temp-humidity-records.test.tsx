import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { TempHumidityRecordFormPreview } from '../tempHumidityPreview'
import { applyDetectedEquipmentCode, applyLookupEquipment, buildTempHumidityListParams, emptyTempHumidityFilters, equipmentLookupErrorText } from '../tempHumidityPageState'
import { tempHumiditySchema } from '../tempHumiditySchema'

describe('TempHumidityRecordFormPreview', () => {
  it('renders lookup equipment details and placement fields in the add form preview', () => {
    const html = renderToStaticMarkup(
      <TempHumidityRecordFormPreview
        defaultPerson="Alice"
        currentEquipNo="XPD-S-041"
        lookupEquipment={{
          id: 1,
          equipment_no: 'XPD-S-041',
          name: '恒温恒湿箱',
          model: 'A1',
          status: 'active',
          calibration_date: '2026-01-01',
          next_calibration_date: '2027-01-01',
          location_site: '曹一天宏',
          location_room: '样品室',
        }}
      />,
    )

    expect(html).toContain('XPD-S-041')
    expect(html).toContain('恒温恒湿箱')
    expect(html).toContain('曹一天宏')
    expect(html).toContain('样品室')
    expect(html).toContain('2027-01-01')
  })

  it('keeps an unknown scanned equipment code visible when lookup returns an error', () => {
    const html = renderToStaticMarkup(
      <TempHumidityRecordFormPreview
        defaultPerson="Alice"
        currentEquipNo="UNKNOWN-SENSOR"
        lookupEquipment={null}
      />,
    )

    expect(html).toContain('UNKNOWN-SENSOR')
  })

  it('rejects non-numeric temperature and humidity input before submit', () => {
    const base = {
      location_site: '曹一天宏',
      location_room: '样品室',
      equip_no: 'XPD-S-041',
      record_person: 'Alice',
      remark: '',
      record_time: '2026-06-13T09:30',
    }

    expect(tempHumiditySchema.safeParse({ ...base, temperature: 'abc', humidity: '65.0' }).success).toBe(false)
    expect(tempHumiditySchema.safeParse({ ...base, temperature: '25.3', humidity: 'bad' }).success).toBe(false)
    expect(tempHumiditySchema.safeParse({ ...base, temperature: '25.3', humidity: '65.0' }).success).toBe(true)
  })

  it('builds list params with range filters and pagination while removing blanks', () => {
    expect(
      buildTempHumidityListParams(
        {
          ...emptyTempHumidityFilters,
          search: 'XPD-S-041',
          record_time_from: '2026-06-01',
          temperature_min: '20',
          humidity_max: '80',
        },
        2,
        50,
      ),
    ).toEqual({
      search: 'XPD-S-041',
      record_time_from: '2026-06-01',
      temperature_min: '20',
      humidity_max: '80',
      page: 2,
      per_page: 50,
    })
  })

  it('keeps detected unknown equipment code before lookup result is known', () => {
    expect(
      applyDetectedEquipmentCode(
        {
          location_site: '',
          location_room: '',
          equip_no: '',
          record_person: 'Alice',
          remark: '',
          record_time: '',
        },
        'UNKNOWN-SENSOR',
      ).equip_no,
    ).toBe('UNKNOWN-SENSOR')
  })

  it('auto-fills placement on lookup success but does not overwrite edit snapshots', () => {
    const values = {
      location_site: '旧场所',
      location_room: '旧房间',
      equip_no: 'OLD',
      record_person: 'Alice',
      remark: '',
      record_time: '',
    }
    const equipment = {
      id: 1,
      equipment_no: 'XPD-S-041',
      name: '恒温恒湿箱',
      status: 'active',
      location_site: '曹一天宏',
      location_room: '样品室',
    }

    expect(applyLookupEquipment(values, equipment, false)).toMatchObject({
      equip_no: 'XPD-S-041',
      location_site: '曹一天宏',
      location_room: '样品室',
    })
    expect(applyLookupEquipment(values, equipment, true)).toBe(values)
  })

  it('preserves manually entered placement when lookup has no placement fields', () => {
    const values = {
      location_site: '手填场所',
      location_room: '手填房间',
      equip_no: 'UNKNOWN',
      record_person: 'Alice',
      remark: '',
      record_time: '',
    }

    expect(
      applyLookupEquipment(
        values,
        {
          id: 1,
          equipment_no: 'XPD-S-045',
          name: '温湿度记录仪',
          status: 'active',
          location_site: null,
          location_room: null,
        },
        false,
      ),
    ).toMatchObject({
      equip_no: 'XPD-S-045',
      location_site: '手填场所',
      location_room: '手填房间',
    })
  })

  it('uses a local not-found message for unknown equipment lookup', () => {
    expect(equipmentLookupErrorText({ response: { status: 404 } })).toBe('未找到设备')
    expect(equipmentLookupErrorText({ response: { status: 500 } })).toBeNull()
  })
})
