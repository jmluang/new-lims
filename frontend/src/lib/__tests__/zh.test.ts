import { describe, expect, it } from 'vitest'
import * as zh from '../zh'

describe('Chinese text helpers', () => {
  it('does not expose a global DOM text mutator', () => {
    expect('installChineseUiTranslations' in zh).toBe(false)
  })

  it('translates standard form page titles', () => {
    expect(zh.zhText('Create standard')).toBe('新建标准')
    expect(zh.zhText('Edit standard')).toBe('编辑标准')
  })

  it('translates equipment usage record statuses', () => {
    expect(zh.zhText('using')).toBe('使用中')
    expect(zh.zhText('finished')).toBe('已结束')
  })
})
