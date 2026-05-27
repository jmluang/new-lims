import { describe, expect, it } from 'vitest'
import { allowsRoute } from '../routePermissions'

describe('route permissions', () => {
  it('allows routes only when the effective permission action is granted', () => {
    const permissions = {
      resources: {
        customers: { actions: { read: true } },
        'system.backups': { actions: { read: false } },
        equipment_labels: { actions: { print: true } },
      },
    }

    expect(allowsRoute(permissions, 'customers', 'read')).toBe(true)
    expect(allowsRoute(permissions, 'system.backups', 'read')).toBe(false)
    expect(allowsRoute(permissions, 'equipment_labels', 'print')).toBe(true)
    expect(allowsRoute(permissions, 'equipment_labels', 'read')).toBe(false)
  })
})
