import { describe, expect, it } from 'vitest'
import { errorMessage } from '../utils'

describe('system utils', () => {
  it('uses plain Error messages before falling back to generic copy', () => {
    expect(errorMessage(new Error('请选择委托单'), 'Unable to receive samples')).toBe('请选择委托单')
  })
})
