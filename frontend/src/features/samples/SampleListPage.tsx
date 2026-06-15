import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { ArrowLeftRight, Eye, FileText, HandCoins, PackageCheck, Printer, Search, Undo2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { useCurrentUser } from '../auth/useCurrentUser'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, PaginationControls, Panel, StatusBadge } from '../system/shared'
import { type ApiCollection, type ApiResource, inputClass, paginationParams } from '../system/utils'
import { SampleFlowCardPrintArea, SampleFlowCardPrintStyles, SampleFlowLedgerTable, type SampleFlowCardData } from './SampleFlowCardPrintArea'
import { SampleLabelPrintArea, SampleLabelPrintStyles, type SampleLabelPreview } from './SampleLabelPrintArea'
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
  const samples = samplesQuery.data?.data ?? []

  function claimSample(sample: Sample) {
    const holder = currentUser.data?.name?.trim() || zhText('Operator') || '操作人'

    quickFlow.mutate({ sampleId: sample.id, payload: { action_type: 'lend', holder_to: holder } })
  }

  function goToDetail(sample: Sample) {
    void navigate({ to: '/samples/$sampleId', params: { sampleId: String(sample.id) } })
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
      title="Samples"
      description="Track received physical samples, holder, location and flow records."
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
              Receive samples
            </Button>
          </PermissionGate>
        </>
      }
    >
      <SampleLabelPrintStyles />
      <Panel title="Filters">
        <div className="grid gap-3 md:grid-cols-3">
          <Field label="Search">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input
                className={`${inputClass} pl-9`}
                value={filters.search}
                onChange={(event) => setFilters({ ...filters, search: event.target.value })}
                placeholder={zhText('sample no, name, model') ?? undefined}
              />
            </div>
          </Field>
          <Field label="Status">
            <select className={inputClass} value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
              <option value="">{zhText('All')}</option>
              {['pending', 'testing', 'completed', 'retained', 'returned', 'scrapped', 'outsourced', 'outsource_returned', 'abnormal'].map((status) => (
                <option value={status} key={status}>
                  {zhText(status)}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Current holder">
            <input className={inputClass} value={filters.current_holder} onChange={(event) => setFilters({ ...filters, current_holder: event.target.value })} />
          </Field>
        </div>
      </Panel>

      {samplesQuery.isError ? <ErrorNotice error={samplesQuery.error} fallback="Unable to load samples" /> : null}
      {printSamples.error ? <ErrorNotice error={printSamples.error} fallback="Unable to create sample labels" /> : null}
      {quickFlow.error ? <ErrorNotice error={quickFlow.error} fallback="无法完成样品流转" /> : null}
      {samplesQuery.isPending ? <LoadingState label="Loading samples" /> : null}
      {!samplesQuery.isPending && samples.length === 0 ? <EmptyState title="No samples found" description="Receive samples from a test order before tracking flows." /> : null}
      {samples.length > 0 ? (
        <>
          <DataTable>
            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">选择</th>
                <th className="px-3 py-2 font-medium">Sample no</th>
                <th className="px-3 py-2 font-medium">Sample name</th>
                <th className="px-3 py-2 font-medium">Order no</th>
                <th className="px-3 py-2 font-medium">Status</th>
                <th className="px-3 py-2 font-medium">Holder</th>
                <th className="px-3 py-2 font-medium">Location</th>
                <th className="px-3 py-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {samples.map((sample) => (
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
                  <td className="px-3 py-3 text-sm text-slate-700">{sample.current_holder ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{sample.current_location ?? '-'}</td>
                  <td className="px-3 py-3">
                    <div className="flex flex-wrap gap-2">
                      <PermissionGate resource="sample_flows" action="create">
                        {sample.status === 'pending' && sample.current_holder === '样品室' ? (
                          <Button variant="secondary" disabled={quickFlow.isPending} onClick={() => claimSample(sample)}>
                            <HandCoins className="size-4" aria-hidden="true" />
                            领样
                          </Button>
                        ) : null}
                        {sample.status === 'testing' && sample.current_holder !== '样品室' ? (
                          <>
                            <Button variant="secondary" onClick={() => goToDetail(sample)}>
                              <ArrowLeftRight className="size-4" aria-hidden="true" />
                              流转
                            </Button>
                            <Button variant="secondary" onClick={() => goToDetail(sample)}>
                              <Undo2 className="size-4" aria-hidden="true" />
                              归还
                            </Button>
                          </>
                        ) : null}
                      </PermissionGate>
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
                        View
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          <div className="space-y-3 md:hidden">
            {samples.map((sample) => (
              <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" key={sample.id}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h2 className="truncate text-sm font-semibold text-slate-950">{sample.sample_no}</h2>
                    <p className="truncate text-xs text-slate-500">{sample.sample_name}</p>
                  </div>
                  <StatusBadge status={sample.status} />
                </div>
                <Button className="mt-3" variant="secondary" onClick={() => void navigate({ to: '/samples/$sampleId', params: { sampleId: String(sample.id) } })}>
                  <Eye className="size-4" aria-hidden="true" />
                  View
                </Button>
              </article>
            ))}
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
      <SampleLabelPrintArea labels={printLabels} screenHidden />
    </PageShell>
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
            {zhText('Print flow card')}
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
        {isLoading ? <LoadingState label="Loading sample flows" /> : null}
        {error ? <ErrorNotice error={error} fallback="Unable to load sample flows" /> : null}
        {card ? <SampleFlowLedgerTable flows={card.flows} sample={card.sample} /> : null}
      </div>
    </Modal>
  )
}

function cleanParams(filters: Record<string, string | number>) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
}
