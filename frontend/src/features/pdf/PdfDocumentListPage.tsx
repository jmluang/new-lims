import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Clock, FileText, Loader2, PenLine, Pencil, Search, Trash2, XCircle } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { Button, DataTable, EmptyState, ErrorNotice, LoadingState, Modal, PageShell, PaginationControls, Panel } from '../system/shared'
import { formatDateTime, inputClass } from '../system/utils'
import {
  deleteSigningDocument,
  fetchSigningDocuments,
  renameSigningDocument,
  type DocumentSigner,
  type SigningDocument,
} from './handwrittenApi'
import { editableReason, planReason } from './documentEditable'

const stageLabels: Record<string, string> = {
  confirmed_awaiting_finalize: '待定稿',
  finalized_awaiting_workflow: '已定稿，未编排签名',
  preparing_fields: '正在准备签名字段',
  awaiting_signature: '等待签署',
  all_signed: '全部已签署',
  published: '已发布',
  cancelled: '已取消',
  failed: '已失败',
}

const signerStatusLabels: Record<string, string> = {
  pending: '未轮到',
  available: '待签署',
  signing: '签署中',
  signed: '已签署',
  rejected: '已拒绝',
  cancelled: '已取消',
}

const roleLabels: Record<string, string> = {
  inspector: '主检',
  reviewer: '审核',
  issuer: '签发',
}

