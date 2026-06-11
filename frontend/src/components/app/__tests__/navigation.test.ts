import { describe, expect, it } from 'vitest'
import { isActivePath, visibleNavGroups } from '../navigation'

const routes = ['/', '/system', '/system/groups', '/equipment', '/equipment/locations', '/equipment/systems', '/equipment/labels', '/equipment/temp-humidity']

describe('navigation active path matching', () => {
  it('does not keep the first sibling highlighted when a more specific route is active', () => {
    expect(isActivePath('/equipment/labels', '/equipment', routes)).toBe(false)
    expect(isActivePath('/equipment/labels', '/equipment/labels', routes)).toBe(true)
    expect(isActivePath('/equipment/systems', '/equipment', routes)).toBe(false)
    expect(isActivePath('/equipment/systems', '/equipment/systems', routes)).toBe(true)
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
    expect(labels).not.toContain('设备位置')
    expect(labels).not.toContain('角色组')
    expect(labels).not.toContain('审计日志')
  })
})
