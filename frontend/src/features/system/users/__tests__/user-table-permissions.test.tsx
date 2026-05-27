import { describe, expect, it } from 'vitest'
import { canUpdateUserPhone } from '../userPermissions'

describe('user table permissions', () => {
  it('treats system user phone as read-only without field update permission', () => {
    expect(canUpdateUserPhone({
      resources: {
        'system.users': {
          fields: {
            phone: {
              update: false,
            },
          },
        },
      },
    })).toBe(false)
  })

  it('allows phone editing when field update permission is granted', () => {
    expect(canUpdateUserPhone({
      resources: {
        'system.users': {
          fields: {
            phone: {
              update: true,
            },
          },
        },
      },
    })).toBe(true)
  })
})
