import { describe, expect, it } from 'vitest'
import { availableSampleFlowActions } from '../sampleFlowAvailability'

describe('availableSampleFlowActions', () => {
  it('hides every manual flow action for terminal samples', () => {
    expect(availableSampleFlowActions({ status: 'returned', current_holder: '客户' })).toEqual([])
    expect(availableSampleFlowActions({ status: 'scrapped', current_holder: '样品室' })).toEqual([])
  })

  it('matches backend state rules for active samples', () => {
    expect(availableSampleFlowActions({ status: 'pending', current_holder: '样品室' })).toEqual([
      'lend',
      'send_out',
      'return_client',
      'scrap',
      'position_change',
    ])
    expect(availableSampleFlowActions({ status: 'testing', current_holder: '检测员A' })).toEqual([
      'transfer',
      'return_room',
      'send_out',
      'return_client',
      'scrap',
      'position_change',
    ])
    expect(availableSampleFlowActions({ status: 'outsourced', current_holder: '分包实验室' })).toEqual([
      'receive_back',
      'send_out',
      'return_client',
      'scrap',
      'position_change',
    ])
  })
})
