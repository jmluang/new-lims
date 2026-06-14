import { useEffect, useId, useRef, useState } from 'react'
import { Button, Panel } from '../../features/system/shared'
import { inputClass } from '../../features/system/utils'
import { completeDetectedScan, normalizeScanValue, stopQrScannerIfRunning, type QrScannerInstance } from './qrScanner'

type QrScannerPanelProps = {
  title: string
  placeholder: string
  onDetected: (text: string) => void
}

export function QrScannerPanel({ title, placeholder, onDetected }: QrScannerPanelProps) {
  const [manualValue, setManualValue] = useState('')
  const [cameraEnabled, setCameraEnabled] = useState(false)
  const readerId = `qr-reader-${useId().replace(/:/g, '')}`

  function submitManual() {
    const text = normalizeScanValue(manualValue)

    if (text !== null) {
      onDetected(text)
      setManualValue('')
    }
  }

  function handleCameraDetected(text: string) {
    completeDetectedScan(text, onDetected, () => setCameraEnabled(false))
  }

  return (
    <Panel title={title}>
      <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
        <input
          className={inputClass}
          value={manualValue}
          onChange={(event) => setManualValue(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault()
              submitManual()
            }
          }}
          placeholder={placeholder}
        />
        <Button className="w-full sm:w-auto" variant="secondary" onClick={submitManual}>
          添加
        </Button>
      </div>
      <div className="mt-3 flex gap-2">
        <Button className="w-full sm:w-auto" variant="secondary" onClick={() => setCameraEnabled((value) => !value)}>
          {cameraEnabled ? '关闭扫码' : '打开扫码'}
        </Button>
      </div>
      {cameraEnabled ? <QrCamera readerId={readerId} onDetected={handleCameraDetected} /> : null}
    </Panel>
  )
}

type QrCameraProps = {
  readerId: string
  onDetected: (text: string) => void
}

/**
 * Camera scanning is an input convenience only. The library is loaded lazily so
 * the module stays safe to import in non-browser environments, and any camera
 * failure surfaces a hint without blocking the manual-entry path above.
 */
function QrCamera({ readerId, onDetected }: QrCameraProps) {
  const [error, setError] = useState<string | null>(null)
  const onDetectedRef = useRef(onDetected)

  useEffect(() => {
    onDetectedRef.current = onDetected
  }, [onDetected])

  useEffect(() => {
    let cancelled = false
    let scanner: QrScannerInstance | null = null
    let scannerStarted = false
    let startSettled = false
    let cleanupRequested = false

    function cleanupScanner() {
      if (!scanner || !startSettled) {
        return
      }

      void stopQrScannerIfRunning(scanner, scannerStarted).catch(() => {})
    }

    async function startScanner() {
      try {
        const { Html5Qrcode } = await import('html5-qrcode')
        if (cancelled) {
          return
        }

        const instance = new Html5Qrcode(readerId)
        scanner = instance

        await instance.start(
          { facingMode: 'environment' },
          { fps: 10, qrbox: { width: 220, height: 220 } },
          (decodedText: string) => onDetectedRef.current(decodedText),
          () => {},
        )

        scannerStarted = true
      } catch (startError: unknown) {
        if (!cancelled) {
          setError(startError instanceof Error ? startError.message : '摄像头不可用，请使用手动输入')
        }
      } finally {
        startSettled = true

        if (cleanupRequested) {
          cleanupScanner()
        }
      }
    }

    void startScanner()

    return () => {
      cancelled = true
      cleanupRequested = true

      cleanupScanner()
    }
  }, [readerId])

  return (
    <div className="mt-3">
      <div id={readerId} className="overflow-hidden rounded-md border border-emerald-900/10" />
      {error ? <p className="mt-2 text-xs text-amber-600">{error}</p> : null}
    </div>
  )
}
