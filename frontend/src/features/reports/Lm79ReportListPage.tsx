import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { Download, Plus, RefreshCw, Search, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, PaginationControls, Panel } from '../system/shared'
import { formatDateTime, inputClass, type PaginationMeta } from '../system/utils'
import { previewReportPdf, type ReportSummary } from './lm79Api'

type Filters = { search: string; status: '' | 'draft' | 'submitted' }
const emptyFilters: Filters = { search: '', status: '' }

export function Lm79ReportListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [filters, setFilters] = useState<Filters>(emptyFilters)
  const [applied, setApplied] = useState<Filters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [deleting, setDeleting] = useState<ReportSummary | null>(null)
  const query = useQuery({
    queryKey: ['lm79-reports', applied.search, applied.status, page, perPage],
    queryFn: async () => (await api.get<{ data: ReportSummary[]; meta: PaginationMeta }>('/api/lm79-reports', {
      params: { search: applied.search || undefined, status: applied.status || undefined, page, per_page: perPage },
    })).data,
  })
  const rows = query.data?.data ?? []
  const remove = useMutation({
    mutationFn: async (id: number) => { await api.delete(`/api/lm79-reports/${id}`) },
    onSuccess: async () => {
      setDeleting(null)
      if (rows.length === 1 && page > 1) setPage(page - 1)
      await queryClient.invalidateQueries({ queryKey: ['lm79-reports'] })
    },
  })
  const download = useMutation({
    mutationFn: async (report: ReportSummary) => {
      const objectUrl = URL.createObjectURL(await previewReportPdf(report.id))
      const link = document.createElement('a')
      link.href = objectUrl
      link.download = `${report.report_number}.pdf`
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)
    },
  })

  function actions(report: ReportSummary) {
    return <div className="flex flex-wrap items-center justify-end gap-1">
      <Link className="inline-flex min-h-9 items-center rounded-md px-3 text-sm font-medium text-emerald-800 hover:bg-emerald-50" to="/reports/lm79/$reportId" params={{ reportId: String(report.id) }}>
        {report.locked ? '查看报告' : '编制报告'}
      </Link>
      {report.locked && report.document_uuid ? (
        <PermissionGate resource="pdf.workflow" action="create">
          <a className="inline-flex min-h-9 items-center rounded-md px-3 text-sm text-slate-600 hover:bg-slate-100" href={`/pdf/handwritten-signing?document=${encodeURIComponent(report.document_uuid)}#plan`}>签署流程</a>
        </PermissionGate>
      ) : null}
      {!report.locked ? <>
        <PermissionGate resource="lm79_reports" action="print">
          <Button variant="ghost" title="下载草稿 PDF" disabled={download.isPending} onClick={() => download.mutate(report)}>
            <Download className="size-4" /> PDF
          </Button>
        </PermissionGate>
        <PermissionGate resource="lm79_reports" action="delete">
          <Button variant="ghost" title="删除草稿" disabled={remove.isPending} onClick={() => { remove.reset(); setDeleting(report) }}>
            <Trash2 className="size-4" /> 删除
          </Button>
        </PermissionGate>
      </> : null}
    </div>
  }

  return <PageShell
    title="LM-79 检测报告"
    description="管理单样品报告的编制资料、原始文件与签署交接。"
    actions={<PermissionGate resource="lm79_reports" action="create"><Button variant="primary" onClick={() => void navigate({ to: '/reports/lm79/new' })}><Plus className="size-4" aria-hidden="true" />新建报告</Button></PermissionGate>}
  >
    <Panel title="报告查询">
      <form className="flex flex-wrap items-end gap-3" onSubmit={event => {
        event.preventDefault()
        setPage(1)
        setApplied({ ...filters, search: filters.search.trim() })
      }}>
        <Field label="关键词" className="min-w-0 flex-1 sm:min-w-72">
          <input className={inputClass} placeholder="报告编号、样品、产品、型号、委托方" value={filters.search} onChange={event => setFilters(current => ({ ...current, search: event.target.value }))} />
        </Field>
        <Field label="报告状态" className="w-full sm:w-44">
          <select className={inputClass} value={filters.status} onChange={event => setFilters(current => ({ ...current, status: event.target.value as Filters['status'] }))}>
            <option value="">全部状态</option><option value="draft">草稿</option><option value="submitted">已交接签署</option>
          </select>
        </Field>
        <Button variant="primary" type="submit"><Search className="size-4" />查询</Button>
        <Button variant="secondary" onClick={() => { setFilters(emptyFilters); setApplied(emptyFilters); setPage(1) }}>重置</Button>
      </form>
    </Panel>
    <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-slate-600">
      <span>共 <strong className="font-semibold text-slate-900">{query.data?.meta.total ?? 0}</strong> 份报告</span>
      <Button variant="ghost" disabled={query.isFetching} onClick={() => void query.refetch()}><RefreshCw className={`size-4 ${query.isFetching ? 'animate-spin' : ''}`} />刷新</Button>
    </div>
    {query.isPending ? <LoadingState label="正在加载报告" /> : null}
    {query.isError ? <ErrorNotice error={query.error} fallback="报告列表加载失败" /> : null}
    {download.isError ? <ErrorNotice error={download.error} fallback="PDF 下载失败" /> : null}
    {!query.isPending && !query.isError && rows.length === 0 ? <EmptyState title={applied.search || applied.status ? '没有符合条件的报告' : '暂无检测报告'} description={applied.search || applied.status ? '调整关键词或状态后重新查询。' : '从已接收的实际样品开始新建报告。'} /> : null}
    {rows.length > 0 ? <>
      <DataTable>
        <thead className="bg-slate-50 text-left text-xs font-medium text-slate-500"><tr>
          {['报告编号', '样品 / 委托单', '产品 / 型号', '委托方', '状态', '文件与光谱', '创建人 / 更新时间', '操作'].map(title => <th className={`px-3 py-3 ${title === '操作' ? 'text-right' : ''}`} key={title}>{title}</th>)}
        </tr></thead>
        <tbody className="divide-y divide-slate-100">{rows.map(report => <tr key={report.id} className="align-top transition-colors hover:bg-emerald-50/30">
          <td className="whitespace-nowrap px-3 py-3"><Link className="font-medium text-emerald-800 hover:underline" to="/reports/lm79/$reportId" params={{ reportId: String(report.id) }}>{report.report_number}</Link></td>
          <td className="px-3 py-3"><div className="whitespace-nowrap text-slate-900">{report.sample_no}</div><div className="mt-1 text-xs text-slate-500">{report.order_no || '—'}</div></td>
          <td className="max-w-64 px-3 py-3"><div className="break-words font-medium text-slate-900">{report.product_name || '—'}</div><div className="mt-1 break-words text-xs text-slate-500">{report.model || '—'}</div></td>
          <td className="max-w-56 break-words px-3 py-3 text-slate-700">{report.applicant || '—'}</td>
          <td className="whitespace-nowrap px-3 py-3"><ReportState locked={report.locked} /></td>
          <td className="whitespace-nowrap px-3 py-3 text-xs leading-6 text-slate-600">附件 {report.attachment_count} 个<br />光谱 {report.spectrum_point_count} 点</td>
          <td className="whitespace-nowrap px-3 py-3"><div className="text-slate-700">{report.created_by_name || '—'}</div><div className="mt-1 text-xs text-slate-500">{formatDateTime(report.updated_at)}</div></td>
          <td className="px-3 py-3">{actions(report)}</td>
        </tr>)}</tbody>
      </DataTable>
      <div className="space-y-3 md:hidden">{rows.map(report => <article key={report.id} className="min-w-0 rounded-lg border border-emerald-900/10 bg-white p-4">
        <div className="flex flex-wrap items-start justify-between gap-2"><Link className="min-w-0 break-all font-semibold text-emerald-800" to="/reports/lm79/$reportId" params={{ reportId: String(report.id) }}>{report.report_number}</Link><ReportState locked={report.locked} /></div>
        <p className="mt-3 break-words text-sm font-medium text-slate-900">{report.product_name || '—'} · {report.model || '—'}</p>
        <dl className="mt-3 grid grid-cols-[4rem_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
          <dt className="text-slate-500">样品</dt><dd className="min-w-0 break-all">{report.sample_no}</dd>
          <dt className="text-slate-500">委托单</dt><dd className="min-w-0 break-all">{report.order_no || '—'}</dd>
          <dt className="text-slate-500">委托方</dt><dd className="min-w-0 break-words">{report.applicant || '—'}</dd>
          <dt className="text-slate-500">数据</dt><dd>附件 {report.attachment_count} 个 · 光谱 {report.spectrum_point_count} 点</dd>
          <dt className="text-slate-500">创建人</dt><dd>{report.created_by_name || '—'}</dd>
          <dt className="text-slate-500">更新</dt><dd>{formatDateTime(report.updated_at)}</dd>
        </dl>
        <div className="mt-3 border-t border-slate-100 pt-3">{actions(report)}</div>
      </article>)}</div>
    </> : null}
    <PaginationControls meta={query.data?.meta} page={page} perPage={perPage} onPageChange={setPage} onPerPageChange={value => { setPerPage(value); setPage(1) }} />
    <Modal open={deleting !== null} title="删除报告草稿" onClose={() => { if (!remove.isPending) setDeleting(null) }} footer={<div className="flex justify-end gap-2"><Button variant="secondary" disabled={remove.isPending} onClick={() => setDeleting(null)}>取消</Button><Button variant="primary" disabled={remove.isPending || !deleting} onClick={() => { if (deleting) remove.mutate(deleting.id) }}>{remove.isPending ? '正在删除…' : '确认删除'}</Button></div>}>
      <p className="text-sm leading-6 text-slate-700">将删除报告 <strong>{deleting?.report_number}</strong>（样品 {deleting?.sample_no}）的草稿、光谱明细及附件。此操作无法撤销。</p>
      {remove.isError ? <div className="mt-3"><ErrorNotice error={remove.error} fallback="报告删除失败" /></div> : null}
    </Modal>
  </PageShell>
}

function ReportState({ locked }: { locked: boolean }) {
  return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${locked ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-600'}`}>{locked ? '已交接签署' : '草稿'}</span>
}
