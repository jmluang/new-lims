import { useQuery } from '@tanstack/react-query'
import { useNavigate, useRouterState } from '@tanstack/react-router'
import { ArrowLeft } from 'lucide-react'
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

  return <PageShell title={document.data ? `报告查看 · ${document.data.report_number}` : '报告查看'}
    description="查看报告的已保存版本，预览或下载 PDF。"
    actions={<a className="inline-flex min-h-10 items-center gap-2 rounded-md px-3 text-sm text-slate-600 hover:bg-slate-100"
      href="/pdf/documents"><ArrowLeft className="size-4" aria-hidden="true" />返回列表</a>}>
    {document.isPending ? <LoadingState label="正在加载报告…" /> : null}
    {document.isError ? <ErrorNotice error={document.error} fallback="报告不存在、不可用或无权查看。" /> : null}
    {document.data ? <PdfDocumentVersions document={document.data} revisionUuid={revisionUuid}
      onRevisionChange={revision => { void navigate({ to: '/pdf/documents/$documentUuid', params: { documentUuid }, search: { revision }, hash: '' }) }} /> : null}
  </PageShell>
}