export function PdfDocumentListPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [renaming, setRenaming] = useState<SigningDocument | null>(null)
  const [renameValue, setRenameValue] = useState('')
  const [deleting, setDeleting] = useState<SigningDocument | null>(null)

  const documents = useQuery({
    queryKey: ['pdf', 'documents', appliedSearch, page, perPage],
    queryFn: () => fetchSigningDocuments({ search: appliedSearch, page, perPage }),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['pdf', 'documents'] })
  const rename = useMutation({
    mutationFn: () => renameSigningDocument(renaming!.document_uuid, renameValue.trim()),
    onSuccess: () => {
      setRenaming(null)
      invalidate()
    },
  })
  const remove = useMutation({
    mutationFn: () => deleteSigningDocument(deleting!.document_uuid),
    onSuccess: () => {
      setDeleting(null)
      invalidate()
    },
  })

  const rows = documents.data?.data ?? []

  // Hand the document to the planning workspace, which reloads its finalized
  // revision and previous plan instead of starting from another upload.
  function planDocument(document: SigningDocument) {
    window.location.href = `/pdf/handwritten-signing?document=${encodeURIComponent(document.document_uuid)}#plan`
  }

  return (
    <PageShell
      title="签署文档"
      description="按报告编号查看手写签名流程的进度：当前阶段、每位签署人的状态，以及尚未签署完成的草稿。"
    >
      <Panel title="筛选">
        <form
          className="flex flex-wrap items-end gap-2"
          onSubmit={(event) => {
            event.preventDefault()
            setPage(1)
            setAppliedSearch(search.trim())
          }}
        >
          <label className="block text-xs font-medium text-slate-600">
            报告编号
            <input
              className={`${inputClass} mt-1 w-64`}
              value={search}
              placeholder="输入报告编号搜索"
              onChange={(event) => setSearch(event.target.value)}
            />
          </label>
          <Button variant="secondary" type="submit">
            <Search className="size-4" />
            搜索
          </Button>
        </form>
      </Panel>

      {documents.isLoading ? <LoadingState label="正在加载签署文档" /> : null}
      {documents.isError ? <ErrorNotice error={documents.error} fallback="签署文档加载失败" /> : null}
      {!documents.isLoading && rows.length === 0 ? (
        <EmptyState title="暂无签署文档" description="上传 PDF 并确认报告编号后，文档会出现在这里。" />
      ) : null}

      {rows.length > 0 ? (
        <DataTable>
          <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-3 py-2">报告编号</th>
              <th className="px-3 py-2">阶段</th>
              <th className="px-3 py-2">签署人</th>
              <th className="px-3 py-2">版本</th>
              <th className="px-3 py-2">创建时间</th>
              <th className="px-3 py-2 text-right">操作</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((document) => (
              <tr key={document.document_uuid} className="align-top">
                <td className="px-3 py-2">
                  <div className="font-medium text-slate-900">{document.report_number}</div>
                  <div className="font-mono text-xs text-slate-400">{document.document_uuid.slice(0, 8)}</div>
                </td>
                <td className="px-3 py-2">
                  <StageBadge document={document} />
                </td>
                <td className="px-3 py-2">
                  {document.signers.length === 0 ? (
                    <span className="text-xs text-slate-400">尚未编排</span>
                  ) : (
                    <div className="space-y-1">
                      {document.signers.map((signer) => (
                        <SignerRow key={signer.sequence} signer={signer} />
                      ))}
                    </div>
                  )}
                </td>
                <td className="whitespace-nowrap px-3 py-2 text-slate-700">{document.revisions.length} 个</td>
                <td className="whitespace-nowrap px-3 py-2 text-slate-700">{formatDateTime(document.created_at)}</td>
                <td className="px-3 py-2">
                  <div className="flex justify-end gap-1">
                    <PermissionGate resource="pdf.workflow" action="create">
                      <Button
                        variant="ghost"
                        title={planReason(document) ?? '编排签名位置与签署人'}
                        disabled={planReason(document) !== null}
                        onClick={() => planDocument(document)}
                      >
                        <PenLine className="size-4" />
                      </Button>
                    </PermissionGate>
                    <PermissionGate resource="pdf.document" action="update">
                      <Button
                        variant="ghost"
                        title={editableReason(document) ?? '修改报告编号'}
                        disabled={editableReason(document) !== null}
                        onClick={() => {
                          setRenaming(document)
                          setRenameValue(document.report_number)
                        }}
                      >
                        <Pencil className="size-4" />
                      </Button>
                    </PermissionGate>
                    <PermissionGate resource="pdf.document" action="delete">
                      <Button
                        variant="ghost"
                        className="text-red-700 hover:bg-red-50"
                        title={editableReason(document) ?? '删除草稿'}
                        disabled={editableReason(document) !== null}
                        onClick={() => setDeleting(document)}
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </PermissionGate>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </DataTable>
      ) : null}

      {/* DataTable is desktop-only, so the same rows need a card form on mobile. */}
      {rows.length > 0 ? (
        <div className="space-y-2 md:hidden">
          {rows.map((document) => (
            <article className="rounded-lg border border-emerald-900/10 bg-white p-3" key={document.document_uuid}>
              <div className="flex items-start justify-between gap-2">
                <p className="truncate text-sm font-medium text-slate-900">{document.report_number}</p>
                <StageBadge document={document} />
              </div>
              <div className="mt-2 space-y-1">
                {document.signers.length === 0 ? (
                  <span className="text-xs text-slate-400">尚未编排签名</span>
                ) : (
                  document.signers.map((signer) => <SignerRow key={signer.sequence} signer={signer} />)
                )}
              </div>
              <p className="mt-2 text-xs text-slate-400">
                {document.revisions.length} 个版本 · {formatDateTime(document.created_at)}
              </p>
              <div className="mt-2 flex flex-wrap gap-2">
                <PermissionGate resource="pdf.workflow" action="create">
                  <Button
                    variant="secondary"
                    disabled={planReason(document) !== null}
                    onClick={() => planDocument(document)}
                  >
                    <PenLine className="size-4" />
                    编排签名
                  </Button>
                </PermissionGate>
                <PermissionGate resource="pdf.document" action="update">
                  <Button
                    variant="secondary"
                    disabled={editableReason(document) !== null}
                    onClick={() => {
                      setRenaming(document)
                      setRenameValue(document.report_number)
                    }}
                  >
                    <Pencil className="size-4" />
                    改编号
                  </Button>
                </PermissionGate>
                <PermissionGate resource="pdf.document" action="delete">
                  <Button
                    variant="secondary"
                    className="border-red-200 text-red-700 hover:bg-red-50"
                    disabled={editableReason(document) !== null}
                    onClick={() => setDeleting(document)}
                  >
                    <Trash2 className="size-4" />
                    删除
                  </Button>
                </PermissionGate>
              </div>
              {planReason(document) ?? editableReason(document) ? (
                <p className="mt-2 text-xs text-slate-400">{planReason(document) ?? editableReason(document)}</p>
              ) : null}
            </article>
          ))}
        </div>
      ) : null}

      {documents.data?.meta ? (
        <PaginationControls
          meta={documents.data.meta}
          page={page}
          perPage={perPage}
          onPageChange={setPage}
          onPerPageChange={(next) => {
            setPerPage(next)
            setPage(1)
          }}
        />
      ) : null}

      <Modal open={renaming !== null} title="修改报告编号" onClose={() => setRenaming(null)}>
        <p className="mb-3 text-xs leading-5 text-slate-500">
          报告编号是文档的业务身份。只有尚未签署、未发布的草稿可以修改。
        </p>
        <label className="block text-xs font-medium text-slate-600">
          报告编号
          <input className={`${inputClass} mt-1`} value={renameValue} onChange={(event) => setRenameValue(event.target.value)} />
        </label>
        {rename.isError ? <div className="mt-3"><ErrorNotice error={rename.error} fallback="报告编号修改失败" /></div> : null}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setRenaming(null)}>取消</Button>
          <Button variant="primary" disabled={!renameValue.trim() || rename.isPending} onClick={() => rename.mutate()}>
            {rename.isPending ? <Loader2 className="size-4 animate-spin" /> : null}
            保存
          </Button>
        </div>
      </Modal>

      <Modal open={deleting !== null} title="删除草稿" onClose={() => setDeleting(null)}>
        <p className="mb-3 text-sm leading-6 text-slate-600">
          将删除报告编号 <span className="font-semibold text-slate-900">{deleting?.report_number}</span> 及其上传文件和定稿版本，
          该编号随即可以重新使用。此操作不可撤销。
        </p>
        <p className="mb-3 text-xs leading-5 text-slate-500">已签署、已发布或处于证据保全中的文档不会被删除。</p>
        {remove.isError ? <div className="mt-3"><ErrorNotice error={remove.error} fallback="草稿删除失败" /></div> : null}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setDeleting(null)}>取消</Button>
          <Button
            variant="primary"
            className="border-red-200 bg-red-600 text-white hover:bg-red-700"
            disabled={remove.isPending}
            onClick={() => remove.mutate()}
          >
            {remove.isPending ? <Loader2 className="size-4 animate-spin" /> : <Trash2 className="size-4" />}
            确认删除
          </Button>
        </div>
      </Modal>
    </PageShell>
  )
}

