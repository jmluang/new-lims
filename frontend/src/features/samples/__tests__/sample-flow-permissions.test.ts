import { describe, expect, it } from 'vitest'
import { visibleSampleFlowActions } from '../sampleFlowPermissions'

describe('visibleSampleFlowActions', () => {
  it('hides room returns unless the return-room permission is granted', () => {
    const actions = ['transfer', 'return_room'] as const

    expect(
      visibleSampleFlowActions(actions, {
        resources: {
          sample_flows: {
            actions: {
              create: true,
              return_room: false,
            },
            fields: {},
          },
        },
      }),
    ).toEqual(['transfer'])

    expect(
      visibleSampleFlowActions(actions, {
        resources: {
          sample_flows: {
            actions: {
              create: true,
              return_room: true,
            },
            fields: {},
          },
        },
      }),
    ).toEqual(['transfer', 'return_room'])
  })
})
