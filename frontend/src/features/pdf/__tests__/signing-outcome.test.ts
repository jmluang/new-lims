import { describe, expect, it } from 'vitest'
import { signedText, signedTitle } from '../signingOutcome'

describe('signed copy', () => {
  it('does not send the last signer looking for a next one', () => {
    expect(signedText(true)).not.toContain('下一位')
    expect(signedText(true)).toContain('已发布')
    expect(signedTitle(true)).toBe('全部签署已完成')
  })

  it('still hands off when signers remain', () => {
    expect(signedText(false)).toContain('下一位')
    expect(signedTitle(false)).toBe('签名已完成')
  })
})
