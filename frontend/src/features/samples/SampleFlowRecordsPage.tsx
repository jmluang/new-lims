import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { Eye, RotateCcw, Search } from 'lucide-react'
import { useState } from 'react'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, PageShell, PaginationControls, Panel, StatusBadge } from '../system/shared'
import { type ApiCollection, inputClass } from '../system/utils'
import { buildSampleFlowRecordParams, emptySampleFlowRecordFilters, type SampleFlowRecordFilters } from './sampleFlowRecordPageState'

type SampleFlowRecord = {
  id: number
  sample_id: number
  action_type: string
  action_by?: number | null
  action_by_name?: string | null
  action_time?: string | null
  holder_from?: string | null
  holder_to?: string | null
  location_from?: string | null
  location_to?: string | null
  remark?: string | null
  sample?: {
    id: number
    sample_no: string
    sample_name?: string | null
    model?: string | null
    order_no?: string | null
    client_company?: string | null
    status?: string | null
    current_holder?: string | null
    current_location?: string | null
  } | null
}

const actionTypes = ['lend', 'transfer', 'return_room', 'send_out', 'receive_back', 'return_client', 'scrap', 'position_change']

export function SampleFlowRecordsPage() {
  const [filters, setFilters] = useState<SampleFlowRecordFilters>(emptySampleFlowRecordFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const recordsQuery = useQuery({
    queryKey: ['sample-flow-records', filters, page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<SampleFlowRecord>>('/api/sample-flows', {
        params: buildSampleFlowRecordParams(filters, page, perPage),
      })

      return response.data
    },
  })
  const records = recordsQuery.data?.data ?? []

  function updateFilters(nextFilters: SampleFlowRecordFilters) {
    setFilters(nextFilters)
    setPage(1)
  }

  return (
    <PageShell title="样品流转记录" description="查看全部样品的领用、转交、归还、送外部等流转历史。">
      <Panel title="Filters">
        <div className="grid gap-3 md:grid-cols-5">
          <Field label="样品编号/名称/委托单">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input
                className={`${inputClass} pl-9`}
                value={filters.search}
                onChange={(event) => updateFilters({ ...filters, search: event.target.value })}
                placeholder="样品编号/名称/型号/委托单"
              />
            </div>
          </Field>
          <Field label="流转动作">
            <select className={inputClass} value={filters.action_type} onChange={(event) => updateFilters({ ...filters, action_type: event.target.value })}>
              <option value="">{zhText('All')}</option>
              {actionTypes.map((action) => (
                <option value={action} key={action}>
                  {zhText(action)}
                </option>
              ))}
            </select>
          </Field>
          <Field label="开始日期">
            <input className={inputClass} type="date" value={filters.action_time_from} onChange={(event) => updateFilters({ ...filters, action_time_from: event.target.value })} />
          </Field>
          <Field label="结束日期">
            <input className={inputClass} type="date" value={filters.action_time_to} onChange={(event) => updateFilters({ ...filters, action_time_to: event.target.value })} />
          </Field>
          <div className="flex items-end">
            <Button variant="secondary" onClick={() => updateFilters(emptySampleFlowRecordFilters)}>
              <RotateCcw className="size-4" aria-hidden="true" />
              重置
            </Button>
          </div>
        </div>
      </Panel>

      {recordsQuery.isError ? <ErrorNotice error={recordsQuery.error} fallback="无法加载样品流转记录" /> : null}
      {recordsQuery.isPending ? <LoadingState label="正在加载样品流转记录" /> : null}
      {!recordsQuery.isPending && records.length === 0 ? <EmptyState title="暂无样品流转记录" description="样品发生领用、转交或归还后，会在这里显示全部历史。" /> : null}
      {records.length > 0 ? (
        <>
          <DataTable>
            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">时间</th>
                <th className="px-3 py-2 font-medium">样品编号</th>
                <th className="px-3 py-2 font-medium">样品名称</th>
                <th className="px-3 py-2 font-medium">委托单号</th>
                <th className="px-3 py-2 font-medium">样品状态</th>
                <th className="px-3 py-2 font-medium">流转类型</th>
                <th className="px-3 py-2 font-medium">原持有人</th>
                <th className="px-3 py-2 font-medium">持有人</th>
                <th className="px-3 py-2 font-medium">原位置</th>
                <th className="px-3 py-2 font-medium">现位置</th>
                <th className="px-3 py-2 font-medium">操作人</th>
                <th className="px-3 py-2 font-medium">操作</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {records.map((record) => (
                <tr key={record.id}>
                  <td className="px-3 py-3 text-sm text-slate-700">{record.action_time ?? '-'}</td>
                  <td className="px-3 py-3 text-sm font-medium text-slate-900">{record.sample?.sample_no ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{record.sample?.sample_name ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{record.sample?.order_no ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">
                    <StatusBadge status={record.sample?.status} />
                  </td>
                  <td className="px-3 py-3 text-sm text-slate-700">{zhText(record.action_type)}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{record.holder_from ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{record.holder_to ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{record.location_from ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{record.location_to ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{record.action_by_name ?? '-'}</td>
                  <td className="px-3 py-3">
                    <SampleDetailLink sampleId={record.sample_id} />
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          <div className="space-y-3 md:hidden">
            {records.map((record) => (
              <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" key={record.id}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h2 className="truncate text-sm font-semibold text-slate-950">{record.sample?.sample_no ?? '-'}</h2>
                    <p className="truncate text-xs text-slate-500">{record.sample?.sample_name ?? '-'}</p>
                  </div>
                  <StatusBadge status={record.sample?.status} />
                </div>
                <div className="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-600">
                  <MobileDetail label="时间" value={record.action_time} />
                  <MobileDetail label="流转类型" value={zhText(record.action_type)} />
                  <MobileDetail label="原持有人" value={record.holder_from} />
                  <MobileDetail label="持有人" value={record.holder_to} />
                  <MobileDetail label="原位置" value={record.location_from} />
                  <MobileDetail label="现位置" value={record.location_to} />
                </div>
                <div className="mt-3">
                  <SampleDetailLink sampleId={record.sample_id} />
                </div>
              </article>
            ))}
          </div>
        </>
      ) : null}
      <PaginationControls
        meta={recordsQuery.data?.meta}
        page={page}
        perPage={perPage}
        onPageChange={setPage}
        onPerPageChange={(nextPerPage) => {
          setPerPage(nextPerPage)
          setPage(1)
        }}
      />
    </PageShell>
  )
}

function SampleDetailLink({ sampleId }: { sampleId: number }) {
  return (
    <Link className="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-emerald-900/15 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-emerald-50 hover:text-emerald-800" to="/samples/$sampleId" params={{ sampleId: String(sampleId) }}>
      <Eye className="size-4" aria-hidden="true" />
      查看
    </Link>
  )
}

function MobileDetail({ label, value }: { label: string; value?: string | number | null }) {
  return (
    <div className="min-w-0">
      <div className="text-slate-500">{label}</div>
      <div className="mt-0.5 truncate font-medium text-slate-900">{value || '-'}</div>
    </div>
  )
}
