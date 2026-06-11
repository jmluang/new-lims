import { describe, expect, it } from 'vitest'
import type { ReactElement } from 'react'
import { SampleLabelPrintStyles } from '../SampleLabelPrintArea'
import { sampleLabelSpec } from '../sampleLabelSpec'

describe('sample label print spec', () => {
  it('prints 40mm x 60mm labels containing only sample number and QR code', () => {
    expect(sampleLabelSpec).toEqual({
      widthMm: 40,
      heightMm: 60,
    })
  })

  it('uses the same print isolation contract as equipment labels', () => {
    const styleElement = SampleLabelPrintStyles() as ReactElement<{ children: string }>

    expect(styleElement.props.children).toMatch(/body\s*>\s*:not\(\.sample-label-print-area\)\s*\{[^}]*display:\s*none !important;/)
  })
})
