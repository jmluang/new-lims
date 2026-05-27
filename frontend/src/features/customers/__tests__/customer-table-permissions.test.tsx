import { describe, expect, it } from 'vitest'
import { visibleCustomerColumns } from '../customerColumns'

describe('customer table permissions', () => {
  it('removes hidden sensitive columns from the customer table', () => {
    const columns = visibleCustomerColumns({
      phone: { hidden: true, read: false },
      email: { hidden: true, read: false },
      credit_code: { hidden: false, read: true },
    }).map((column) => column.key)

    expect(columns).toContain('name')
    expect(columns).toContain('credit_code')
    expect(columns).not.toContain('phone')
    expect(columns).not.toContain('email')
  })

  it('adds sensitive columns when field read permission is restored', () => {
    const columns = visibleCustomerColumns({
      phone: { hidden: false, read: true },
      email: { hidden: false, read: true },
    }).map((column) => column.key)

    expect(columns).toContain('phone')
    expect(columns).toContain('email')
  })
})
