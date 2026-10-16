import { useQuery } from '@tanstack/react-query'
import { lazy, Suspense, useEffect, useRef, type ReactNode } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { ListboxSelect } from '../../components/ui/ListboxSelect'
import { EmptyState, ErrorNotice } from '../system/shared'
import { downloadRevision, type SigningDocument } from './handwrittenApi'

type Revision = SigningDocument['revisions'][number]
const PdfPreview = lazy(async () => ({ default: (await import('./PdfPlacementWorkspace')).PdfPlacementWorkspace }))

const roleLabels: Record<string, string> = {
  finalized_unsigned: '定稿 PDF',
  prepared: '已准备签名位置',
  handwritten_signature: '手写签名 PDF',
}

function revisionLabel(revision: Revision) {
  return `版本 ${revision.revision_number ?? '未编号'} · ${roleLabels[revision.revision_role ?? ''] ?? 'PDF'}`
}

/** Uses the existing download permission and immutable revision endpoint for both actions. */
function RevisionPdf({ revision, reportNumber, available, toolbar }: {
  toolbar: ReactNode
  revision: Revision
  reportNumber: string
  available: boolean
}) {
  const pdf = useQuery({
    queryKey: ['pdf', 'handwritten', 'revision', revision.revision_uuid],
    queryFn: () => downloadRevision(revision.revision_uuid),
    enabled: available,
    staleTime: Infinity,
    retry: false,
  })
  const download = useRef<HTMLAnchorElement>(null)
  useEffect(() => {
    if (!pdf.data || !available) return
    // Blob URLs belong to this mounted preview, not to the shared immutable-byte cache.
    const url = URL.createObjectURL(pdf.data)
    download.current?.setAttribute('href', url)
    return () => URL.revokeObjectURL(url)
  }, [pdf.data, available])

  return <div className="flex min-h-0 flex-1 flex-col gap-2">
    <div className="flex shrink-0 flex-wrap items-center gap-2">
      {toolbar}
      {pdf.data && available ? <a ref={download} className="inline-flex min-h-10 shrink-0 items-center px-2 text-sm text-emerald-800 underline"
        download={`${reportNumber}-v${revision.revision_number ?? 'unknown'}.pdf`}>下载此版本 PDF</a> : null}
    </div>
    {!available ? <p className="text-sm text-amber-800">此版本当前不可预览或下载。</p>
      : pdf.isError ? <ErrorNotice error={pdf.error} fallback="PDF 加载失败" />
      : !pdf.data ? <p role="status" className="text-sm text-slate-500">正在加载 PDF…</p>
      : <Suspense fallback={<p role="status" className="text-sm text-slate-500">正在加载 PDF 预览…</p>}>
        <div className="min-h-0 flex-1 [&>div]:h-full">
          <PdfPreview file={pdf.data} placements={[]} editable={false} fitPage />
        </div>
      </Suspense>}
  </div>
}

export function PdfDocumentVersions({ document, revisionUuid, onRevisionChange, actions }: {
  actions?: ReactNode
  document: SigningDocument
  revisionUuid: string | null
  onRevisionChange: (revisionUuid: string) => void
}) {
  const revisions = [...document.revisions].sort((a, b) => (b.revision_number ?? 0) - (a.revision_number ?? 0))
  const selectedUuid = revisionUuid ?? document.published_revision_uuid
    ?? (document.status === 'published' ? '' : revisions[0]?.revision_uuid ?? '')
  const selected = revisions.find(revision => revision.revision_uuid === selectedUuid)

  const toolbar = <>
    <div className="min-w-0 flex-[1_1_16rem] sm:max-w-md">
      <ListboxSelect label="报告版本" value={selectedUuid} onChange={onRevisionChange}
        options={revisions.map(revision => ({ value: revision.revision_uuid,
          label: `${revisionLabel(revision)}${revision.revision_uuid === document.published_revision_uuid ? ' · 已发布' : ''}` }))} />
    </div>
    {actions}
  </>
  const toolbarRow = <div className="flex shrink-0 flex-wrap items-center gap-2">{toolbar}</div>

  return revisions.length === 0 ? <>{toolbarRow}<EmptyState title="暂无 PDF 版本" description="报告定稿后将在这里显示版本。" /></> : <div className="flex h-full min-h-0 flex-col gap-2">
    {!selected ? <>{toolbarRow}<p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
      {revisionUuid === null ? '已发布版本不可用，请选择有效的报告版本。' : '链接指定的版本不存在或不属于此报告，请选择有效的报告版本。'}
    </p></> : <PermissionGate resource="pdf.revision" action="download"
      fallback={<>{toolbarRow}<p className="text-sm text-slate-500">没有 PDF 预览或下载权限，可联系管理员。</p></>}>
      <RevisionPdf revision={selected} reportNumber={document.report_number} toolbar={toolbar}
        available={selected.integrity_state === 'ready' && document.integrity_state === 'ok'} />
    </PermissionGate>}
  </div>
}
