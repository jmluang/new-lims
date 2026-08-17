import { describe, expect, it } from 'vitest'
import { fieldAspectRatio } from '../fieldAspect'

const a4 = { width: 595, height: 842 }

describe('fieldAspectRatio', () => {
  // Normalized rects are fractions of page width and height separately, so the
  // page's own proportions are half the answer. Reading width/height alone
  // would call a 0.16 x 0.055 field 2.9:1 when it is really 2.06:1.
  it('accounts for the page being taller than it is wide', () => {
    expect(fieldAspectRatio({ width: '0.160000', height: '0.055000' }, a4)).toBeCloseTo(2.06, 2)
  })

  it('matches the field under test', () => {
    // lims_inspector_g1: 0.2926 x 0.1148 on A4.
    expect(fieldAspectRatio({ width: '0.292647', height: '0.114767' }, a4)).toBeCloseTo(1.8, 1)
  })

  it('falls back rather than dividing by zero', () => {
    expect(fieldAspectRatio({ width: '0.16', height: '0' }, a4)).toBe(900 / 320)
    expect(fieldAspectRatio(null, a4)).toBe(900 / 320)
  })

  it('assumes A4 until the page has been measured', () => {
    expect(fieldAspectRatio({ width: '0.160000', height: '0.055000' }, null)).toBeCloseTo(2.06, 2)
  })
})
