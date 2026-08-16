import { describe, expect, it } from 'vitest'
import { allowsAnyRoute, allowsRoute } from '../routePermissions'

describe('route permissions', () => {
  it('allows routes only when the effective permission action is granted', () => {
    const permissions = {
      resources: {
        customers: { actions: { read: true } },
        'system.backups': { actions: { read: false } },
        equipment_labels: { actions: { print: true } },
        equipment_systems: { actions: { read: true, create: false } },
      },
    }

    expect(allowsRoute(permissions, 'customers', 'read')).toBe(true)
    expect(allowsRoute(permissions, 'system.backups', 'read')).toBe(false)
    expect(allowsRoute(permissions, 'equipment_labels', 'print')).toBe(true)
    expect(allowsRoute(permissions, 'equipment_labels', 'read')).toBe(false)
    expect(allowsRoute(permissions, 'equipment_systems', 'read')).toBe(true)
    expect(allowsRoute(permissions, 'equipment_systems', 'create')).toBe(false)
  })

  it('allows a shared workspace when either signer or planner permission is granted', () => {
    const signer = { resources: { 'pdf.request': { actions: { read: true } } } }
    const planner = { resources: { 'pdf.workflow': { actions: { create: true } } } }
    const requirements = [
      { resource: 'pdf.request' },
      { resource: 'pdf.workflow', action: 'create' },
    ]

    expect(allowsAnyRoute(signer, requirements)).toBe(true)
    expect(allowsAnyRoute(planner, requirements)).toBe(true)
    expect(allowsAnyRoute({ resources: {} }, requirements)).toBe(false)
  })
})
