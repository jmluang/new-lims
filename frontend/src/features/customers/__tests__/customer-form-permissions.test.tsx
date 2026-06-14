import { describe, expect, it } from 'vitest'
import { filterForbiddenCustomerFields } from '../customerPermissions'
import { customerSchema } from '../customerSchema'

describe('customer form permissions', () => {
  it('excludes fields without update permission from submit payloads', () => {
    const payload = filterForbiddenCustomerFields(
      {
        name: 'Acme Lab',
        credit_code: '91330000123456789X',
        phone: '13800000000',
        email: 'acme@example.test',
        address: 'Hangzhou',
        remark: 'VIP',
        status: 'active',
      },
      {
        credit_code: { update: false },
        phone: { update: false },
        email: { update: true },
      },
    )

    expect(payload).toMatchObject({
      name: 'Acme Lab',
      email: 'acme@example.test',
    })
    expect(payload.credit_code).toBeUndefined()
    expect(payload.phone).toBeUndefined()
  })

  it('strips removed classification fields from customer form values', () => {
    const payload = customerSchema.parse({
      name: 'Acme Lab',
      type: 'enterprise',
      level: 'a',
      source: 'referral',
      industry: 'testing',
      status: 'active',
    })

    expect(payload).not.toHaveProperty('type')
    expect(payload).not.toHaveProperty('level')
    expect(payload).not.toHaveProperty('source')
    expect(payload).not.toHaveProperty('industry')
  })
})
