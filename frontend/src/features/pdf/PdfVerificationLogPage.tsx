import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, Download, Eye, Search, XCircle } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, PaginationControls, Panel } from '../system/shared'
import { errorMessage, formatBytes, formatDateTime, inputClass, type ApiCollection, type ApiResource } from '../system/utils'
import { securityLevelLabel, type VerificationResultData } from './api'
import { VerificationResultCard } from './VerificationResultCard'

type VerificationLogRow = {
  id: number
  file_name: string
  file_size: number
  primary_hash?: string | null
  overall_valid: boolean
  security_level: string
  verification_message: string
  verify_source?: string | null
  ip_address?: string | null
  user?: { id: number; name: string } | null
  has_saved_file: boolean
  created_at?: string | null
}

type Filters = {
  search: string
  overall_valid: string
  security_level: string
  verify_source: string
  verified_from: string
  verified_to: string
}

const emptyFilters: Filters = {
  search: '',
  overall_valid: '',
  security_level: '',
  verify_source: '',
  verified_from: '',
  verified_to: '',
}

const securityLevels = ['very_high', 'high', 'medium', 'low', 'compromised']

const sourceLabels: Record<string, string> = { admin: '后台验证', public: '公开核验' }

/**
 * 验证日志 — including the failures. Repeated failed checks against the same
 * report are how a circulating forgery shows up.
 */
