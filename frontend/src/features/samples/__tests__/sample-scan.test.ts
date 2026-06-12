import { describe, expect, it } from 'vitest'
import { buildSampleScanFlowPayload, sampleScanActionRequiresHolder, SampleScanFlowValidationError } from '../sampleScanSchema'

describe('buildSampleScanFlowPayload', () => {
  it('builds a scan flow payload with required location for return_room', () => {
    expect(
      buildSampleScanFlowPayload({
        action_type: 'return_room',
        location_to: '样品室',
        remark: 'return',
      }),
    ).toEqual({
      action_type: 'return_room',
      location_to: '样品室',
      remark: 'return',
    })
  })

  it('keeps the holder when an action targets a person', () => {
    expect(
      buildSampleScanFlowPayload({
        action_type: 'lend',
        holder_to: 'Alice',
        location_to: '实验区A',
      }),
    ).toEqual({
      action_type: 'lend',
      holder_to: 'Alice',
      location_to: '实验区A',
    })
  })

  it('rejects a missing location', () => {
    expect(() => buildSampleScanFlowPayload({ action_type: 'return_room', location_to: '' })).toThrow(SampleScanFlowValidationError)
  })

  it('requires a holder for lend and transfer but not for room returns', () => {
    expect(sampleScanActionRequiresHolder('lend')).toBe(true)
    expect(sampleScanActionRequiresHolder('transfer')).toBe(true)
    expect(sampleScanActionRequiresHolder('return_room')).toBe(false)
    expect(sampleScanActionRequiresHolder('receive_back')).toBe(false)
    expect(() => buildSampleScanFlowPayload({ action_type: 'lend', location_to: '实验区A' })).toThrow(SampleScanFlowValidationError)
  })
})
