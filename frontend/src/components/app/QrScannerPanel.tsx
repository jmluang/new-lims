import { useEffect, useId, useRef, useState } from 'react'
import { Button, Panel } from '../../features/system/shared'
import { inputClass } from '../../features/system/utils'

export function normalizeScanValue(value: string): string | null {
  const text = value.trim()

  return text === '' ? null : text
}

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

  return (
    <Panel title={title}>
      <div className="grid gap-3 md:grid-cols-[1fr_auto]">
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
        <Button variant="secondary" onClick={submitManual}>
          添加
        </Button>
      </div>
      <div className="mt-3 flex gap-2">
        <Button variant="secondary" onClick={() => setCameraEnabled((value) => !value)}>
          {cameraEnabled ? '关闭扫码' : '打开扫码'}
        </Button>
      </div>
      {cameraEnabled ? <QrCamera readerId={readerId} onDetected={onDetected} /> : null}
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
  onDetectedRef.current = onDetected

  useEffect(() => {
    let cancelled = false
    let scanner: { stop: () => Promise<void>; clear: () => void } | null = null

    void import('html5-qrcode')
      .then(({ Html5Qrcode }) => {
        if (cancelled) {
          return undefined
        }

        const instance = new Html5Qrcode(readerId)
        scanner = instance

        return instance.start(
          { facingMode: 'environment' },
          { fps: 10, qrbox: { width: 220, height: 220 } },
          (decodedText: string) => onDetectedRef.current(decodedText),
          () => {},
        )
      })
      .catch((startError: unknown) => {
        if (!cancelled) {
          setError(startError instanceof Error ? startError.message : '摄像头不可用，请使用手动输入')
        }
      })

    return () => {
      cancelled = true

      if (scanner) {
        void scanner
          .stop()
          .then(() => scanner?.clear())
          .catch(() => {})
      }
    }
  }, [readerId])

  return (
    <div className="mt-3">
      <div id={readerId} className="overflow-hidden rounded-md border border-emerald-900/10" />
      {error ? <p className="mt-2 text-xs text-amber-600">{error}</p> : null}
    </div>
  )
}
