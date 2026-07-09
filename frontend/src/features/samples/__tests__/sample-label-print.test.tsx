import { describe, expect, it } from 'vitest'
import type { ReactElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { SampleLabelPrintArea, SampleLabelPrintStyles } from '../SampleLabelPrintArea'
import { sampleLabelSpec } from '../sampleLabelSpec'

describe('sample label print spec', () => {
  it('prints 40mm x 60mm sample labels', () => {
    expect(sampleLabelSpec).toEqual({
      widthMm: 40,
      heightMm: 60,
    })
  })

  it('renders the reference layout fields with a QR code', () => {
    const html = renderToStaticMarkup(
      <SampleLabelPrintArea
        labels={[
          {
            client_company: '中山市样品客户',
            sample_name: '控制器',
            model: 'CTRL-1',
            input_voltage: '220V',
            rated_current: '1.3A',
            rated_frequency: '50Hz',
            power: '300W',
            sample_no: 'SAMPLE-001',
            status: 'pending',
            qr_text: 'SAMPLE-001',
          },
        ]}
      />,
    )

    expect(html).toContain('中山市样品客户')
    expect(html).toContain('名称：控制器')
    expect(html).toContain('型号：CTRL-1')
    expect(html).toContain('电压：220V 电流：1.3A')
    expect(html).toContain('频率：50Hz 功率：300W')
    expect(html).toContain('SAMPLE-001')
    expect(html).toContain('sample-label-qr')
    expect(html).toContain('☑待检')
    expect(html).toContain('□在检')
    expect(html).toContain('□检毕')
    expect(html).toContain('中山市鑫普达检测有限公司')
    expect(html).toContain('height="92"')
    expect(html).toContain('width="92"')
    expect(html).toContain('text-[9px]')
    expect(html).toContain('py-[2mm]')
    expect(html).toContain('gap-[1.13mm]')
  })

  it('uses the same print isolation contract as equipment labels', () => {
    const styleElement = SampleLabelPrintStyles() as ReactElement<{ children: string }>

    expect(styleElement.props.children).toMatch(/body\s*>\s*:not\(\.sample-label-print-area\)\s*\{[^}]*display:\s*none !important;/)
  })
})
