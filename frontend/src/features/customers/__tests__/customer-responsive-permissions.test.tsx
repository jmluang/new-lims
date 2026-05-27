import { describe, expect, it } from 'vitest'
import { visibleCustomerColumns, visibleCustomerMobileFields } from '../customerColumns'

describe('customer responsive permission consistency', () => {
  it('uses the same phone field permission metadata for desktop and mobile render paths', () => {
    const fieldPermissions = {
      phone: { hidden: true, read: false },
    }

    expect(visibleCustomerColumns(fieldPermissions).map((column) => column.key)).not.toContain('phone')
    expect(visibleCustomerMobileFields(fieldPermissions).phone).toBe(false)
  })

  it('restores phone visibility on both desktop and mobile render paths', () => {
    const fieldPermissions = {
      phone: { hidden: false, read: true },
    }

    expect(visibleCustomerColumns(fieldPermissions).map((column) => column.key)).toContain('phone')
    expect(visibleCustomerMobileFields(fieldPermissions).phone).toBe(true)
  })
})
