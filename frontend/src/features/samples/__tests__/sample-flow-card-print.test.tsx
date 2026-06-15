import { renderToStaticMarkup } from 'react-dom/server'
import type { ReactElement } from 'react'
import { describe, expect, it } from 'vitest'
import { SampleFlowCardPrintArea, SampleFlowCardPrintStyles } from '../SampleFlowCardPrintArea'

describe('sample flow ledger print', () => {
  it('renders the printable sample flow ledger fields', () => {
    const html = renderToStaticMarkup(
      <SampleFlowCardPrintArea
        card={{
          sample: { client_company: '中山市客户公司', sample_no: 'S-001', sample_name: '灯具', model: 'LD-100', status: 'pending' },
          flows: [
            {
              id: 1,
              action_type: 'receive',
              action_time: '2026-06-12 08:30:00',
              holder_from: '客户',
              holder_to: '样品室',
              location_from: '前台',
              location_to: '样品室',
            },
          ],
        }}
      />,
    )

    expect(html).toContain('样品流转记录流水单')
    for (const label of ['客户名称', '样品名称', '样品型号', '样品编号', '样品状态', '时间', '流转类型', '原位置', '现位置', '原持有人', '持有人']) {
      expect(html).toContain(label)
    }
    expect(html).toContain('中山市客户公司')
    expect(html).toContain('S-001')
    expect(html).toContain('LD-100')
    expect(html).toContain('待检')
    expect(html).toContain('2026-06-12 08:30:00')
    expect(html).not.toContain('T00:00:00.000Z')
    expect(html).toContain('接收')
    expect(html).toContain('前台')
    expect(html).toContain('样品室')
    expect(html).toContain('客户')
  })

  it('hides the print area on screen and isolates it for printing', () => {
    const styleElement = SampleFlowCardPrintStyles() as ReactElement<{ children: string }>

    expect(styleElement.props.children).toMatch(/body\s*>\s*:not\(\.sample-flow-card-print-area\)\s*\{[^}]*display:\s*none !important;/)
    expect(styleElement.props.children).toMatch(/\.sample-flow-card-print-area\s*\{\s*display:\s*none;/)
    expect(styleElement.props.children).toContain('size: A4 landscape;')
  })
})
