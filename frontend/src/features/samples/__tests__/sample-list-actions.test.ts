import { describe, expect, it } from 'vitest'
import { visibleSampleListActions, type SampleListActionSubject } from '../sampleListActions'

function sample(overrides: Partial<SampleListActionSubject> = {}): SampleListActionSubject {
  return {
    status: 'pending',
    current_holder: '样品室',
    ...overrides,
  }
}

describe('visibleSampleListActions', () => {
  it('matches example sample_manage list actions for active samples', () => {
    expect(visibleSampleListActions(sample({ status: 'pending', current_holder: '样品室' }))).toEqual(['lend', 'return_client'])
    expect(visibleSampleListActions(sample({ status: 'testing', current_holder: 'Alice' }))).toEqual(['transfer', 'return_room', 'return_client'])
    expect(visibleSampleListActions(sample({ status: 'outsourced', current_holder: '分包实验室' }))).toEqual(['receive_back', 'return_client'])
    expect(visibleSampleListActions(sample({ status: 'completed', current_holder: '样品室' }))).toEqual(['return_client'])
    expect(visibleSampleListActions(sample({ status: 'retained', current_holder: '样品室' }))).toEqual(['return_client'])
  })

  it('hides customer return for terminal returned and scrapped samples', () => {
    expect(visibleSampleListActions(sample({ status: 'returned', current_holder: '客户' }))).toEqual([])
    expect(visibleSampleListActions(sample({ status: 'scrapped', current_holder: '样品室' }))).toEqual([])
  })
})