function StageBadge({ document }: { document: SigningDocument }) {
  const label = stageLabels[document.stage] ?? document.stage
  const tone = document.stage === 'published' || document.stage === 'all_signed'
    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
    : document.stage === 'cancelled' || document.stage === 'failed'
      ? 'border-slate-200 bg-slate-50 text-slate-600'
      : 'border-sky-200 bg-sky-50 text-sky-800'

  return (
    <div className="flex flex-col gap-1">
      <span className={`inline-flex w-fit items-center gap-1 rounded border px-1.5 py-0.5 text-xs ${tone}`}>
        <FileText className="size-3" />
        {label}
      </span>
      {document.has_running_work ? (
        <span className="inline-flex w-fit items-center gap-1 text-[11px] text-amber-700">
          <Clock className="size-3" />
          有任务进行中
        </span>
      ) : null}
    </div>
  )
}

function SignerRow({ signer }: { signer: DocumentSigner }) {
  const done = signer.status === 'signed'
  const stopped = signer.status === 'rejected' || signer.status === 'cancelled'

  return (
    <div className="flex items-center gap-1.5 text-xs">
      {done ? <CheckCircle2 className="size-3.5 text-emerald-600" /> : null}
      {stopped ? <XCircle className="size-3.5 text-red-600" /> : null}
      {!done && !stopped ? <Clock className="size-3.5 text-slate-400" /> : null}
      <span className="text-slate-500">{signer.semantic_role ? roleLabels[signer.semantic_role] ?? signer.semantic_role : `第 ${signer.sequence} 步`}</span>
      <span className="font-medium text-slate-800">{signer.assigned_user_name ?? '—'}</span>
      <span className={done ? 'text-emerald-700' : stopped ? 'text-red-700' : 'text-slate-400'}>
        {signerStatusLabels[signer.status] ?? signer.status}
      </span>
    </div>
  )
}
