import { Eraser, PenLine, RotateCcw } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Button } from '../system/shared'

export function SignaturePad({
  onPreviewChange,
  onReadyChange,
  padRef,
}: {
  onPreviewChange: (preview: string | null) => void
  onReadyChange: (ready: boolean) => void
  padRef: React.MutableRefObject<{ toBlob: () => Promise<Blob>; clear: () => void } | null>
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

  function publishPreview() {
    if (previewFrame.current !== null) return
    previewFrame.current = requestAnimationFrame(() => {
      previewFrame.current = null
      const canvas = canvasRef.current
      if (canvas && hasInk.current) onPreviewChange(canvas.toDataURL('image/png'))
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
          width={900}
          height={320}
          className="block h-56 w-full touch-none cursor-crosshair"
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
