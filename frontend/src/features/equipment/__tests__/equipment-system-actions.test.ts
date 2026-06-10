import { describe, expect, it } from 'vitest'
import { getSystemEquipmentActionState } from '../equipmentSystemActions'

describe('equipment system action state', () => {
  it('keeps equipment actions disabled while equipment assignments are still loading', () => {
    expect(getSystemEquipmentActionState({ equipmentLoaded: false, equipmentIds: [], busy: false })).toEqual({
      canManageEquipment: false,
      canPrintLabels: false,
      manageLabel: '加载设备...',
      printLabel: '加载设备...',
    })
  })

  it('enables management but not printing when a loaded system has no equipment', () => {
    expect(getSystemEquipmentActionState({ equipmentLoaded: true, equipmentIds: [], busy: false })).toEqual({
      canManageEquipment: true,
      canPrintLabels: false,
      manageLabel: '管理设备',
      printLabel: '打印标签',
    })
  })

  it('uses independent busy states for management and printing', () => {
    expect(getSystemEquipmentActionState({ equipmentLoaded: true, equipmentIds: [1, 2], busy: true })).toEqual({
      canManageEquipment: false,
      canPrintLabels: false,
      manageLabel: '加载中...',
      printLabel: '加载中...',
    })
  })
})
