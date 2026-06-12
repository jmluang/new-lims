import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { QrScannerPanel } from '../QrScannerPanel'
import { normalizeScanValue } from '../qrScanner'

describe('normalizeScanValue', () => {
  it('trims surrounding whitespace and returns the code', () => {
    expect(normalizeScanValue('  S-001  ')).toBe('S-001')
  })

  it('returns null for blank input so empty submits are ignored', () => {
    expect(normalizeScanValue('   ')).toBeNull()
    expect(normalizeScanValue('')).toBeNull()
  })
})

describe('QrScannerPanel', () => {
  it('renders manual entry with the placeholder and keeps the camera off by default', () => {
    const html = renderToStaticMarkup(
      <QrScannerPanel title="扫码流转" placeholder="请输入样品编号" onDetected={() => {}} />,
    )

    expect(html).toContain('请输入样品编号')
    // Camera reader element is only mounted after the user opens the camera.
    expect(html).not.toContain('qr-reader-')
    expect(html).toContain('打开扫码')
  })
})
