import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import { QrScannerPanel } from '../QrScannerPanel'
import { normalizeScanValue, stopQrScannerIfRunning } from '../qrScanner'

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

describe('stopQrScannerIfRunning', () => {
  it('does not stop the scanner when camera startup failed before running', async () => {
    const scanner = {
      stop: vi.fn<() => Promise<void>>(),
      clear: vi.fn<() => void>(),
    }

    await stopQrScannerIfRunning(scanner, false)

    expect(scanner.stop).not.toHaveBeenCalled()
    expect(scanner.clear).toHaveBeenCalledOnce()
  })

  it('stops the scanner before clearing it after camera startup succeeds', async () => {
    const scanner = {
      stop: vi.fn<() => Promise<void>>().mockResolvedValue(undefined),
      clear: vi.fn<() => void>(),
    }

    await stopQrScannerIfRunning(scanner, true)

    expect(scanner.stop).toHaveBeenCalledOnce()
    expect(scanner.clear).toHaveBeenCalledOnce()
  })
})
