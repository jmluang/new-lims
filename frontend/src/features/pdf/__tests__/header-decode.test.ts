import { describe, expect, it } from 'vitest'
import { decodeHeaderValue } from '../api'

/**
 * The signed file comes back as binary, so its report number rides on a
 * response header — and HTTP header values are ISO-8859-1. A Chinese report
 * number sent raw reached the operator as mojibake, which looked like a broken
 * report rather than a transport bug.
 */
describe('response header decoding', () => {
  it('restores a percent-encoded Chinese report number', () => {
    expect(decodeHeaderValue(encodeURIComponent('ZS-2026-0001 面板灯'))).toBe('ZS-2026-0001 面板灯')
  })

  it('leaves a plain ascii value alone', () => {
    expect(decodeHeaderValue('ZS-2026-0001')).toBe('ZS-2026-0001')
  })

  it('returns null for an empty header rather than an empty label', () => {
    expect(decodeHeaderValue('')).toBeNull()
    expect(decodeHeaderValue(undefined)).toBeNull()
  })

  it('falls back to the raw value when it is not valid encoding', () => {
    // A stray percent must not throw and blank out the whole result card.
    expect(decodeHeaderValue('100% pass')).toBe('100% pass')
  })
})
