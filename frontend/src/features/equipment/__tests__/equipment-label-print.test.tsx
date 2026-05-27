import { describe, expect, it } from 'vitest'
import { equipmentLabelSpec } from '../equipmentLabelSpec'

describe('equipment label print spec', () => {
  it('uses the first-release 40mm x 60mm label contract with XPD_LIMS footer', () => {
    expect(equipmentLabelSpec).toEqual({
      widthMm: 40,
      heightMm: 60,
      footer: 'XPD_LIMS',
    })
  })
})
