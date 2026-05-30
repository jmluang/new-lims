import { useQuery } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { Eye, PackageCheck, Search } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, PageShell, Panel, StatusBadge } from '../system/shared'
import { type ApiCollection, inputClass } from '../system/utils'

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
  const [filters, setFilters] = useState<SampleFilters>(emptyFilters)
  const samplesQuery = useQuery({
    queryKey: ['samples', filters],
    queryFn: async () => {
      const response = await api.get<ApiCollection<Sample>>('/api/samples', { params: cleanParams(filters) })

      return response.data
    },
  })
  const samples = samplesQuery.data?.data ?? []

  return (
    <PageShell
      title="Samples"
      description="Track received physical samples, holder, location and flow records."
      actions={
        <PermissionGate resource="samples" action="receive">
          <Button variant="primary" onClick={() => void navigate({ to: '/samples/receive' })}>
            <PackageCheck className="size-4" aria-hidden="true" />
            Receive samples
          </Button>
        </PermissionGate>
      }
    >
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
      {samplesQuery.isPending ? <LoadingState label="Loading samples" /> : null}
      {!samplesQuery.isPending && samples.length === 0 ? <EmptyState title="No samples found" description="Receive samples from a test order before tracking flows." /> : null}
      {samples.length > 0 ? (
        <>
          <DataTable>
            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
              <tr>
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
                  <td className="px-3 py-3 text-sm font-medium text-slate-900">{sample.sample_no}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{sample.sample_name}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{sample.order_no ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">
                    <StatusBadge status={sample.status} />
                  </td>
                  <td className="px-3 py-3 text-sm text-slate-700">{sample.current_holder ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{sample.current_location ?? '-'}</td>
                  <td className="px-3 py-3">
                    <Button variant="secondary" onClick={() => void navigate({ to: '/samples/$sampleId', params: { sampleId: String(sample.id) } })}>
                      <Eye className="size-4" aria-hidden="true" />
                      View
                    </Button>
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
    </PageShell>
  )
}

function cleanParams(filters: SampleFilters) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
}
