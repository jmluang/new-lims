import { describe, expect, it } from 'vitest'
import {
  fieldPermissionName,
  permissionActionLabel,
  permissionFieldLabel,
  permissionResourceLabel,
  resourcePermissionName,
} from '../permissionNames'

describe('permission matrix', () => {
  it('builds stable resource and field permission names', () => {
    expect(resourcePermissionName('customers', 'export')).toBe('customers.export')
    expect(fieldPermissionName('customers', 'phone', 'read')).toBe('customers.field.phone.read')
  })

  it('shows catalog resource, action, and field labels in Chinese', () => {
    expect(permissionResourceLabel('system.groups')).toBe('角色组')
    expect(permissionResourceLabel('equipment_locations')).toBe('设备位置')
    expect(permissionActionLabel('read')).toBe('查看')
    expect(permissionActionLabel('restore')).toBe('恢复')
    expect(permissionFieldLabel('manual_files')).toBe('说明书文件')
  })
})
