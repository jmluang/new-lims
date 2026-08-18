import { useQuery } from '@tanstack/react-query'
import { Eye, Search } from 'lucide-react'
import { useState } from 'react'
import { api } from '../../lib/api'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, PaginationControls, Panel } from '../system/shared'
import { formatBytes, formatDateTime, inputClass, type ApiCollection, type ApiResource } from '../system/utils'
import { digestLabels } from './api'

const coverFieldLabels: Record<string, string> = {
  // Qualified, because the ledger's own 报告编号 is the confirmed one and these
  // two can disagree — extraction has returned a whole labelled cover line.
  report_number: '报告编号（识别）',
  product_name: '产品名称',
  model_specification: '规格型号',
  entrust_company: '委托单位',
  test_items: '检测项目',
  report_date: '报告日期',
}

type PdfFileRow = {
  id: number
  file_id: string
  file_name: string
  sha256_hash: string
  md5_hash?: string | null
  file_size?: number | null
  cover_report_number?: string | null
  cover_fields?: Record<string, string | null> | null
  signed: boolean
  created_by?: string | null
  signed_at?: string | null
  has_file: boolean
}

type Filters = {
  search: string
  created_by: string
  signed_from: string
  signed_to: string
}

const emptyFilters: Filters = { search: '', created_by: '', signed_from: '', signed_to: '' }

/**
 * 签章台账 — the record every verification is checked against. Pasting a SHA-256
 * or MD5 into the search box looks the digest up directly.
 */
