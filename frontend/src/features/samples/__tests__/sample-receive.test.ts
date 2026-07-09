import { describe, expect, it } from 'vitest'
import {
  buildReceiveSamplesPayload,
  acceptedReceiveRowCount,
  defaultReceiveLocation,
  normalizeReceivePayload,
  receiveSamplesSchema,
  sampleReceiveOrderPermissions,
} from '../sampleSchema'

describe('sample receive form', () => {
  it('requires a test order, current location and at least one row', () => {
    const parsed = receiveSamplesSchema.safeParse({
      test_order_id: 0,
      current_location: '',
      samples: [],
    })

    expect(parsed.success).toBe(false)
  })

  it('keeps rejected rows in the payload but excludes them from accepted counts', () => {
    const payload = normalizeReceivePayload({
      test_order_id: 8,
      received_date: '2026-05-29',
      current_location: '样品室 A1',
      storage_condition: '',
      batch_no: '',
      samples: [
        {
          test_order_sample_id: 1,
          sample_name: '路灯-1',
          specification: 'LD',
          model: 'LD-100',
          input_voltage: '220V',
          rated_current: '1.3A',
          rated_frequency: '50Hz',
          power: '300W',
          appearance_check: '外观完整',
          remark: '客户备注：需保留原包装',
          reject_reason: '',
        },
        {
          test_order_sample_id: 2,
          sample_name: '破损控制器',
          specification: 'CTRL',
          model: 'C-1',
          appearance_check: '外观破损',
          reject_reason: '外观破损',
        },
      ],
    })

    expect(acceptedReceiveRowCount(payload.samples)).toBe(1)
    expect(payload.samples).toMatchObject([
      {
        input_voltage: '220V',
        rated_current: '1.3A',
        rated_frequency: '50Hz',
        power: '300W',
        remark: '客户备注：需保留原包装',
        reject_reason: null,
      },
      { reject_reason: '外观破损' },
    ])
  })

  it('throws validation errors instead of returning a successful empty submit result', () => {
    expect(() =>
      buildReceiveSamplesPayload({
        test_order_id: 0,
        received_date: '2026-05-29',
        current_location: '样品室 A1',
        storage_condition: '',
        batch_no: '',
        samples: [
          {
            test_order_sample_id: null,
            sample_name: '控制器',
          },
        ],
      }),
    ).toThrow('请选择委托单')
  })

  it('documents the permission needed to load receive order options', () => {
    expect(sampleReceiveOrderPermissions).toEqual(['samples.receive'])
  })

  it('defaults receive location to the sample room option and submits the location name', () => {
    const locations = [
      { id: 1, label: '总部', name: '总部' },
      { id: 2, label: '总部 / 样品室', name: '样品室' },
    ]

    expect(defaultReceiveLocation(locations)).toBe('样品室')
    expect(normalizeReceivePayload({ ...baseReceiveValues(), current_location: defaultReceiveLocation(locations) })).toMatchObject({
      current_location: '样品室',
    })
  })
})

function baseReceiveValues() {
  return {
    test_order_id: 8,
    received_date: '2026-05-29',
    current_location: '样品室',
    storage_condition: '',
    batch_no: '',
    samples: [
      {
        test_order_sample_id: 1,
        sample_name: '路灯-1',
        specification: 'LD',
        model: 'LD-100',
        appearance_check: '外观完整',
        reject_reason: '',
      },
    ],
  }
}
