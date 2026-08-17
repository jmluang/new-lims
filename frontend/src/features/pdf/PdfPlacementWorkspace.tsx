import { Loader2 } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { GlobalWorkerOptions, getDocument, type PDFDocumentProxy, type PDFPageProxy } from 'pdfjs-dist'
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { cn } from '../../lib/utils'
import type { Placement, SignatureRole } from './handwrittenApi'
import { placementPagesReady } from './placementReady'

GlobalWorkerOptions.workerSrc = pdfWorkerUrl

const roleLabels: Record<SignatureRole, string> = {
  inspector: '主检签名',
  reviewer: '审核签名',
  issuer: '签发签名',
}

const roleColors: Record<SignatureRole, string> = {
  inspector: 'border-sky-600 bg-sky-500/10 text-sky-800',
  reviewer: 'border-violet-600 bg-violet-500/10 text-violet-800',
  issuer: 'border-emerald-700 bg-emerald-500/10 text-emerald-800',
}

type DragState = {
  role: SignatureRole
  mode: 'move' | 'resize'
  startX: number
  startY: number
  rect: { x: number; y: number; width: number; height: number }
  pageWidth: number
  pageHeight: number
}

export function PdfPlacementWorkspace({
  file,
  placements,
  editable,
  selectedRole,
  onSelectRole,
  onChange,
  signaturePreview,
  emptyMessage,
}: {
  file: File | Blob | null
  placements: Placement[]
  editable: boolean
  selectedRole?: SignatureRole
  onSelectRole?: (role: SignatureRole) => void
  onChange?: (placements: Placement[]) => void
  signaturePreview?: string | null
  emptyMessage?: string
}) {
  const [loaded, setLoaded] = useState<{ source: File | Blob; document: PDFDocumentProxy } | null>(null)
  const [loadError, setLoadError] = useState<{ source: File | Blob; message: string } | null>(null)
  const [drag, setDrag] = useState<DragState | null>(null)
  // Pages report their measured size once rendered. Placement boxes are
  // positioned as percentages of that size, so before it arrives they sit in a
  // 1x1 box — present in the DOM but not yet where they belong.
  // Tagged with its source, the same way `loaded` is, so a new file starts from
  // an empty set without resetting state from inside an effect.
  const [measured, setMeasured] = useState<{ source: File | Blob; pages: ReadonlySet<number> } | null>(null)
  const markMeasured = useCallback((source: File | Blob, pageIndex: number) => {
    setMeasured((current) => {
      if (current?.source !== source) return { source, pages: new Set([pageIndex]) }
      if (current.pages.has(pageIndex)) return current

      return { source, pages: new Set(current.pages).add(pageIndex) }
    })
  }, [])

  useEffect(() => {
    if (!file) return
    let cancelled = false
    let loaded: PDFDocumentProxy | null = null
    file
      .arrayBuffer()
      .then((bytes) => getDocument({ data: bytes }).promise)
      .then((pdf) => {
        loaded = pdf
        if (!cancelled) {
          setLoadError(null)
          setLoaded({ source: file, document: pdf })
        }
      })
      .catch(() => !cancelled && setLoadError({ source: file, message: 'PDF 预览加载失败，请确认文件未加密且格式完整。' }))

    return () => {
      cancelled = true
      void loaded?.destroy()
    }
  }, [file])

  function updateDrag(event: React.PointerEvent<HTMLDivElement>, pageIndex: number) {
    if (!drag || !onChange) return
    const dx = (event.clientX - drag.startX) / drag.pageWidth
    const dy = (event.clientY - drag.startY) / drag.pageHeight
    const minimum = 0.035
    const next = placements.map((placement) => {
      if (placement.semantic_role !== drag.role || placement.page_index !== pageIndex) return placement
      let { x, y, width, height } = drag.rect
      if (drag.mode === 'move') {
        x = clamp(x + dx, 0, 1 - width)
        y = clamp(y + dy, 0, 1 - height)
      } else {
        width = clamp(width + dx, minimum, 1 - x)
        height = clamp(height + dy, minimum, 1 - y)
      }

      return {
        ...placement,
        normalized_rect: {
          x: decimal(x),
          y: decimal(y),
          width: decimal(width),
          height: decimal(height),
        },
      }
    })
    onChange(next)
  }

  if (!file) {
    return (
      <div className="flex min-h-[32rem] items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-sm text-slate-500">
        {emptyMessage ?? '上传 PDF 后将在这里显示逐页实时预览'}
      </div>
    )
  }

  const measuredPages = measured?.source === file ? measured.pages : new Set<number>()
  const placementsReady = placementPagesReady(placements.map((placement) => placement.page_index), measuredPages)

  const document = loaded?.source === file ? loaded.document : null
  const error = loadError?.source === file ? loadError.message : null

  if (error) {
    return <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">{error}</div>
  }

  if (!document) {
    return <div className="flex min-h-[32rem] items-center justify-center text-sm text-slate-500">正在解析 PDF…</div>
  }

  return (
    <div className="grid min-h-0 grid-cols-[4.5rem_minmax(0,1fr)] overflow-hidden rounded-xl border border-slate-200 bg-slate-200/60 xl:h-full">
      <div className="space-y-2 overflow-y-auto border-r border-slate-200 bg-white p-2">
        {Array.from({ length: document.numPages }, (_, index) => (
          <a
            key={index}
            href={`#pdf-page-${index}`}
            className="flex aspect-[3/4] items-center justify-center rounded-md border border-slate-200 bg-slate-50 text-xs font-semibold text-slate-600 hover:border-emerald-500"
          >
            {index + 1}
          </a>
        ))}
      </div>
      <div className="relative min-h-0">
        {placementsReady ? null : (
          <div className="absolute inset-0 z-10 flex items-center justify-center bg-slate-100/80 backdrop-blur-[1px]">
            <span className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-600 shadow-sm">
              <Loader2 className="size-4 animate-spin text-emerald-700" />
              正在准备签名框…
            </span>
          </div>
        )}
      <div className={cn(
        'max-h-[72vh] space-y-5 p-4 sm:p-6 xl:max-h-none xl:h-full',
        placementsReady ? 'overflow-auto' : 'overflow-hidden pointer-events-none select-none',
      )}>
        {Array.from({ length: document.numPages }, (_, pageIndex) => (
          <PdfPage
            onMeasured={(index) => markMeasured(file, index)}
            key={pageIndex}
            document={document}
            pageIndex={pageIndex}
            placements={placements.filter((placement) => placement.page_index === pageIndex)}
            editable={editable}
            selectedRole={selectedRole}
            signaturePreview={signaturePreview}
            onSelectRole={onSelectRole}
            onDragStart={setDrag}
            onDrag={(event) => updateDrag(event, pageIndex)}
            onDragEnd={() => setDrag(null)}
          />
        ))}
      </div>
      </div>
    </div>
  )
}

