import { renderToStaticMarkup } from 'react-dom/server'
import type { ReactElement } from 'react'
import { describe, expect, it } from 'vitest'
import { SampleFlowCardPrintArea, SampleFlowCardPrintStyles } from '../SampleFlowCardPrintArea'

describe('sample flow card print', () => {
  it('renders printable sample profile and flow timeline', () => {
    const html = renderToStaticMarkup(
      <SampleFlowCardPrintArea
        card={{
          sample: { sample_no: 'S-001', sample_name: '灯具', status: 'pending', current_holder: '样品室', current_location: '样品室' },
          flows: [{ id: 1, action_type: 'receive', action_time: '2026-06-12T00:00:00.000Z', holder_to: '样品室', location_to: '样品室', action_by_name: '张三' }],
        }}
      />,
    )

    expect(html).toContain('S-001')
    // action_type is localized through zhText: receive -> 接收
    expect(html).toContain('接收')
    expect(html).toContain('张三')
  })

  it('hides the print area on screen and isolates it for printing', () => {
    const styleElement = SampleFlowCardPrintStyles() as ReactElement<{ children: string }>

    expect(styleElement.props.children).toMatch(/body\s*>\s*:not\(\.sample-flow-card-print-area\)\s*\{[^}]*display:\s*none !important;/)
    expect(styleElement.props.children).toMatch(/\.sample-flow-card-print-area\s*\{\s*display:\s*none;/)
  })
})
