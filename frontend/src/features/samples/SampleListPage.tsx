import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { ArrowLeftRight, Eye, FileText, HandCoins, PackageCheck, Printer, RotateCcw, Search, Undo2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { useCurrentUser } from '../auth/useCurrentUser'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, PaginationControls, Panel, StatusBadge } from '../system/shared'
import { type ApiCollection, type ApiResource, inputClass, paginationParams, textareaClass } from '../system/utils'
import { SampleFlowCardPrintArea, SampleFlowCardPrintStyles, SampleFlowLedgerTable, type SampleFlowCardData } from './SampleFlowCardPrintArea'
import { SampleLabelPrintArea, SampleLabelPrintStyles, type SampleLabelPreview } from './SampleLabelPrintArea'
import { visibleSampleListActions, type SampleListAction } from './sampleListActions'
import { sampleLabelSpec } from './sampleLabelSpec'

export type Sample = {
  id: number
  test_order_id: number
  test_order_sample_id?: number | null
  order_no?: string | null
  client_company?: string | null
  delivery_sequence: number
  sample_no: string
  sample_name: string
  specification?: string | null
  model?: string | null
  quantity: number
  status: 'pending' | 'testing' | 'completed' | 'retained' | 'returned' | 'scrapped' | 'outsourced' | 'outsource_returned' | 'abnormal'
  current_holder?: string | null
  current_location?: string | null
  storage_condition?: string | null
  received_date?: string | null
  appearance_check?: string | null
  remark?: string | null
  batch_no?: string | null
  sort_order: number
  delivery_received_count: number
}

type SampleFilters = {
  search: string
  status: string
  current_holder: string
}

const emptyFilters: SampleFilters = {
  search: '',
  status: '',
  current_holder: '',
}

