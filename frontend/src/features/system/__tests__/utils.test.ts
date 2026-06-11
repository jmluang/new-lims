import { describe, expect, it } from 'vitest'
import { errorMessage } from '../utils'

describe('system utils', () => {
  it('uses plain Error messages before falling back to generic copy', () => {
    expect(errorMessage(new Error('请选择委托单'), 'Unable to receive samples')).toBe('请选择委托单')
  })

  it('shows the missing permission from 403 API responses', () => {
    expect(
      errorMessage(
        {
          response: {
            status: 403,
            data: {
              message: 'Forbidden',
              permission: 'test_orders.read',
            },
          },
        },
        'Unable to load test orders',
      ),
    ).toBe('没有权限执行该操作：缺少 test_orders.read')
  })
})
