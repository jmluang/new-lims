import { describe, expect, it } from 'vitest'
import type { EquipmentLocation } from '../../equipment/EquipmentListPage'
import { buildLocationSelection, changeLocationSelection, findLocationSelectionIdsByLabel } from '../sampleScanLocations'
import { buildSampleScanFlowPayload, sampleScanActionDefaults, sampleScanActionRequiresHolder, SampleScanFlowValidationError } from '../sampleScanSchema'

const locationTree: EquipmentLocation[] = [
  {
    id: 1,
    name: '总部',
    code: 'HQ',
    status: 'active',
    children: [
      {
        id: 2,
        name: '一楼',
        code: 'F1',
        status: 'active',
        children: [
          { id: 3, name: '留样室', code: 'ROOM', status: 'active' },
          { id: 4, name: '停用库位', code: 'OFF', status: 'disabled' },
        ],
      },
      { id: 5, name: '停用楼层', code: 'DISABLED', status: 'disabled', children: [{ id: 6, name: '隐藏房间', code: 'HIDDEN', status: 'active' }] },
    ],
  },
  { id: 7, name: '外场', code: 'FIELD', status: 'active' },
]

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

  it('defaults the target holder to the current user when the action targets a person', () => {
    expect(sampleScanActionDefaults('lend', '总部 / 一楼', 'Sample Manager')).toMatchObject({
      action_type: 'lend',
      holder_to: 'Sample Manager',
      location_to: '总部 / 一楼',
    })
    expect(sampleScanActionDefaults('return_room', '总部 / 一楼', 'Sample Manager').holder_to).toBe('')
  })
})

describe('sample scan location selection', () => {
  it('builds linked location levels and submits the selected location path', () => {
    const firstLevel = buildLocationSelection(locationTree, [])

    expect(firstLevel.levels).toHaveLength(1)
    expect(firstLevel.levels[0].options.map((option) => option.name)).toEqual(['总部', '外场'])

    const secondLevel = changeLocationSelection(locationTree, [], 0, '1')
    expect(secondLevel.levels).toHaveLength(2)
    expect(secondLevel.levels[1].options.map((option) => option.name)).toEqual(['一楼'])

    const thirdLevel = changeLocationSelection(locationTree, ['1'], 1, '2')
    expect(thirdLevel.levels).toHaveLength(3)
    expect(thirdLevel.levels[2].options.map((option) => option.name)).toEqual(['留样室'])

    const selected = changeLocationSelection(locationTree, ['1', '2'], 2, '3')
    expect(selected.value).toBe('总部 / 一楼 / 留样室')
  })

  it('excludes disabled location branches from linked choices', () => {
    const selection = buildLocationSelection(locationTree, ['1'])

    expect(selection.levels[1].options.map((option) => option.name)).not.toContain('停用楼层')
    expect(findLocationSelectionIdsByLabel(locationTree, '隐藏房间')).toEqual([])
  })

  it('finds a location branch by full path or leaf name', () => {
    expect(findLocationSelectionIdsByLabel(locationTree, '总部 / 一楼 / 留样室')).toEqual(['1', '2', '3'])
    expect(findLocationSelectionIdsByLabel(locationTree, '留样室')).toEqual(['1', '2', '3'])
  })
})