export function SampleListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const currentUser = useCurrentUser()
  const [filters, setFilters] = useState<SampleFilters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [printLabels, setPrintLabels] = useState<SampleLabelPreview[]>([])
  const [shouldPrint, setShouldPrint] = useState(false)
  const [printingId, setPrintingId] = useState<number | null>(null)
  const [flowRecordsSample, setFlowRecordsSample] = useState<Sample | null>(null)
  const [returnClientSample, setReturnClientSample] = useState<Sample | null>(null)
  const [returnClientRemark, setReturnClientRemark] = useState('')
  const samplesQuery = useQuery({
    queryKey: ['samples', filters, page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<Sample>>('/api/samples', { params: cleanParams({ ...filters, ...paginationParams(page, perPage) }) })

      return response.data
    },
  })
  const flowCardQuery = useQuery({
    queryKey: ['sample-flow-card', flowRecordsSample?.id],
    enabled: flowRecordsSample !== null,
    queryFn: async () => {
      if (!flowRecordsSample) {
        throw new Error('sample_required')
      }

      const response = await api.get<ApiResource<SampleFlowCardData>>(`/api/samples/${flowRecordsSample.id}/flow-card`)

      return response.data.data
    },
  })
  const printSamples = useMutation({
    mutationFn: async (sampleIds: number[]) => {
      const response = await api.post<{ data: SampleLabelPreview[] }>('/api/sample-labels/preview', {
        sample_ids: sampleIds,
        label_width_mm: sampleLabelSpec.widthMm,
        label_height_mm: sampleLabelSpec.heightMm,
      })

      return response.data.data
    },
    onSuccess: (labels) => {
      setPrintLabels(labels)
      setShouldPrint(true)
    },
    onSettled: () => setPrintingId(null),
  })
  const quickFlow = useMutation({
    mutationFn: async (input: { sampleId: number; payload: Record<string, string> }) => {
      await api.post(`/api/samples/${input.sampleId}/scan-flow`, input.payload)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['samples'] })
    },
  })
  const returnClientFlow = useMutation({
    mutationFn: async (input: { sampleId: number; remark?: string }) => {
      await api.post(`/api/samples/${input.sampleId}/flows`, {
        action_type: 'return_client',
        ...(input.remark?.trim() ? { remark: input.remark.trim() } : {}),
      })
    },
    onSuccess: async () => {
      setReturnClientSample(null)
      setReturnClientRemark('')
      await queryClient.invalidateQueries({ queryKey: ['samples'] })
    },
  })
  const samples = samplesQuery.data?.data ?? []

  function claimSample(sample: Sample) {
    const holder = currentUser.data?.name?.trim() || zhText('Operator') || '操作人'

    quickFlow.mutate({ sampleId: sample.id, payload: { action_type: 'lend', holder_to: holder } })
  }

  function goToDetail(sample: Sample) {
    void navigate({ to: '/samples/$sampleId', params: { sampleId: String(sample.id) } })
  }

  function returnClient(sample: Sample) {
    setReturnClientSample(sample)
    setReturnClientRemark('')
  }

  function confirmReturnClient() {
    if (!returnClientSample) {
      return
    }

    returnClientFlow.mutate({ sampleId: returnClientSample.id, remark: returnClientRemark })
  }

  function renderFlowActionButtons(sample: Sample, actions: SampleListAction[]) {
    return (
      <PermissionGate resource="sample_flows" action="create">
        {actions.includes('lend') ? (
          <Button variant="secondary" disabled={quickFlow.isPending} onClick={() => claimSample(sample)}>
            <HandCoins className="size-4" aria-hidden="true" />
            领样
          </Button>
        ) : null}
        {actions.includes('transfer') ? (
          <Button variant="secondary" onClick={() => goToDetail(sample)}>
            <ArrowLeftRight className="size-4" aria-hidden="true" />
            流转
          </Button>
        ) : null}
        {actions.includes('return_room') ? (
          <PermissionGate resource="sample_flows" action="return_room">
            <Button variant="secondary" onClick={() => goToDetail(sample)}>
              <Undo2 className="size-4" aria-hidden="true" />
              归还
            </Button>
          </PermissionGate>
        ) : null}
        {actions.includes('receive_back') ? (
          <Button variant="secondary" onClick={() => goToDetail(sample)}>
            <PackageCheck className="size-4" aria-hidden="true" />
            外发退回
          </Button>
        ) : null}
        {actions.includes('return_client') ? (
          <Button variant="danger" onClick={() => returnClient(sample)}>
            <RotateCcw className="size-4" aria-hidden="true" />
            退客户
          </Button>
        ) : null}
      </PermissionGate>
    )
  }

  useEffect(() => {
    if (!shouldPrint || printLabels.length === 0) {
      return
    }

    const timeout = window.setTimeout(() => {
      window.print()
      setShouldPrint(false)
    })

    return () => window.clearTimeout(timeout)
  }, [printLabels, shouldPrint])

  function toggleSelected(id: number) {
    setSelectedIds((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]))
  }

  function printSingle(sample: Sample) {
    setPrintingId(sample.id)
    printSamples.mutate([sample.id])
  }

  return (
    <PageShell
      title="样品信息"
      description="跟踪已接收样品、当前持有人、位置和流转记录。"
      actions={
        <>
          <PermissionGate resource="sample_labels" action="print">
            <Button variant="secondary" disabled={selectedIds.length === 0} onClick={() => printSamples.mutate(selectedIds)}>
              <Printer className="size-4" aria-hidden="true" />
              样品标签 ({selectedIds.length})
            </Button>
          </PermissionGate>
          <PermissionGate resource="samples" action="receive">
            <Button variant="primary" onClick={() => void navigate({ to: '/samples/receive' })}>
              <PackageCheck className="size-4" aria-hidden="true" />
              接收样品
            </Button>
          </PermissionGate>
        </>
      }
    >
      <SampleLabelPrintStyles />
      <Panel title="筛选条件">
        <div className="grid gap-3 md:grid-cols-3">
          <Field label="搜索">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input
                className={`${inputClass} pl-9`}
                value={filters.search}
                onChange={(event) => setFilters({ ...filters, search: event.target.value })}
                placeholder="样品编号、名称、型号"
              />
            </div>
          </Field>
          <Field label="状态">
            <select className={inputClass} value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
              <option value="">全部</option>
              {['pending', 'testing', 'completed', 'retained', 'returned', 'scrapped', 'outsourced', 'outsource_returned', 'abnormal'].map((status) => (
                <option value={status} key={status}>
                  {zhText(status)}
                </option>
              ))}
            </select>
          </Field>
          <Field label="当前持有人">
            <input className={inputClass} value={filters.current_holder} onChange={(event) => setFilters({ ...filters, current_holder: event.target.value })} placeholder="输入持有人姓名" />
          </Field>
        </div>
      </Panel>

      {samplesQuery.isError ? <ErrorNotice error={samplesQuery.error} fallback="无法加载样品" /> : null}
      {printSamples.error ? <ErrorNotice error={printSamples.error} fallback="无法生成样品标签" /> : null}
      {quickFlow.error ? <ErrorNotice error={quickFlow.error} fallback="无法完成样品流转" /> : null}
      {samplesQuery.isPending ? <LoadingState label="正在加载样品" /> : null}
      {!samplesQuery.isPending && samples.length === 0 ? <EmptyState title="未找到样品" description="请先从委托单接收样品，再跟踪流转记录。" /> : null}
      {samples.length > 0 ? (
        <>
          <DataTable>
            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">选择</th>
                <th className="px-3 py-2 font-medium">样品编号</th>
                <th className="px-3 py-2 font-medium">样品名称</th>
                <th className="px-3 py-2 font-medium">委托单号</th>
                <th className="px-3 py-2 font-medium">状态</th>
                <th className="px-3 py-2 font-medium">备注</th>
                <th className="px-3 py-2 font-medium">持有人</th>
                <th className="px-3 py-2 font-medium">位置</th>
                <th className="px-3 py-2 font-medium">操作</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {samples.map((sample) => {
                const actions = visibleSampleListActions(sample)

                return (
                  <tr key={sample.id}>
                    <td className="px-3 py-3">
                      <input
                        className="size-4 rounded border-slate-300 text-emerald-600"
                        type="checkbox"
                        checked={selectedIds.includes(sample.id)}
                        onChange={() => toggleSelected(sample.id)}
                      />
                    </td>
                    <td className="px-3 py-3 text-sm font-medium text-slate-900">{sample.sample_no}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{sample.sample_name}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{sample.order_no ?? '-'}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">
                      <StatusBadge status={sample.status} />
                    </td>
                    <td className="max-w-48 truncate px-3 py-3 text-sm text-slate-700" title={sample.remark ?? undefined}>{sample.remark ?? '-'}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{sample.current_holder ?? '-'}</td>
                    <td className="px-3 py-3 text-sm text-slate-700">{sample.current_location ?? '-'}</td>
                    <td className="px-3 py-3">
                      <div className="flex flex-wrap gap-2">
                        {renderFlowActionButtons(sample, actions)}
                        <PermissionGate resource="sample_flows" action="read">
                          <Button variant="secondary" onClick={() => setFlowRecordsSample(sample)}>
                            <FileText className="size-4" aria-hidden="true" />
                            流转卡
                          </Button>
                        </PermissionGate>
                        <PermissionGate resource="sample_labels" action="print">
                          <Button variant="secondary" disabled={printingId === sample.id} onClick={() => printSingle(sample)}>
                            <Printer className="size-4" aria-hidden="true" />
                            打印
                          </Button>
                        </PermissionGate>
                        <Button variant="secondary" onClick={() => goToDetail(sample)}>
                          <Eye className="size-4" aria-hidden="true" />
                          查看
                        </Button>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </DataTable>
          <div className="space-y-3 md:hidden">
            {samples.map((sample) => {
              const actions = visibleSampleListActions(sample)

              return (
                <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" key={sample.id}>
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <h2 className="truncate text-sm font-semibold text-slate-950">{sample.sample_no}</h2>
                      <p className="truncate text-xs text-slate-500">{sample.sample_name}</p>
                    </div>
                    <StatusBadge status={sample.status} />
                  </div>
                  <div className="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-600">
                    <MobileDetail label="委托单号" value={sample.order_no} />
                    <MobileDetail label="备注" value={sample.remark} />
                    <MobileDetail label="持有人" value={sample.current_holder} />
                    <MobileDetail label="位置" value={sample.current_location} />
                  </div>
                  <div className="mt-3 flex flex-wrap gap-2">
                    {renderFlowActionButtons(sample, actions)}
                    <PermissionGate resource="sample_flows" action="read">
                      <Button variant="secondary" onClick={() => setFlowRecordsSample(sample)}>
                        <FileText className="size-4" aria-hidden="true" />
                        流转卡
                      </Button>
                    </PermissionGate>
                    <Button variant="secondary" onClick={() => void navigate({ to: '/samples/$sampleId', params: { sampleId: String(sample.id) } })}>
                      <Eye className="size-4" aria-hidden="true" />
                      查看
                    </Button>
                  </div>
                </article>
              )
            })}
          </div>
        </>
      ) : null}
      <PaginationControls
        meta={samplesQuery.data?.meta}
        page={page}
        perPage={perPage}
        onPageChange={setPage}
        onPerPageChange={(nextPerPage) => {
          setPerPage(nextPerPage)
          setPage(1)
        }}
      />
      <SampleFlowRecordsModal
        card={flowCardQuery.data}
        error={flowCardQuery.error}
        isLoading={flowCardQuery.isPending && flowRecordsSample !== null}
        onClose={() => setFlowRecordsSample(null)}
        open={flowRecordsSample !== null}
      />
      <ReturnClientModal
        error={returnClientFlow.error}
        isPending={returnClientFlow.isPending}
        onClose={() => setReturnClientSample(null)}
        onConfirm={confirmReturnClient}
        open={returnClientSample !== null}
        remark={returnClientRemark}
        sample={returnClientSample}
        setRemark={setReturnClientRemark}
      />
      <SampleLabelPrintArea labels={printLabels} />
    </PageShell>
  )
}

function MobileDetail({ label, value }: { label: string; value?: string | null }) {
  return (
    <div className="min-w-0">
      <div className="text-slate-500">{label}</div>
      <div className="mt-0.5 truncate font-medium text-slate-900">{value || '-'}</div>
    </div>
  )
}

function ReturnClientModal({
  error,
  isPending,
  onClose,
  onConfirm,
  open,
  remark,
  sample,
  setRemark,
}: {
  error: unknown
  isPending: boolean
  onClose: () => void
  onConfirm: () => void
  open: boolean
  remark: string
  sample: Sample | null
  setRemark: (remark: string) => void
}) {
  return (
    <Modal open={open} title={sample ? `退客户 - ${sample.sample_no}` : '退客户'} description="确认退还客户后，系统会追加样品流转记录。" onClose={onClose}>
      <div className="space-y-4">
        {error ? <ErrorNotice error={error} fallback="退客户操作失败" /> : null}
        <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm leading-6 text-amber-900">
          确认后样品状态将变更为{zhText('returned')}，当前持有人将变更为客户。
        </div>
        <Field label="备注">
          <textarea className={textareaClass} value={remark} onChange={(event) => setRemark(event.target.value)} placeholder="填写客户签收人、交接单号或其他说明" />
        </Field>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={isPending}>
            取消
          </Button>
          <Button variant="danger" onClick={onConfirm} disabled={isPending}>
            确认退客户
          </Button>
        </div>
      </div>
    </Modal>
  )
}

export function SampleFlowRecordsModal({
  card,
  error,
  isLoading,
  onClose,
  open,
}: {
  card?: SampleFlowCardData
  error: unknown
  isLoading: boolean
  onClose: () => void
  open: boolean
}) {
  return (
    <Modal
      title="流转记录"
      size="wide"
      actions={
        card ? (
          <Button variant="secondary" onClick={() => window.print()}>
            <Printer className="size-4" aria-hidden="true" />
            打印流转卡
          </Button>
        ) : null
      }
      open={open}
      onClose={onClose}
    >
      {card ? (
        <>
          <SampleFlowCardPrintStyles />
          <SampleFlowCardPrintArea card={card} screenHidden />
        </>
      ) : null}
      <div className="space-y-3">
        {isLoading ? <LoadingState label="正在加载样品流转记录" /> : null}
        {error ? <ErrorNotice error={error} fallback="无法加载样品流转记录" /> : null}
        {card ? <SampleFlowLedgerTable flows={card.flows} sample={card.sample} /> : null}
      </div>
    </Modal>
  )
}

function cleanParams(filters: Record<string, string | number>) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
}