export function PdfFileListPage() {
  const [filters, setFilters] = useState<Filters>(emptyFilters)
  const [applied, setApplied] = useState<Filters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [detailId, setDetailId] = useState<number | null>(null)

  const detailQuery = useQuery({
    queryKey: ['pdf', 'files', 'detail', detailId],
    enabled: detailId !== null,
    queryFn: async () => {
      const response = await api.get<ApiResource<PdfFileRow & { metadata?: Record<string, unknown> }>>(
        `/api/pdf/files/${detailId}`,
      )

      return response.data.data
    },
  })

  const filesQuery = useQuery({
    queryKey: ['pdf', 'files', applied, page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<PdfFileRow>>('/api/pdf/files', {
        params: { ...applied, page, per_page: perPage },
      })

      return response.data
    },
  })

  function applyFilters() {
    setApplied(filters)
    setPage(1)
  }

  const rows = filesQuery.data?.data ?? []

  return (
    <PageShell title="签章台账" description="所有经本系统签章的 PDF 记录，验证时即以此处登记的摘要为准。">
      <Panel title="筛选">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="文件名 / 编号 / 摘要">
            <input
              className={inputClass}
              value={filters.search}
              placeholder="支持粘贴 SHA-256 或 MD5"
              onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))}
              onKeyDown={(event) => event.key === 'Enter' && applyFilters()}
            />
          </Field>
          <Field label="签发人">
            <input
              className={inputClass}
              value={filters.created_by}
              onChange={(event) => setFilters((current) => ({ ...current, created_by: event.target.value }))}
              onKeyDown={(event) => event.key === 'Enter' && applyFilters()}
            />
          </Field>
          <Field label="签发日期从">
            <input
              className={inputClass}
              type="date"
              value={filters.signed_from}
              onChange={(event) => setFilters((current) => ({ ...current, signed_from: event.target.value }))}
            />
          </Field>
          <Field label="签发日期至">
            <input
              className={inputClass}
              type="date"
              value={filters.signed_to}
              onChange={(event) => setFilters((current) => ({ ...current, signed_to: event.target.value }))}
            />
          </Field>
        </div>
        <div className="mt-3 flex gap-2">
          <Button variant="primary" onClick={applyFilters}>
            <Search className="size-4" aria-hidden="true" />
            查询
          </Button>
          <Button
            variant="secondary"
            onClick={() => {
              setFilters(emptyFilters)
              setApplied(emptyFilters)
              setPage(1)
            }}
          >
            重置
          </Button>
        </div>
      </Panel>

      {filesQuery.isError ? <ErrorNotice error={filesQuery.error} fallback="无法加载签章台账" /> : null}

      {filesQuery.isPending ? (
        <LoadingState label="正在加载签章台账" />
      ) : rows.length === 0 ? (
        <EmptyState title="暂无记录" description="完成一次 PDF 签章后，记录会出现在这里。" />
      ) : (
        <>
          <DataTable>
            <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-3 py-2">文件名</th>
                <th className="px-3 py-2">报告编号</th>
                <th className="px-3 py-2">SHA-256</th>
                <th className="px-3 py-2">大小</th>
                <th className="px-3 py-2">签发人</th>
                <th className="px-3 py-2">签发时间</th>
                <th className="px-3 py-2 text-right">操作</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((row) => (
                <tr key={row.id}>
                  <td className="max-w-64 truncate px-3 py-2 text-slate-900">{row.file_name}</td>
                  <td className="px-3 py-2 text-slate-700">{row.cover_report_number ?? '-'}</td>
                  <td className="px-3 py-2 font-mono text-xs text-slate-500" title={row.sha256_hash}>
                    {row.sha256_hash.slice(0, 16)}…
                  </td>
                  <td className="px-3 py-2 text-slate-700">{formatBytes(row.file_size)}</td>
                  <td className="px-3 py-2 text-slate-700">{row.created_by ?? '-'}</td>
                  <td className="px-3 py-2 text-slate-700">{formatDateTime(row.signed_at)}</td>
                  <td className="px-3 py-2 text-right">
                    <div className="flex justify-end gap-1">
                      <Button variant="ghost" onClick={() => setDetailId(row.id)}>
                        <Eye className="size-4" aria-hidden="true" />
                        详情
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>

          <div className="space-y-2 md:hidden">
            {rows.map((row) => (
              <article className="rounded-lg border border-emerald-900/10 bg-white p-3" key={row.id}>
                <p className="truncate text-sm font-medium text-slate-900">{row.file_name}</p>
                <p className="mt-1 text-xs text-slate-500">
                  {row.cover_report_number ? `${row.cover_report_number} · ` : ''}
                  {formatBytes(row.file_size)} · {formatDateTime(row.signed_at)}
                </p>
                <p className="mt-1 break-all font-mono text-[11px] text-slate-400">{row.sha256_hash}</p>
                <div className="mt-2 flex gap-2">
                  <Button variant="secondary" onClick={() => setDetailId(row.id)}>
                    <Eye className="size-4" aria-hidden="true" />
                    详情
                  </Button>
                </div>
              </article>
            ))}
          </div>

          <PaginationControls
            meta={filesQuery.data?.meta}
            page={page}
            perPage={perPage}
            onPageChange={setPage}
            onPerPageChange={(value) => {
              setPerPage(value)
              setPage(1)
            }}
          />
        </>
      )}

      {/*
        The full digests and the seal configuration used — what someone needs
        when a recipient disputes a report and quotes a hash over the phone.
      */}
      <Modal title="签章记录详情" size="wide" open={detailId !== null} onClose={() => setDetailId(null)}>
        {detailQuery.isPending ? (
          <LoadingState label="正在加载签章记录" />
        ) : detailQuery.isError ? (
          <ErrorNotice error={detailQuery.error} fallback="无法加载签章记录" />
        ) : detailQuery.data ? (
          <PdfFileDetail file={detailQuery.data} />
        ) : null}
      </Modal>
    </PageShell>
  )
}

