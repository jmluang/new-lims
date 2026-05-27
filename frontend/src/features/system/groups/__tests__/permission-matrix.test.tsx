import { describe, expect, it } from 'vitest'
import { fieldPermissionName, resourcePermissionName } from '../permissionNames'

describe('permission matrix', () => {
  it('builds stable resource and field permission names', () => {
    expect(resourcePermissionName('customers', 'export')).toBe('customers.export')
    expect(fieldPermissionName('customers', 'phone', 'read')).toBe('customers.field.phone.read')
  })
})
