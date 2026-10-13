import { describe, expect, it } from 'vitest'
import { availableSampleFlowActions } from '../sampleFlowAvailability'

describe('customer return precondition', () => {
  it('allows a returned outsourced sample to be lent for internal testing', () => {
    expect(availableSampleFlowActions({ status: 'outsource_returned', current_holder: '样品室' })).toContain('lend')
  })
  it('requires a testing sample to return to the sample room before customer return', () => {
    const actions = availableSampleFlowActions({ status: 'testing', current_holder: 'Inspector' })
    expect(actions).toContain('return_room')
    expect(actions).not.toContain('return_client')
  })
})
