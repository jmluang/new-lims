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
    const html = renderSampleLabel()

    expect(html).toContain('中山市样品客户')
    expect(html).toContain('型号：CTRL-1')
    expect(html).toContain('名称：控制器')
    expect(html).toContain('电压：AC 220-240 V 50/60Hz')
    expect(html).toContain('电流：1.212 A +/-5%')
    expect(html).toContain('频率：50/60 Hz')
    expect(html).toContain('功率：60.00 W max')
    expect(html).toContain('SAMPLE-001')
    expect(html).toContain('sample-label-qr')
    expect(html).toContain('justify-start')
    expect(html).toContain('☑待检')
    expect(html).toContain('□在检')
    expect(html).toContain('□检毕')
    expect(html).toContain('中山市鑫普达检测有限公司')
    expect(html).toContain('height="112"')
    expect(html).toContain('width="112"')
    expect(html).toContain('text-[9px]')
    expect(html).toContain('py-[2mm]')
    expect(html).toContain('w-[30mm]')
    expect(html).toContain('text-[7.2px]')
    expect(html.match(/grid-cols-\[minmax\(0,1fr\)_minmax\(0,1fr\)\]/g)).toHaveLength(2)

  })

  it('keeps name before model and electrical details before the QR code', () => {
    const html = renderSampleLabel()
    const nameIndex = html.indexOf('名称：控制器')
    const modelIndex = html.indexOf('型号：CTRL-1')
    const voltageIndex = html.indexOf('电压：AC 220-240 V 50/60Hz')
    const qrIndex = html.indexOf('sample-label-qr')

    expect(nameIndex).toBeGreaterThan(-1)
    expect(modelIndex).toBeGreaterThan(nameIndex)
    expect(voltageIndex).toBeGreaterThan(modelIndex)
    expect(qrIndex).toBeGreaterThan(voltageIndex)
  })

  it('constrains every electrical value inside its grid column', () => {
    const html = renderSampleLabel()

    expect(html.match(/min-w-0 overflow-hidden text-ellipsis/g)).toHaveLength(4)
  })

  it('uses the same print isolation contract as equipment labels', () => {
    const styleElement = SampleLabelPrintStyles() as ReactElement<{ children: string }>

    expect(styleElement.props.children).toMatch(/body\s*>\s*:not\(\.sample-label-print-area\)\s*\{[^}]*display:\s*none !important;/)
  })
})

function renderSampleLabel() {
  return renderToStaticMarkup(
    <SampleLabelPrintArea
      labels={[
        {
          client_company: '中山市样品客户',
          sample_name: '控制器',
          model: 'CTRL-1',
          input_voltage: 'AC 220-240 V 50/60Hz',
          rated_current: '1.212 A +/-5%',
          rated_frequency: '50/60 Hz',
          power: '60.00 W max',
          sample_no: 'SAMPLE-001',
          status: 'pending',
          qr_text: 'SAMPLE-001',
        },
      ]}
    />,
  )
}