export function PdfVerificationLogPage() {
  const [filters, setFilters] = useState<Filters>(emptyFilters)
  const [applied, setApplied] = useState<Filters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [downloadError, setDownloadError] = useState<string | null>(null)
  const [detailId, setDetailId] = useState<number | null>(null)

  const detailQuery = useQuery({
    queryKey: ['pdf', 'verification-logs', detailId],
    enabled: detailId !== null,
    queryFn: async () => {
      const response = await api.get<ApiResource<VerificationLogRow & { verification_data?: VerificationResultData | null }>>(
        `/api/pdf/verification-logs/${detailId}`,
      )

      return response.data.data
    },
  })

  const logsQuery = useQuery({
    queryKey: ['pdf', 'verification-logs', applied, page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<VerificationLogRow>>('/api/pdf/verification-logs', {
        params: { ...applied, page, per_page: perPage },
      })

      return response.data
    },
  })

  async function download(row: VerificationLogRow) {
    setDownloadError(null)

    try {
      const response = await api.get<Blob>(`/api/pdf/verification-logs/${row.id}/download`, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = row.file_name
      anchor.click()

      // Revoked on a timer, not in the same task as the click: a browser that
      // has not yet taken ownership of the blob ends up with a cancelled
      // download, which reads as the button doing nothing.
      setTimeout(() => URL.revokeObjectURL(url), 10_000)
    } catch (caught) {
      setDownloadError(errorMessage(caught, '下载失败'))
    }
  }

  const rows = logsQuery.data?.data ?? []

  return (
    <PageShell title="验证日志" description="记录每一次 PDF 真伪核验，包括后台验证与公开核验的失败记录。">
      <Panel title="筛选">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <Field label="文件名 / 摘要">
            <input
              className={inputClass}
              value={filters.search}
              onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  setApplied(filters)
                  setPage(1)
                }
              }}
            />
          </Field>
          <Field label="验证结果">
            <select
              className={inputClass}
              value={filters.overall_valid}
              onChange={(event) => setFilters((current) => ({ ...current, overall_valid: event.target.value }))}
            >
              <option value="">全部</option>
              <option value="1">通过</option>
              <option value="0">失败</option>
            </select>
          </Field>
          <Field label="安全等级">
            <select
              className={inputClass}
              value={filters.security_level}
              onChange={(event) => setFilters((current) => ({ ...current, security_level: event.target.value }))}
            >
              <option value="">全部</option>
              {securityLevels.map((level) => (
                <option value={level} key={level}>
                  {securityLevelLabel(level)}
                </option>
              ))}
            </select>
          </Field>
          <Field label="来源">
            <select
              className={inputClass}
              value={filters.verify_source}
              onChange={(event) => setFilters((current) => ({ ...current, verify_source: event.target.value }))}
            >
              <option value="">全部</option>
              <option value="admin">后台验证</option>
              <option value="public">公开核验</option>
            </select>
          </Field>
          <Field label="验证日期从">
            <input
              className={inputClass}
              type="date"
              value={filters.verified_from}
              onChange={(event) => setFilters((current) => ({ ...current, verified_from: event.target.value }))}
            />
          </Field>
          <Field label="验证日期至">
            <input
              className={inputClass}
              type="date"
              value={filters.verified_to}
              onChange={(event) => setFilters((current) => ({ ...current, verified_to: event.target.value }))}
            />
          </Field>
        </div>
        <div className="mt-3 flex gap-2">
          <Button
            variant="primary"
            onClick={() => {
              setApplied(filters)
              setPage(1)
            }}
          >
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

      {downloadError ? <ErrorNotice error={new Error(downloadError)} /> : null}
      {logsQuery.isError ? <ErrorNotice error={logsQuery.error} fallback="无法加载验证日志" /> : null}

      {logsQuery.isPending ? (
        <LoadingState label="正在加载验证日志" />
      ) : rows.length === 0 ? (
        <EmptyState title="暂无验证记录" description="任何一次核验都会记录在这里。" />
      ) : (
        <>
          <DataTable>
            <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-3 py-2">结果</th>
                <th className="px-3 py-2">文件名</th>
                <th className="px-3 py-2">说明</th>
                <th className="px-3 py-2">安全等级</th>
                <th className="px-3 py-2">来源</th>
                <th className="px-3 py-2">验证人 / IP</th>
                <th className="px-3 py-2">时间</th>
                <th className="px-3 py-2 text-right">操作</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((row) => (
                <tr key={row.id}>
                  <td className="px-3 py-2">
                    {row.overall_valid ? (
                      <span className="inline-flex items-center gap-1 text-emerald-700">
                        <CheckCircle2 className="size-4" aria-hidden="true" />
                        通过
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 text-red-700">
                        <XCircle className="size-4" aria-hidden="true" />
                        失败
                      </span>
                    )}
                  </td>
                  <td className="max-w-56 truncate px-3 py-2 text-slate-900">{row.file_name}</td>
                  <td className="max-w-64 truncate px-3 py-2 text-slate-600">{row.verification_message}</td>
                  <td className="px-3 py-2 text-slate-700">{securityLevelLabel(row.security_level)}</td>
                  <td className="px-3 py-2 text-slate-700">{sourceLabels[row.verify_source ?? ''] ?? '-'}</td>
                  <td className="px-3 py-2 text-slate-600">{row.user?.name ?? row.ip_address ?? '-'}</td>
                  <td className="px-3 py-2 text-slate-700">{formatDateTime(row.created_at)}</td>
                  <td className="px-3 py-2 text-right">
                    <div className="flex justify-end gap-1">
                      <Button variant="ghost" onClick={() => setDetailId(row.id)}>
                        <Eye className="size-4" aria-hidden="true" />
                        详情
                      </Button>
                      <PermissionGate resource="pdf_verification_logs" action="download">
                        <Button variant="ghost" disabled={!row.has_saved_file} onClick={() => download(row)}>
                          <Download className="size-4" aria-hidden="true" />
                          下载
                        </Button>
                      </PermissionGate>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>

          <div className="space-y-2 md:hidden">
            {rows.map((row) => (
              <article className="rounded-lg border border-emerald-900/10 bg-white p-3" key={row.id}>
                <div className="flex items-center justify-between gap-2">
                  <p className="truncate text-sm font-medium text-slate-900">{row.file_name}</p>
                  <span className={row.overall_valid ? 'shrink-0 text-xs text-emerald-700' : 'shrink-0 text-xs text-red-700'}>
                    {row.overall_valid ? '通过' : '失败'}
                  </span>
                </div>
                <p className="mt-1 text-xs text-slate-600">{row.verification_message}</p>
                <p className="mt-1 text-xs text-slate-400">
                  {formatBytes(row.file_size)} · {sourceLabels[row.verify_source ?? ''] ?? '-'} · {formatDateTime(row.created_at)}
                </p>
                <Button className="mt-2" variant="secondary" onClick={() => setDetailId(row.id)}>
                  <Eye className="size-4" aria-hidden="true" />
                  详情
                </Button>
              </article>
            ))}
          </div>

          <PaginationControls
            meta={logsQuery.data?.meta}
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
        The stored verification payload is what a dispute is settled with — it
        holds the digests the file had at the time and what they were compared
        against, long after the file itself has moved on.
      */}
      <Modal title="验证详情" size="wide" open={detailId !== null} onClose={() => setDetailId(null)}>
        {detailQuery.isPending ? (
          <LoadingState label="正在加载验证详情" />
        ) : detailQuery.isError ? (
          <ErrorNotice error={detailQuery.error} fallback="无法加载验证详情" />
        ) : detailQuery.data ? (
          <div className="space-y-4">
            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
              <DetailRow label="文件名" value={detailQuery.data.file_name} />
              <DetailRow label="验证时间" value={formatDateTime(detailQuery.data.created_at)} />
              <DetailRow label="来源" value={sourceLabels[detailQuery.data.verify_source ?? ''] ?? '-'} />
              <DetailRow label="验证人" value={detailQuery.data.user?.name ?? '未登录访客'} />
              <DetailRow label="IP 地址" value={detailQuery.data.ip_address ?? '-'} />
              <DetailRow label="安全等级" value={securityLevelLabel(detailQuery.data.security_level)} />
            </dl>

            {detailQuery.data.verification_data ? (
              <VerificationResultCard result={detailQuery.data.verification_data} />
            ) : (
              <p className="text-sm text-slate-500">该记录没有保存完整的验证数据。</p>
            )}
          </div>
        ) : null}
      </Modal>
    </PageShell>
  )
}

function DetailRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex gap-2">
      <dt className="w-20 shrink-0 text-slate-500">{label}</dt>
      <dd className="min-w-0 break-words text-slate-900">{value}</dd>
    </div>
  )
}