function PdfFileDetail({ file }: { file: PdfFileRow & { metadata?: Record<string, unknown> } }) {
  const metadata = file.metadata ?? {}
  const photometric = metadata.photometric_content_removal as { status?: string } | undefined
  const functionStampIds = Array.isArray(metadata.function_stamp_ids) ? (metadata.function_stamp_ids as number[]) : []

  return (
    <div className="space-y-4">
      <section>
        <h3 className="text-sm font-semibold text-slate-900">文件</h3>
        <dl className="mt-2 space-y-2 text-sm">
          <DetailRow label="文件名" value={file.file_name} />
          <DetailRow label="文件编号" value={file.file_id} />
          <DetailRow label="报告编号" value={reportNumberLabel(file.cover_report_number, metadata.report_number_source)} />
          <DetailRow label="文件大小" value={formatBytes(file.file_size)} />
          <DetailRow label="签发人" value={file.created_by ?? '-'} />
          <DetailRow label="签发时间" value={formatDateTime(file.signed_at)} />
          <DetailRow label="数字签名" value={file.signed ? '已写入' : '未签名（未选择任何印章）'} />
        </dl>
      </section>

      <section>
        <h3 className="text-sm font-semibold text-slate-900">文件指纹</h3>
        <dl className="mt-2 space-y-2 text-sm">
          <div className="flex gap-2">
            <dt className="w-20 shrink-0 text-slate-500">{digestLabels.primary_hash}</dt>
            <dd className="min-w-0 break-all font-mono text-xs text-slate-700 select-all">{file.sha256_hash}</dd>
          </div>
          <div className="flex gap-2">
            <dt className="w-20 shrink-0 text-slate-500">{digestLabels.md5_hash}</dt>
            <dd className="min-w-0 break-all font-mono text-xs text-slate-700 select-all">{file.md5_hash ?? '-'}</dd>
          </div>
        </dl>
      </section>

      {file.cover_fields ? (
        <section>
          <h3 className="text-sm font-semibold text-slate-900">封面信息</h3>
          <dl className="mt-2 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            {Object.entries(coverFieldLabels).map(([key, label]) => (
              <DetailRow key={key} label={label} value={file.cover_fields?.[key] || '—'} />
            ))}
          </dl>
        </section>
      ) : null}

      <section>
        <h3 className="text-sm font-semibold text-slate-900">签章配置</h3>
        {/* Names are snapshotted at signing time, so this stays readable even
            after a seal configuration has been deleted. */}
        <dl className="mt-2 space-y-2 text-sm">
          <DetailRow label="声明页" value={configLabel(metadata.certificate_name, metadata.certificate_id, '未附加')} />
          <DetailRow label="首页盖章" value={configLabel(metadata.digital_signature_name, metadata.digital_signature_id, '未使用')} />
          <DetailRow label="骑缝章" value={configLabel(metadata.perforation_stamp_name, metadata.perforation_stamp_id, '未使用')} />
          <DetailRow label="功能章" value={functionStampLabel(metadata.function_stamp_names, functionStampIds)} />
          {photometric?.status && photometric.status !== 'not_requested' ? (
            <DetailRow label="光度数据" value="已处理" />
          ) : null}
        </dl>
      </section>
    </div>
  )
}

/**
 * The report number together with where it came from.
 *
 * Cover-page extraction has returned a whole labelled line ("产品名称:LED 面板灯")
 * as the number, so a record that shows one still has to say whether an
 * operator confirmed it — that is the difference between a number worth
 * searching the ledger by and a parsing accident. Records signed before the
 * confirmation field existed carry no source and are shown bare.
 */
function reportNumberLabel(reportNumber: string | null | undefined, source: unknown) {
  if (!reportNumber) {
    return '未登记'
  }

  switch (source) {
    case 'operator':
      return `${reportNumber}（人工确认）`
    case 'cover_extraction':
      return `${reportNumber}（封面识别）`
    default:
      return reportNumber
  }
}

/** Prefers the snapshotted name, falls back to the id for older records. */
function configLabel(name: unknown, id: unknown, emptyLabel: string) {
  if (typeof name === 'string' && name !== '') {
    return name
  }

  return id ? `#${id}` : emptyLabel
}

function functionStampLabel(names: unknown, ids: number[]) {
  if (Array.isArray(names) && names.length > 0) {
    return names.join('、')
  }

  return ids.length > 0 ? ids.map((id) => `#${id}`).join('、') : '未使用'
}

function DetailRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex gap-2">
      <dt className="w-20 shrink-0 text-slate-500">{label}</dt>
      <dd className="min-w-0 break-words text-slate-900">{value}</dd>
    </div>
  )
}
