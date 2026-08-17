import { Loader2 } from 'lucide-react'
import { memo, useCallback, useEffect, useRef, useState } from 'react'
import { GlobalWorkerOptions, getDocument, type PDFDocumentProxy, type PDFPageProxy } from 'pdfjs-dist'
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { cn } from '../../lib/utils'
import type { Placement, SignatureRole } from './handwrittenApi'
import { applyDrag, type DragState } from './placementDrag'
import { placementPagesReady, samePlacements } from './placementReady'

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
  // Only the pointer handlers ever read this, and nothing renders from it, so a
  // ref keeps a drag from costing two renders it has no use for.
  const dragRef = useRef<DragState | null>(null)
  // Pages report their measured size once rendered. Placement boxes are
  // positioned as percentages of that size, so before it arrives they sit in a
  // 1x1 box — present in the DOM but not yet where they belong.
  // Tagged with its source, the same way `loaded` is, so a new file starts from
  // an empty set without resetting state from inside an effect.
  const [measured, setMeasured] = useState<{ source: File | Blob; pages: ReadonlySet<number> } | null>(null)
  const scrollAreaRef = useRef<HTMLDivElement>(null)
  const scrolledTo = useRef<File | Blob | null>(null)
  const fileRef = useRef(file)
  useEffect(() => {
    fileRef.current = file
  }, [file])
  const markMeasured = useCallback((pageIndex: number) => {
    const source = fileRef.current
    if (!source) return
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

  const measuredPages = measured?.source === file ? measured.pages : new Set<number>()
  const placementsReady = placementPagesReady(placements.map((placement) => placement.page_index), measuredPages)

  // Boxes often sit deep in a report; opening on page 1 hides the very thing
  // the operator came to adjust. Jump once per file, after the boxes have landed.
  useEffect(() => {
    if (!file || !placementsReady || scrolledTo.current === file) return
    const firstPage = placements.reduce<number | null>(
      (lowest, placement) => (lowest === null || placement.page_index < lowest ? placement.page_index : lowest),
      null,
    )
    if (firstPage === null || firstPage === 0) {
      scrolledTo.current = file
      return
    }
    const target = scrollAreaRef.current?.querySelector(`#pdf-page-${firstPage}`)
    target?.scrollIntoView({ block: 'start' })
    scrolledTo.current = file
  })

  // A memoized page keeps the handlers it last rendered with, so nothing here
  // may read captured state. `drag` is set on pointerdown by a render this page
  // skips, and a captured plan would write back an array that predates edits
  // made to other pages.
  const placementsRef = useRef(placements)
  const changeRef = useRef(onChange)
  useEffect(() => {
    placementsRef.current = placements
    changeRef.current = onChange
  }, [placements, onChange])

  // Written straight from the pointer handlers: waiting for an effect to sync
  // this would drop the first move of every drag.
  const startDrag = useCallback((next: DragState) => {
    dragRef.current = next
  }, [])
  const endDrag = useCallback(() => {
    dragRef.current = null
  }, [])

  // Pointer events outpace the screen, so coalesce them into one update per
  // frame instead of re-rendering for every move.
  const pendingFrame = useRef<number | null>(null)
  useEffect(() => () => {
    if (pendingFrame.current !== null) cancelAnimationFrame(pendingFrame.current)
  }, [])

  function updateDrag(event: React.PointerEvent<HTMLDivElement>, pageIndex: number) {
    const currentDrag = dragRef.current
    if (!currentDrag || !changeRef.current) return
    const { clientX, clientY } = event

    if (pendingFrame.current !== null) return
    pendingFrame.current = requestAnimationFrame(() => {
      pendingFrame.current = null
      const latestDrag = dragRef.current
      const onLatestChange = changeRef.current
      if (!latestDrag || !onLatestChange) return
      onLatestChange(applyDrag(placementsRef.current, latestDrag, pageIndex, clientX, clientY))
    })
  }

  if (!file) {
    return (
      <div className="flex min-h-[32rem] items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-sm text-slate-500">
        {emptyMessage ?? '上传 PDF 后将在这里显示逐页实时预览'}
      </div>
    )
  }

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
      <div ref={scrollAreaRef} className={cn(
        'max-h-[72vh] space-y-5 p-4 sm:p-6 xl:max-h-none xl:h-full',
        placementsReady ? 'overflow-auto' : 'overflow-hidden pointer-events-none select-none',
      )}>
        {Array.from({ length: document.numPages }, (_, pageIndex) => (
          <PdfPage
            onMeasured={markMeasured}
            key={pageIndex}
            document={document}
            pageIndex={pageIndex}
            placements={placements.filter((placement) => placement.page_index === pageIndex)}
            editable={editable}
            selectedRole={selectedRole}
            signaturePreview={signaturePreview}
            onSelectRole={onSelectRole}
            onDragStart={startDrag}
            onDrag={(event) => updateDrag(event, pageIndex)}
            onDragEnd={endDrag}
          />
        ))}
      </div>
      </div>
    </div>
  )
}

const PdfPage = memo(function PdfPage({
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
    const context = canvas.getContext('2d')
    if (!context) return
    const task = page.render({ canvas, canvasContext: context, viewport, transform: [outputScale, 0, 0, outputScale, 0, 0] })
    return () => task.cancel()
    // Deliberately only `page`: re-running this cancels and repaints the canvas,
    // which must not happen just because a parent render produced new callbacks.
  }, [page])

  // Reported separately so the notification cannot drag the canvas render with
  // it. The ref keeps the callback current without making it a dependency.
  const measuredRef = useRef(onMeasured)
  useEffect(() => {
    measuredRef.current = onMeasured
  }, [onMeasured])
  useEffect(() => {
    if (size.width > 1) measuredRef.current(pageIndex)
  }, [size.width, pageIndex])

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
              {editable ? (
                <span
                  data-resize="true"
                  title="拖动调整大小"
                  className="absolute -bottom-0.5 -right-0.5 size-5 cursor-se-resize rounded-tl border-l-2 border-t-2 border-current bg-white shadow-sm"
                />
              ) : null}
            </div>
          )
        })}
      </div>
    </div>
  )
}, (previous, next) =>
  previous.document === next.document
  && previous.pageIndex === next.pageIndex
  && previous.editable === next.editable
  && previous.selectedRole === next.selectedRole
  && previous.signaturePreview === next.signaturePreview
  && previous.onMeasured === next.onMeasured
  // The parent re-filters this array on every render, so compare by value:
  // dragging one box must not reconcile every other page in the report.
  && samePlacements(previous.placements, next.placements))

function numericRect(placement: Placement) {
  return {
    x: Number(placement.normalized_rect.x),
    y: Number(placement.normalized_rect.y),
    width: Number(placement.normalized_rect.width),
    height: Number(placement.normalized_rect.height),
  }
}


