import { useQuery } from '@tanstack/react-query'
import { useNavigate, useRouterState } from '@tanstack/react-router'
import { ArrowLeft } from 'lucide-react'
import { useLayoutEffect, useRef, useState } from 'react'
import { ErrorNotice, LoadingState, PageShell } from '../system/shared'
import { fetchSigningDocument } from './handwrittenApi'
import { PdfDocumentVersions } from './PdfDocumentVersions'

export function PdfDocumentDetailPage() {
  const pathname = useRouterState({ select: state => state.location.pathname })
  const search = useRouterState({ select: state => state.location.searchStr })
  const documentUuid = pathname.split('/').filter(Boolean).at(-1) ?? ''
  const revisionUuid = new URLSearchParams(search).get('revision')
  const navigate = useNavigate()
  const document = useQuery({
    queryKey: ['pdf', 'documents', documentUuid],
    queryFn: () => fetchSigningDocument(documentUuid),
    retry: false,
  })

  const content = useRef<HTMLDivElement>(null)
  const [height, setHeight] = useState<number>()
  useLayoutEffect(() => {
    const area = content.current
    if (!area) return
    const main = area.closest('main')
    const measure = () => {
      const bottomPadding = main ? parseFloat(getComputedStyle(main).paddingBottom) : 16
      setHeight(Math.max(120, (window.visualViewport?.height ?? window.innerHeight) - area.getBoundingClientRect().top - bottomPadding))
    }
    measure()
    const observer = new ResizeObserver(measure)
    if (main) observer.observe(main)
    const header = window.document.querySelector('header')
    if (header) observer.observe(header)
    window.addEventListener('resize', measure)
    window.visualViewport?.addEventListener('resize', measure)
    return () => {
      observer.disconnect()
      window.removeEventListener('resize', measure)
      window.visualViewport?.removeEventListener('resize', measure)
    }
  }, [])
  const returnLink = <a className="inline-flex min-h-10 shrink-0 items-center gap-1 rounded-md px-2 text-sm text-slate-600 hover:bg-slate-100"
    href="/pdf/documents"><ArrowLeft className="size-4" aria-hidden="true" />返回列表</a>

  return <PageShell title={document.data ? `报告查看 · ${document.data.report_number}` : '报告查看'}
    description="只读 PDF · 可切换版本、翻页或放大。">
    <div ref={content} className="flex min-h-0 flex-col gap-2" style={{ height }}>
    {document.data ? null : returnLink}
    {document.isPending ? <LoadingState label="正在加载报告…" /> : null}
    {document.isError ? <ErrorNotice error={document.error} fallback="报告不存在、不可用或无权查看。" /> : null}
    {document.data ? <PdfDocumentVersions document={document.data} revisionUuid={revisionUuid} actions={returnLink}
      onRevisionChange={revision => { void navigate({ to: '/pdf/documents/$documentUuid', params: { documentUuid }, search: { revision }, hash: '' }) }} /> : null}
    </div>
  </PageShell>
}
