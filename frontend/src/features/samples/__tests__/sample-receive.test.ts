import { describe, expect, it } from 'vitest'
import { buildReceiveSamplesPayload, acceptedReceiveRowCount, normalizeReceivePayload, receiveSamplesSchema } from '../sampleSchema'

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
          appearance_check: '外观完整',
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
    expect(payload.samples).toMatchObject([{ reject_reason: null }, { reject_reason: '外观破损' }])
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
})
