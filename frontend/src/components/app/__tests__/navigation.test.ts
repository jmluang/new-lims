import { describe, expect, it } from 'vitest'
import { isActivePath, visibleNavGroups } from '../navigation'

const routes = [
  '/',
  '/system',
  '/system/departments',
  '/system/groups',
  '/equipment',
  '/equipment/locations',
  '/equipment/systems',
  '/equipment/labels',
  '/equipment/temp-humidity',
  '/samples',
  '/samples/flow-records',
  '/samples/scan',
]

describe('navigation active path matching', () => {
  it('does not keep the first sibling highlighted when a more specific route is active', () => {
    expect(isActivePath('/equipment/labels', '/equipment', routes)).toBe(false)
    expect(isActivePath('/equipment/labels', '/equipment/labels', routes)).toBe(true)
    expect(isActivePath('/equipment/systems', '/equipment', routes)).toBe(false)
    expect(isActivePath('/equipment/systems', '/equipment/systems', routes)).toBe(true)
    expect(isActivePath('/system/departments', '/system', routes)).toBe(false)
    expect(isActivePath('/system/departments', '/system/departments', routes)).toBe(true)
    expect(isActivePath('/system/groups', '/system', routes)).toBe(false)
    expect(isActivePath('/system/groups', '/system/groups', routes)).toBe(true)
  })

  it('keeps parent list routes active for their own create and edit pages', () => {
    expect(isActivePath('/equipment/new', '/equipment', routes)).toBe(true)
    expect(isActivePath('/equipment/42/edit', '/equipment', routes)).toBe(true)
    expect(isActivePath('/equipment/systems/new', '/equipment/systems', routes)).toBe(true)
    expect(isActivePath('/equipment/systems/42/edit', '/equipment/systems', routes)).toBe(true)
  })

  it('hides navigation items without effective read permissions', () => {
    const labels = visibleNavGroups({
      resources: {
        equipment: { actions: { read: true } },
        samples: { actions: { read: true } },
        system: { actions: { read: true } },
      },
    }).flatMap((group) => group.items.map((item) => item.label))

    expect(labels).toContain('设备台账')
    expect(labels).toContain('样品信息')
    expect(labels).not.toContain('位置名称')
    expect(labels).not.toContain('部门管理')
    expect(labels).not.toContain('角色组')
    expect(labels).not.toContain('审计日志')
  })

  it('hides the dashboard when the user has no effective permissions', () => {
    const groups = visibleNavGroups({ resources: {} })

    expect(groups).toEqual([])
  })

  it('shows handwritten signing to either signers or workflow planners', () => {
    const resourceSets: Array<Record<string, { actions: Record<string, boolean> }>> = [
      { 'pdf.request': { actions: { read: true } } },
      { 'pdf.workflow': { actions: { create: true } } },
    ]

    for (const resources of resourceSets) {
      const labels = visibleNavGroups({ resources }).flatMap((group) => group.items.map((item) => item.label))
      expect(labels).toContain('手写数字签名')
    }
  })

  it('shows department management inside the system management navigation group', () => {
    const groups = visibleNavGroups({
      resources: {
        'system.departments': { actions: { read: true } },
      },
    })

    const systemGroup = groups.find((group) => group.label === '系统管理')

    expect(systemGroup?.items).toEqual([expect.objectContaining({ label: '部门管理', to: '/system/departments' })])
  })

  it('shows location names inside the system management navigation group', () => {
    const groups = visibleNavGroups({
      resources: {
        equipment_locations: { actions: { read: true } },
      },
    })

    const systemGroup = groups.find((group) => group.label === '系统管理')

    expect(systemGroup?.items).toEqual([expect.objectContaining({ label: '位置名称', to: '/equipment/locations' })])
  })

  it('does not expose the removed data dictionary navigation item', () => {
    const labels = visibleNavGroups({
      resources: {
        'system.dictionaries': { actions: { read: true } },
      },
    }).flatMap((group) => group.items.map((item) => item.label))

    expect(labels).not.toContain('数据字典')
  })

  it('shows sample flow records in the business navigation when flow read is granted', () => {
    const groups = visibleNavGroups({
      resources: {
        sample_flows: { actions: { read: true } },
      },
    })

    const businessGroup = groups.find((group) => group.label === '业务管理')

    expect(businessGroup?.items).toEqual([expect.objectContaining({ label: '样品流转记录', to: '/samples/flow-records' })])
    expect(isActivePath('/samples/flow-records', '/samples', routes)).toBe(false)
    expect(isActivePath('/samples/flow-records', '/samples/flow-records', routes)).toBe(true)
  })
})
