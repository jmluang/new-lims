import { Eraser, PenLine, RotateCcw } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Button } from '../system/shared'
import { inkBounds } from './inkCrop'

/** Backing resolution; the height follows from the requested aspect ratio. */
const CANVAS_WIDTH = 900

export function SignaturePad({
  onPreviewChange,
  onReadyChange,
  padRef,
  aspectRatio = 900 / 320,
}: {
  onPreviewChange: (preview: string | null) => void
  onReadyChange: (ready: boolean) => void
  padRef: React.MutableRefObject<{ toBlob: () => Promise<Blob>; clear: () => void } | null>
  /**
   * Width over height of the drawing surface.
   *
   * The display has to match the canvas it is backed by. When it did not, a
   * stroke drawn in a near-square box was mapped onto a 900x320 canvas and came
   * out stretched sideways — the stored signature was not the one written.
   */
  aspectRatio?: number
}) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const drawing = useRef(false)
  const hasInk = useRef(false)
  const lastPoint = useRef<{ x: number; y: number } | null>(null)
  const previewFrame = useRef<number | null>(null)
  const [width, setWidth] = useState(4)
  const [inkVisible, setInkVisible] = useState(false)

  function clear() {
    const canvas = canvasRef.current
    const context = canvas?.getContext('2d')
    if (!canvas || !context) return
    context.clearRect(0, 0, canvas.width, canvas.height)
    hasInk.current = false
    setInkVisible(false)
    onPreviewChange(null)
    onReadyChange(false)
  }

  useEffect(() => {
    padRef.current = {
      clear,
      toBlob: async () => {
        const canvas = canvasRef.current
        if (!canvas || !hasInk.current) throw new Error('请先完成手写签名')
        return new Promise<Blob>((resolve, reject) =>
          canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('签名图生成失败'))), 'image/png'),
        )
      },
    }
    return () => {
      padRef.current = null
    }
  })

  function point(canvas: HTMLCanvasElement, clientX: number, clientY: number) {
    const bounds = canvas.getBoundingClientRect()
    return {
      x: ((clientX - bounds.left) / bounds.width) * canvas.width,
      y: ((clientY - bounds.top) / bounds.height) * canvas.height,
    }
  }

  function beginStroke(canvas: HTMLCanvasElement, clientX: number, clientY: number) {
    drawing.current = true
    lastPoint.current = point(canvas, clientX, clientY)
  }

  function continueStroke(canvas: HTMLCanvasElement, clientX: number, clientY: number) {
    if (!drawing.current || !lastPoint.current) return
    const next = point(canvas, clientX, clientY)
    const context = canvas.getContext('2d')
    if (!context) return
    context.strokeStyle = '#173f72'
    context.lineWidth = width * 2.1
    context.lineCap = 'round'
    context.lineJoin = 'round'
    context.beginPath()
    context.moveTo(lastPoint.current.x, lastPoint.current.y)
    context.lineTo(next.x, next.y)
    context.stroke()
    lastPoint.current = next
    if (!hasInk.current) {
      hasInk.current = true
      setInkVisible(true)
    }
    onReadyChange(true)
    publishPreview()
  }

  function endStroke() {
    drawing.current = false
    lastPoint.current = null
    publishPreview()
  }

  /** Null when the canvas cannot be read or holds no ink. */
  function croppedToInk(canvas: HTMLCanvasElement): string | null {
    const context = canvas.getContext('2d')
    if (!context) return null
    const bounds = inkBounds(context.getImageData(0, 0, canvas.width, canvas.height))
    if (!bounds) return null

    const cropped = document.createElement('canvas')
    cropped.width = Math.max(1, bounds.width)
    cropped.height = Math.max(1, bounds.height)
    const target = cropped.getContext('2d')
    if (!target) return null
    target.drawImage(canvas, bounds.left, bounds.top, bounds.width, bounds.height, 0, 0, cropped.width, cropped.height)

    return cropped.toDataURL('image/png')
  }

  function publishPreview() {
    if (previewFrame.current !== null) return
    previewFrame.current = requestAnimationFrame(() => {
      previewFrame.current = null
      const canvas = canvasRef.current
      if (!canvas || !hasInk.current) return
      // The server keeps only the ink and a margin around it, so previewing the
      // whole pad would show whitespace the document will never contain.
      onPreviewChange(croppedToInk(canvas) ?? canvas.toDataURL('image/png'))
    })
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2 text-sm text-slate-600">
          <PenLine className="size-4 text-emerald-700" />
          请在框内连续书写，支持鼠标、触控笔和手指
        </div>
        <div className="flex items-center gap-2">
          <label className="flex items-center gap-2 text-xs text-slate-600">
            笔画
            <input
              type="range"
              min="2"
              max="9"
              value={width}
              onChange={(event) => setWidth(Number(event.target.value))}
              aria-label="签名笔画粗细"
            />
          </label>
          <Button variant="secondary" onClick={clear}>
            <RotateCcw className="size-4" />
            重写
          </Button>
        </div>
      </div>
      <div className="relative overflow-hidden rounded-xl border border-slate-300 bg-[linear-gradient(to_right,transparent_49.8%,rgb(148_163_184/0.18)_50%,transparent_50.2%),linear-gradient(to_bottom,transparent_49.8%,rgb(148_163_184/0.18)_50%,transparent_50.2%)] shadow-inner">
        <canvas
          ref={canvasRef}
          width={CANVAS_WIDTH}
          height={Math.round(CANVAS_WIDTH / aspectRatio)}
          style={{ aspectRatio }}
          className="block w-full touch-none cursor-crosshair"
          aria-label="手写签名区域"
          onPointerDown={(event) => {
            if (event.pointerType === 'mouse') return
            event.currentTarget.setPointerCapture(event.pointerId)
            beginStroke(event.currentTarget, event.clientX, event.clientY)
          }}
          onPointerMove={(event) => {
            if (event.pointerType === 'mouse') return
            continueStroke(event.currentTarget, event.clientX, event.clientY)
          }}
          onPointerUp={(event) => event.pointerType !== 'mouse' && endStroke()}
          onPointerCancel={() => {
            drawing.current = false
            lastPoint.current = null
          }}
          onMouseDown={(event) => beginStroke(event.currentTarget, event.clientX, event.clientY)}
          onMouseMove={(event) => continueStroke(event.currentTarget, event.clientX, event.clientY)}
          onMouseUp={endStroke}
          onMouseLeave={() => drawing.current && endStroke()}
        />
        {!inkVisible ? (
          <div className="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-slate-400">
            <Eraser className="mr-2 size-4" /> 从这里开始签名
          </div>
        ) : null}
      </div>
      <p className="text-xs leading-5 text-slate-500">
        预览只展示笔迹外观；提交时系统会再次要求当前密码，并由 Java 使用单位证书生成 PAdES-B-T 数字签名。
      </p>
    </div>
  )
}
