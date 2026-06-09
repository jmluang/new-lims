import { describe, expect, it } from 'vitest'
import type { ReactElement } from 'react'
import { EquipmentLabelPrintStyles } from '../EquipmentLabelPrintArea'
import { equipmentLabelSpec } from '../equipmentLabelSpec'

describe('equipment label print spec', () => {
  it('uses the first-release 40mm x 60mm label contract with XPD_LIMS footer', () => {
    expect(equipmentLabelSpec).toEqual({
      widthMm: 40,
      heightMm: 60,
      footer: 'XPD_LIMS',
    })
  })

  it('removes the application layout from the print flow before rendering labels', () => {
    const styleElement = EquipmentLabelPrintStyles() as ReactElement<{ children: string }>

    expect(styleElement.props.children).toMatch(/body\s*>\s*:not\(\.label-print-area\)\s*\{[^}]*display:\s*none !important;/)
  })
})
