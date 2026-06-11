import { useQuery } from '@tanstack/react-query'
import { Download, Search } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../../components/app/PermissionGate'
import { api } from '../../../lib/api'
import {
  Button,
  DataTable,
  EmptyState,
  ErrorNotice,
  Field,
  LoadingState,
  PageShell,
  PaginationControls,
  Panel,
} from '../shared'
import { type ApiCollection, formatDateTime, inputClass, paginationParams } from '../utils'

type AuditLog = {
  id: number
  created_at: string
  request_id: string
  actor_user_id?: number | null
  actor_name_snapshot?: string | null
  module: string
  action: string
  subject_type?: string | null
  subject_id?: string | null
  before_values?: Record<string, unknown> | null
  after_values?: Record<string, unknown> | null
  changed_values?: Record<string, unknown> | null
  ip_address?: string | null
  user_agent?: string | null
  hash: string
}

type AuditFilters = {
  actor: string
  module: string
  action: string
  subject_type: string
  subject_id: string
  request_id: string
  date_from: string
  date_to: string
}

const emptyFilters: AuditFilters = {
  actor: '',
  module: '',
  action: '',
  subject_type: '',
  subject_id: '',
  request_id: '',
  date_from: '',
  date_to: '',
}

export function AuditLogListPage() {
  const [filters, setFilters] = useState<AuditFilters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [selected, setSelected] = useState<AuditLog | null>(null)
  const auditLogsQuery = useQuery({
    queryKey: ['audit-logs', filters, page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<AuditLog>>('/api/audit-logs', { params: cleanParams({ ...filters, ...paginationParams(page, perPage) }) })

      return response.data
    },
  })
  const logs = auditLogsQuery.data?.data ?? []

  async function exportLogs() {
    const response = await api.get<{ headers: string[]; data: AuditLog[] }>('/api/audit-logs/export', {
      params: cleanParams(filters),
    })
    const content = JSON.stringify(response.data, null, 2)
    const blob = new Blob([content], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = `audit-logs-${new Date().toISOString().slice(0, 10)}.json`
    link.click()
    URL.revokeObjectURL(url)
  }

  return (
    <PageShell
      title="Audit Logs"
      description="Append-only audit trail with before/after values, request IDs and export controls."
      actions={
        <PermissionGate resource="system.audit_logs" action="export">
          <Button variant="secondary" onClick={() => void exportLogs()}>
            <Download className="size-4" aria-hidden="true" />
            Export JSON
          </Button>
        </PermissionGate>
      }
    >
      <Panel title="Audit filters">
        <div className="grid gap-3 md:grid-cols-4">
          <Field label="Actor">
            <input className={inputClass} value={filters.actor} onChange={(event) => setFilters({ ...filters, actor: event.target.value })} />
          </Field>
          <Field label="Module">
            <input className={inputClass} value={filters.module} onChange={(event) => setFilters({ ...filters, module: event.target.value })} />
          </Field>
          <Field label="Action">
            <input className={inputClass} value={filters.action} onChange={(event) => setFilters({ ...filters, action: event.target.value })} />
          </Field>
          <Field label="Request ID">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input
                className={`${inputClass} pl-9`}
                value={filters.request_id}
                onChange={(event) => setFilters({ ...filters, request_id: event.target.value })}
              />
            </div>
          </Field>
          <Field label="Subject type">
            <input
              className={inputClass}
              value={filters.subject_type}
              onChange={(event) => setFilters({ ...filters, subject_type: event.target.value })}
            />
          </Field>
          <Field label="Subject ID">
            <input className={inputClass} value={filters.subject_id} onChange={(event) => setFilters({ ...filters, subject_id: event.target.value })} />
          </Field>
          <Field label="From">
            <input
              className={inputClass}
              type="date"
              value={filters.date_from}
              onChange={(event) => setFilters({ ...filters, date_from: event.target.value })}
            />
          </Field>
          <Field label="To">
            <input
              className={inputClass}
              type="date"
              value={filters.date_to}
              onChange={(event) => setFilters({ ...filters, date_to: event.target.value })}
            />
          </Field>
        </div>
      </Panel>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_420px]">
        <section>
          {auditLogsQuery.isPending ? <LoadingState label="Loading audit logs" /> : null}
          {auditLogsQuery.isError ? <ErrorNotice error={auditLogsQuery.error} fallback="Unable to load audit logs" /> : null}
          {!auditLogsQuery.isPending && logs.length === 0 ? (
            <EmptyState title="No audit logs" description="No records match the current filters." />
          ) : null}
          {logs.length > 0 ? (
            <>
              <DataTable>
                <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                  <tr>
                    <th className="px-3 py-2 font-medium">Time</th>
                    <th className="px-3 py-2 font-medium">Actor</th>
                    <th className="px-3 py-2 font-medium">Operation</th>
                    <th className="px-3 py-2 font-medium">Subject</th>
                    <th className="px-3 py-2 font-medium">Request</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {logs.map((log) => (
                    <tr className="cursor-pointer align-top hover:bg-slate-50" onClick={() => setSelected(log)} key={log.id}>
                      <td className="px-3 py-3 text-slate-700">{formatDateTime(log.created_at)}</td>
                      <td className="px-3 py-3">
                        <div className="font-medium text-slate-900">{log.actor_name_snapshot ?? 'System'}</div>
                        <div className="text-xs text-slate-500">{log.actor_user_id ?? '-'}</div>
                      </td>
                      <td className="px-3 py-3">
                        <div className="font-medium text-slate-900">{log.action}</div>
                        <div className="text-xs text-slate-500">{log.module}</div>
                      </td>
                      <td className="px-3 py-3 text-xs text-slate-600">
                        {log.subject_type ?? '-'} #{log.subject_id ?? '-'}
                      </td>
                      <td className="px-3 py-3 text-xs text-slate-600">{log.request_id}</td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>

              <div className="space-y-3 md:hidden">
                {logs.map((log) => (
                  <button className="w-full rounded-lg border border-slate-200 bg-white p-4 text-left shadow-sm" type="button" onClick={() => setSelected(log)} key={log.id}>
                    <div className="text-sm font-semibold text-slate-950">{log.action}</div>
                    <div className="mt-1 text-xs text-slate-500">{log.module}</div>
                    <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                      <span>{log.actor_name_snapshot ?? 'System'}</span>
                      <span>{formatDateTime(log.created_at)}</span>
                    </div>
                  </button>
                ))}
              </div>
            </>
          ) : null}
          <PaginationControls
            meta={auditLogsQuery.data?.meta}
            page={page}
            perPage={perPage}
            onPageChange={setPage}
            onPerPageChange={(nextPerPage) => {
              setPerPage(nextPerPage)
              setPage(1)
            }}
          />
        </section>

        <Panel title="Record detail" description={selected ? `#${selected.id} ${selected.action}` : 'Select an audit record'}>
          {selected ? (
            <div className="space-y-3 text-sm">
              <Detail label="Request ID" value={selected.request_id} />
              <Detail label="IP" value={selected.ip_address ?? '-'} />
              <Detail label="Hash" value={selected.hash} />
              <JsonBlock title="Before" value={selected.before_values} />
              <JsonBlock title="After" value={selected.after_values} />
              <JsonBlock title="Changed" value={selected.changed_values} />
            </div>
          ) : (
            <EmptyState title="No record selected" description="Choose a row to inspect before and after values." />
          )}
        </Panel>
      </div>
    </PageShell>
  )
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-xs font-medium uppercase text-slate-500">{label}</div>
      <div className="mt-1 break-all rounded-md bg-slate-50 p-2 text-xs text-slate-700">{value}</div>
    </div>
  )
}

function JsonBlock({ title, value }: { title: string; value?: Record<string, unknown> | null }) {
  return (
    <div>
      <div className="text-xs font-medium uppercase text-slate-500">{title}</div>
      <pre className="mt-1 max-h-64 overflow-auto rounded-md bg-slate-950 p-3 text-xs text-slate-100">
        {JSON.stringify(value ?? {}, null, 2)}
      </pre>
    </div>
  )
}

function cleanParams(filters: Record<string, string | number>) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
}
