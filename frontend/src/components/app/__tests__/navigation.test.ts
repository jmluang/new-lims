import { describe, expect, it } from 'vitest'
import { isActivePath } from '../navigation'

const routes = ['/', '/system', '/system/groups', '/equipment', '/equipment/locations', '/equipment/labels', '/equipment/temp-humidity']

describe('navigation active path matching', () => {
  it('does not keep the first sibling highlighted when a more specific route is active', () => {
    expect(isActivePath('/equipment/labels', '/equipment', routes)).toBe(false)
    expect(isActivePath('/equipment/labels', '/equipment/labels', routes)).toBe(true)
    expect(isActivePath('/system/groups', '/system', routes)).toBe(false)
    expect(isActivePath('/system/groups', '/system/groups', routes)).toBe(true)
  })

  it('keeps parent list routes active for their own create and edit pages', () => {
    expect(isActivePath('/equipment/new', '/equipment', routes)).toBe(true)
    expect(isActivePath('/equipment/42/edit', '/equipment', routes)).toBe(true)
  })
})
