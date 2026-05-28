import { describe, expect, it } from 'vitest'
import * as zh from '../zh'

describe('Chinese text helpers', () => {
  it('does not expose a global DOM text mutator', () => {
    expect('installChineseUiTranslations' in zh).toBe(false)
  })
})
