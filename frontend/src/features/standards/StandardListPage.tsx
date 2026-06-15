import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { Download, Edit3, Eye, Plus, Search, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, PageShell, PaginationControls, Panel, StatusBadge } from '../system/shared'
import { type ApiCollection, inputClass, localDateInputValue, paginationParams } from '../system/utils'

export type FieldPermissionMeta = Record<string, { read?: boolean; update?: boolean; export?: boolean; hidden?: boolean }>

export type Standard = {
  id: number
  std_no: string
  chinese_name: string
  publish_date?: string | null
  implement_date?: string | null
  status: 'active' | 'pending' | 'abolished' | 'replaced' | 'disabled'
  abolish_date?: string | null
  replaced_by?: string | null
  corresponding_std?: string | null
  category?: string | null
  language?: string | null
  operator_id?: number | null
  catalogs?: StandardCatalog[]
  items?: StandardItem[]
  _field_permissions?: FieldPermissionMeta
}

export type StandardCatalog = {
  id: number
  standard_id: number
  parent_id?: number | null
  code: string
  name: string
  content?: string | null
  sort_order: number
}

export type StandardItem = {
  id: number
  standard_id: number
  item_no: string
  item_name: string
  requirement?: string | null
  unit?: string | null
  method?: string | null
  remark?: string | null
}

type StandardFilters = {
  search: string
  status: string
  category: string
  language: string
}

const emptyFilters: StandardFilters = {
  search: '',
  status: '',
  category: '',
  language: '',
}

export function StandardListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [filters, setFilters] = useState<StandardFilters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const standardsQuery = useQuery({
    queryKey: ['standards', filters, page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<Standard>>('/api/standards', { params: cleanParams({ ...filters, ...paginationParams(page, perPage) }) })

      return response.data
    },
  })
  const deleteStandard = useMutation({
    mutationFn: async (standard: Standard) => {
      await api.delete(`/api/standards/${standard.id}`)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['standards'] }),
  })
  const standards = standardsQuery.data?.data ?? []

  async function exportStandards() {
    const response = await api.get<{ headers: string[]; data: Record<string, unknown>[] }>('/api/standards/export', {
      params: cleanParams(filters),
    })
    const blob = new Blob([JSON.stringify(response.data, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = `standards-${localDateInputValue()}.json`
    link.click()
    URL.revokeObjectURL(url)
  }

  return (
    <PageShell
      title="Standard Library"
      description="Manage test standards, lifecycle status, catalogs and test items."
      actions={
        <>
          <PermissionGate resource="standards" action="export">
            <Button variant="secondary" onClick={() => void exportStandards()}>
              <Download className="size-4" aria-hidden="true" />
              Export
            </Button>
          </PermissionGate>
          <PermissionGate resource="standards" action="create">
            <Button variant="primary" onClick={() => void navigate({ to: '/standards/new' })}>
              <Plus className="size-4" aria-hidden="true" />
              New standard
            </Button>
          </PermissionGate>
        </>
      }
    >
      <Panel title="Filters">
        <div className="grid gap-3 md:grid-cols-4">
          <Field label="Search">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input
                className={`${inputClass} pl-9`}
                value={filters.search}
                onChange={(event) => setFilters({ ...filters, search: event.target.value })}
                placeholder={zhText('standard number, name') ?? undefined}
              />
            </div>
          </Field>
          <Field label="Status">
            <select className={inputClass} value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
              <option value="">{zhText('All')}</option>
              {['active', 'pending', 'abolished', 'replaced', 'disabled'].map((status) => (
                <option value={status} key={status}>
                  {zhText(status)}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Category">
            <input className={inputClass} value={filters.category} onChange={(event) => setFilters({ ...filters, category: event.target.value })} />
          </Field>
          <Field label="Language">
            <input className={inputClass} value={filters.language} onChange={(event) => setFilters({ ...filters, language: event.target.value })} />
          </Field>
        </div>
      </Panel>

      {standardsQuery.isError ? <ErrorNotice error={standardsQuery.error} fallback="Unable to load standards" /> : null}
      {deleteStandard.error ? <ErrorNotice error={deleteStandard.error} fallback="Unable to delete standard" /> : null}
      {standardsQuery.isPending ? <LoadingState label="Loading standards" /> : null}
      {!standardsQuery.isPending && standards.length === 0 ? <EmptyState title="No standards found" description="Adjust filters or create a new standard." /> : null}
      {standards.length > 0 ? (
        <>
          <DataTable>
            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">Standard number</th>
                <th className="px-3 py-2 font-medium">Chinese name</th>
                <th className="px-3 py-2 font-medium">Status</th>
                <th className="px-3 py-2 font-medium">Category</th>
                <th className="px-3 py-2 font-medium">Language</th>
                <th className="px-3 py-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {standards.map((standard) => (
                <tr key={standard.id}>
                  <td className="px-3 py-3 text-sm font-medium text-slate-900">{standard.std_no}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{standard.chinese_name}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">
                    <StatusBadge status={standard.status} />
                  </td>
                  <td className="px-3 py-3 text-sm text-slate-700">{standard.category ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{standard.language ?? '-'}</td>
                  <td className="px-3 py-3">
                    <StandardActions standard={standard} onDelete={(target) => deleteStandard.mutate(target)} />
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          <div className="space-y-3 md:hidden">
            {standards.map((standard) => (
              <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" key={standard.id}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h2 className="truncate text-sm font-semibold text-slate-950">{standard.std_no}</h2>
                    <p className="truncate text-xs text-slate-500">{standard.chinese_name}</p>
                  </div>
                  <StatusBadge status={standard.status} />
                </div>
                <div className="mt-3">
                  <StandardActions standard={standard} onDelete={(target) => deleteStandard.mutate(target)} />
                </div>
              </article>
            ))}
          </div>
        </>
      ) : null}
      <PaginationControls
        meta={standardsQuery.data?.meta}
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

function StandardActions({ standard, onDelete }: { standard: Standard; onDelete: (standard: Standard) => void }) {
  const navigate = useNavigate()

  return (
    <div className="flex flex-wrap gap-2">
      <Button variant="secondary" onClick={() => void navigate({ to: '/standards/$standardId', params: { standardId: String(standard.id) } })}>
        <Eye className="size-4" aria-hidden="true" />
        View
      </Button>
      <PermissionGate resource="standards" action="update">
        <Button variant="secondary" onClick={() => void navigate({ to: '/standards/$standardId/edit', params: { standardId: String(standard.id) } })}>
          <Edit3 className="size-4" aria-hidden="true" />
          Edit
        </Button>
      </PermissionGate>
      <PermissionGate resource="standards" action="delete">
        <Button variant="danger" onClick={() => onDelete(standard)}>
          <Trash2 className="size-4" aria-hidden="true" />
          Delete
        </Button>
      </PermissionGate>
    </div>
  )
}

function cleanParams(filters: Record<string, string | number>) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
}