function PdfPage({
  document,
  pageIndex,
  placements,
  editable,
  selectedRole,
  signaturePreview,
  onMeasured,
  onSelectRole,
  onDragStart,
  onDrag,
  onDragEnd,
}: {
  document: PDFDocumentProxy
  pageIndex: number
  placements: Placement[]
  editable: boolean
  selectedRole?: SignatureRole
  signaturePreview?: string | null
  onMeasured: (pageIndex: number) => void
  onSelectRole?: (role: SignatureRole) => void
  onDragStart: (drag: DragState) => void
  onDrag: (event: React.PointerEvent<HTMLDivElement>) => void
  onDragEnd: () => void
}) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const [page, setPage] = useState<PDFPageProxy | null>(null)
  const [size, setSize] = useState({ width: 1, height: 1 })

  useEffect(() => {
    let cancelled = false
    document.getPage(pageIndex + 1).then((loaded) => !cancelled && setPage(loaded))
    return () => {
      cancelled = true
    }
  }, [document, pageIndex])

  useEffect(() => {
    if (!page || !canvasRef.current) return
    const viewport = page.getViewport({ scale: 1.35 })
    const outputScale = Math.min(window.devicePixelRatio || 1, 2)
    const canvas = canvasRef.current
    canvas.width = Math.floor(viewport.width * outputScale)
    canvas.height = Math.floor(viewport.height * outputScale)
    canvas.style.width = `${viewport.width}px`
    canvas.style.height = `${viewport.height}px`
    setSize({ width: viewport.width, height: viewport.height })
    // The boxes can only land correctly once this page knows its own size.
    onMeasured(pageIndex)
    const context = canvas.getContext('2d')
    if (!context) return
    const task = page.render({ canvas, canvasContext: context, viewport, transform: [outputScale, 0, 0, outputScale, 0, 0] })
    return () => task.cancel()
  }, [page, pageIndex, onMeasured])

  return (
    <div id={`pdf-page-${pageIndex}`} className="mx-auto w-fit max-w-full">
      <div className="mb-1 text-center text-xs font-medium text-slate-500">第 {pageIndex + 1} 页</div>
      <div className="relative overflow-hidden bg-white shadow-[0_12px_35px_rgb(15_23_42/0.16)]" style={{ width: size.width, height: size.height, maxWidth: '100%' }}>
        <canvas ref={canvasRef} className="block h-auto max-w-full" />
        {placements.map((placement) => {
          const rect = numericRect(placement)
          const selected = selectedRole === placement.semantic_role
          return (
            <div
              key={placement.semantic_role}
              role={editable ? 'button' : undefined}
              tabIndex={editable ? 0 : undefined}
              aria-label={`${roleLabels[placement.semantic_role]}位置`}
              className={cn(
                'absolute touch-none select-none overflow-hidden border-2 shadow-sm',
                roleColors[placement.semantic_role],
                selected && 'ring-2 ring-white ring-offset-2 ring-offset-emerald-700',
                editable ? 'cursor-move' : 'pointer-events-none',
              )}
              style={{ left: `${rect.x * 100}%`, top: `${rect.y * 100}%`, width: `${rect.width * 100}%`, height: `${rect.height * 100}%` }}
              onPointerDown={(event) => {
                if (!editable) return
                event.currentTarget.setPointerCapture(event.pointerId)
                onSelectRole?.(placement.semantic_role)
                onDragStart({
                  role: placement.semantic_role,
                  mode: (event.target as HTMLElement).dataset.resize ? 'resize' : 'move',
                  startX: event.clientX,
                  startY: event.clientY,
                  rect,
                  pageWidth: event.currentTarget.parentElement?.clientWidth ?? size.width,
                  pageHeight: event.currentTarget.parentElement?.clientHeight ?? size.height,
                })
              }}
              onPointerMove={onDrag}
              onPointerUp={onDragEnd}
            >
              <div className="absolute inset-x-0 top-0 flex items-center justify-between bg-white/90 px-1.5 py-0.5 text-[10px] font-semibold">
                <span>{roleLabels[placement.semantic_role]}</span>
                <span>P{pageIndex + 1}</span>
              </div>
              {signaturePreview ? (
                <img src={signaturePreview} alt="手写签名实时预览" className="h-full w-full object-contain p-1.5 pt-5" />
              ) : null}
              {editable ? <span data-resize="true" className="absolute bottom-0 right-0 size-4 cursor-se-resize border-l border-t border-current bg-white/90" /> : null}
            </div>
          )
        })}
      </div>
    </div>
  )
}

function numericRect(placement: Placement) {
  return {
    x: Number(placement.normalized_rect.x),
    y: Number(placement.normalized_rect.y),
    width: Number(placement.normalized_rect.width),
    height: Number(placement.normalized_rect.height),
  }
}

function clamp(value: number, minimum: number, maximum: number) {
  return Math.min(Math.max(value, minimum), maximum)
}

function decimal(value: number) {
  return value.toFixed(6)
}
