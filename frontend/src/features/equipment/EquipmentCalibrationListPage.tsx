import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { Eye, Plus, Search, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, PageShell, PaginationControls, Panel, StatusBadge } from '../system/shared'
import { type ApiCollection, inputClass, paginationParams } from '../system/utils'

export type EquipmentCalibrationSummary = {
  id: number
  calibration_name: string
  calibration_time: string
  operator_name?: string | null
  result: string
  devices_count: number
  standards_count: number
}

type CalibrationFilters = {
  search: string
  result: string
}

const emptyFilters: CalibrationFilters = {
  search: '',
  result: '',
}

export function EquipmentCalibrationListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [filters, setFilters] = useState<CalibrationFilters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)

  const recordsQuery = useQuery({
    queryKey: ['equipment-calibrations', filters, page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<EquipmentCalibrationSummary>>('/api/equipment-calibrations', {
        params: cleanParams({ ...filters, ...paginationParams(page, perPage) }),
      })

      return response.data
    },
  })

  const deleteRecord = useMutation({
    mutationFn: async (record: EquipmentCalibrationSummary) => {
      await api.delete(`/api/equipment-calibrations/${record.id}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['equipment-calibrations'] })
    },
  })

  const records = recordsQuery.data?.data ?? []

  return (
    <PageShell
      title="Device calibration records"
      description="Track device calibration events, standards and results."
      actions={
        <PermissionGate resource="equipment_calibrations" action="create">
          <Button variant="primary" onClick={() => void navigate({ to: '/equipment/calibrations/new' })}>
            <Plus className="size-4" aria-hidden="true" />
            {zhText('新建定标记录')}
          </Button>
        </PermissionGate>
      }
    >
      <Panel title="Filters">
        <div className="grid gap-3 md:grid-cols-3">
          <Field label="Search">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input className={`${inputClass} pl-9`} value={filters.search} onChange={(event) => setFilters({ ...filters, search: event.target.value })} placeholder="定标名称" />
            </div>
          </Field>
          <Field label="结果">
            <select className={inputClass} value={filters.result} onChange={(event) => setFilters({ ...filters, result: event.target.value })}>
              <option value="">{zhText('All')}</option>
              <option value="qualified">{zhText('qualified')}</option>
              <option value="unqualified">{zhText('unqualified')}</option>
            </select>
          </Field>
        </div>
      </Panel>

      {recordsQuery.isError ? <ErrorNotice error={recordsQuery.error} fallback="无法加载定标记录" /> : null}
      {deleteRecord.error ? <ErrorNotice error={deleteRecord.error} fallback="无法删除定标记录" /> : null}
      {recordsQuery.isPending ? <LoadingState label="正在加载定标记录" /> : null}
      {!recordsQuery.isPending && records.length === 0 ? <EmptyState title="暂无定标记录" description="新建定标记录后会显示在此处。" /> : null}

      {records.length > 0 ? (
        <DataTable>
          <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
            <tr>
              <th className="px-3 py-2 font-medium">定标时间</th>
              <th className="px-3 py-2 font-medium">定标名称</th>
              <th className="px-3 py-2 font-medium">结果</th>
              <th className="px-3 py-2 font-medium">操作人</th>
              <th className="px-3 py-2 font-medium">设备数</th>
              <th className="px-3 py-2 font-medium">标准件数</th>
              <th className="px-3 py-2 font-medium">操作</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {records.map((record) => (
              <tr key={record.id}>
                <td className="px-3 py-3 text-sm text-slate-700">{record.calibration_time}</td>
                <td className="px-3 py-3 text-sm font-medium text-slate-900">{record.calibration_name}</td>
                <td className="px-3 py-3 text-sm">
                  <StatusBadge status={record.result} />
                </td>
                <td className="px-3 py-3 text-sm text-slate-700">{record.operator_name ?? '-'}</td>
                <td className="px-3 py-3 text-sm text-slate-700">{record.devices_count}</td>
                <td className="px-3 py-3 text-sm text-slate-700">{record.standards_count}</td>
                <td className="px-3 py-3">
                  <div className="flex flex-wrap gap-2">
                    <Button variant="secondary" onClick={() => void navigate({ to: '/equipment/calibrations/$calibrationId', params: { calibrationId: String(record.id) } })}>
                      <Eye className="size-4" aria-hidden="true" />
                      查看
                    </Button>
                    <PermissionGate resource="equipment_calibrations" action="update">
                      <Button variant="secondary" onClick={() => void navigate({ to: '/equipment/calibrations/$calibrationId/edit', params: { calibrationId: String(record.id) } })}>
                        编辑
                      </Button>
                    </PermissionGate>
                    <PermissionGate resource="equipment_calibrations" action="delete">
                      <Button variant="danger" disabled={deleteRecord.isPending} onClick={() => deleteRecord.mutate(record)}>
                        <Trash2 className="size-4" aria-hidden="true" />
                        删除
                      </Button>
                    </PermissionGate>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </DataTable>
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

function cleanParams(filters: Record<string, string | number>) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
}
