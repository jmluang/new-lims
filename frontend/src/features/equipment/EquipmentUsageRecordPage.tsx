import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Edit3, Plus, Search, Trash2, X } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { QrScannerPanel } from '../../components/app/QrScannerPanel'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { useEffectivePermissions } from '../auth/useCurrentUser'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, PaginationControls, Panel, StatusBadge } from '../system/shared'
import { type ApiCollection, type ApiResource, inputClass, paginationParams, textareaClass } from '../system/utils'
import { buildEquipmentUsageStartPayload, uniqueNumberList } from './equipmentUsageSchema'

type UsageStatus = 'using' | 'finished'

type EquipmentUsageRecord = {
  id: number
  equipment_id: number
  sample_id: number
  equipment_no: string
  equipment_name: string
  sample_no: string
  sample_name: string
  sample_model?: string | null
  start_time: string
  end_time?: string | null
  status: UsageStatus
  operator_name?: string | null
  remark?: string | null
}

type EquipmentUsageFilters = {
  equipment: string
  sample: string
  status: string
}

type EquipmentUsageOptions = {
  equipment: Array<{ id: number; equipment_no: string; name: string; model?: string | null; status: string; calibration_date?: string | null }>
  samples: Array<{ id: number; sample_no: string; sample_name: string; model?: string | null; status: string }>
}

const emptyFilters: EquipmentUsageFilters = {
  equipment: '',
  sample: '',
  status: '',
}

export function EquipmentUsageRecordPage() {
  const queryClient = useQueryClient()
  const permissions = useEffectivePermissions()
  const [filters, setFilters] = useState<EquipmentUsageFilters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [editing, setEditing] = useState<EquipmentUsageRecord | null>(null)
  const [editForm, setEditForm] = useState({ start_time: '', end_time: '', remark: '' })
  const [form, setForm] = useState({
    equipment_ids: [] as number[],
    sample_ids: [] as number[],
    start_time: new Date().toISOString().slice(0, 16),
    remark: '',
  })
  const [scannedLabels, setScannedLabels] = useState<{ equipment: Record<number, string>; sample: Record<number, string> }>({ equipment: {}, sample: {} })
  const canCreate = Boolean(permissions.data?.resources.equipment_usage_records?.actions.create)
  const recordsQuery = useQuery({
    queryKey: ['equipment-usage-records', filters, page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<EquipmentUsageRecord>>('/api/equipment-usage-records', {
        params: cleanParams({ ...filters, ...paginationParams(page, perPage) }),
      })

      return response.data
    },
  })
  const optionsQuery = useQuery({
    queryKey: ['equipment-usage-record-options'],
    enabled: canCreate,
    queryFn: async () => {
      const response = await api.get<{ data: EquipmentUsageOptions }>('/api/equipment-usage-records/form-options', { params: { limit: 100 } })

      return response.data.data
    },
  })
  const lookupEquipment = useMutation({
    mutationFn: async (code: string) => {
      const response = await api.get<ApiResource<{ id: number; equipment_no: string; name: string }>>('/api/equipment-usage-records/lookup', {
        params: { type: 'equipment', code },
      })

      return response.data.data
    },
    onSuccess: (item) => {
      setForm((current) => ({ ...current, equipment_ids: uniqueNumberList([...current.equipment_ids, item.id]) }))
      setScannedLabels((current) => ({ ...current, equipment: { ...current.equipment, [item.id]: `${item.equipment_no} - ${item.name}` } }))
    },
  })
  const lookupSample = useMutation({
    mutationFn: async (code: string) => {
      const response = await api.get<ApiResource<{ id: number; sample_no: string; sample_name: string }>>('/api/equipment-usage-records/lookup', {
        params: { type: 'sample', code },
      })

      return response.data.data
    },
    onSuccess: (item) => {
      setForm((current) => ({ ...current, sample_ids: uniqueNumberList([...current.sample_ids, item.id]) }))
      setScannedLabels((current) => ({ ...current, sample: { ...current.sample, [item.id]: `${item.sample_no} - ${item.sample_name}` } }))
    },
  })
  const startUsage = useMutation({
    mutationFn: async () => {
      const payload = buildEquipmentUsageStartPayload(form)

      await api.post('/api/equipment-usage-records/start', payload)
    },
    onSuccess: async () => {
      setSelectedIds([])
      setForm((current) => ({ ...current, equipment_ids: [], sample_ids: [], remark: '' }))
      setScannedLabels({ equipment: {}, sample: {} })
      await queryClient.invalidateQueries({ queryKey: ['equipment-usage-records'] })
    },
  })
  const endUsage = useMutation({
    mutationFn: async (record: EquipmentUsageRecord) => {
      await api.post(`/api/equipment-usage-records/${record.id}/end`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['equipment-usage-records'] })
    },
  })
  const batchEndUsage = useMutation({
    mutationFn: async () => {
      await api.post('/api/equipment-usage-records/batch-end', { ids: selectedIds })
    },
    onSuccess: async () => {
      setSelectedIds([])
      await queryClient.invalidateQueries({ queryKey: ['equipment-usage-records'] })
    },
  })
  const updateUsage = useMutation({
    mutationFn: async () => {
      if (!editing) {
        return
      }

      await api.put(`/api/equipment-usage-records/${editing.id}`, {
        start_time: editForm.start_time,
        end_time: editForm.end_time || null,
        remark: editForm.remark || null,
      })
    },
    onSuccess: async () => {
      setEditing(null)
      await queryClient.invalidateQueries({ queryKey: ['equipment-usage-records'] })
    },
  })
  const deleteUsage = useMutation({
    mutationFn: async (record: EquipmentUsageRecord) => {
      await api.delete(`/api/equipment-usage-records/${record.id}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['equipment-usage-records'] })
    },
  })
  const records = recordsQuery.data?.data ?? []
  const usingSelectedIds = records.filter((record) => selectedIds.includes(record.id) && record.status === 'using').map((record) => record.id)

  function toggleSelected(id: number) {
    setSelectedIds((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]))
  }

  function startEdit(record: EquipmentUsageRecord) {
    setEditing(record)
    setEditForm({
      start_time: dateTimeLocalValue(record.start_time),
      end_time: record.end_time ? dateTimeLocalValue(record.end_time) : '',
      remark: record.remark ?? '',
    })
  }

  function equipmentLabelFor(id: number) {
    const option = optionsQuery.data?.equipment.find((item) => item.id === id)

    return scannedLabels.equipment[id] ?? (option ? `${option.equipment_no} - ${option.name}` : `#${id}`)
  }

  function sampleLabelFor(id: number) {
    const option = optionsQuery.data?.samples.find((item) => item.id === id)

    return scannedLabels.sample[id] ?? (option ? `${option.sample_no} - ${option.sample_name}` : `#${id}`)
  }

  function removeEquipment(id: number) {
    setForm((current) => ({ ...current, equipment_ids: current.equipment_ids.filter((value) => value !== id) }))
  }

  function removeSample(id: number) {
    setForm((current) => ({ ...current, sample_ids: current.sample_ids.filter((value) => value !== id) }))
  }

  return (
    <PageShell title="设备使用记录" description="记录设备和样品的开始测试、使用中和结束状态。">
      <Panel title="Filters">
        <div className="grid gap-3 md:grid-cols-4">
          <Field label="设备">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input className={`${inputClass} pl-9`} value={filters.equipment} onChange={(event) => setFilters({ ...filters, equipment: event.target.value })} placeholder="编号/名称" />
            </div>
          </Field>
          <Field label="样品">
            <input className={inputClass} value={filters.sample} onChange={(event) => setFilters({ ...filters, sample: event.target.value })} placeholder="编号/名称/型号" />
          </Field>
          <Field label="状态">
            <select className={inputClass} value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
              <option value="">{zhText('All')}</option>
              <option value="using">使用中</option>
              <option value="finished">已结束</option>
            </select>
          </Field>
          <div className="flex items-end">
            <Button variant="secondary" onClick={() => setFilters(emptyFilters)}>
              重置
            </Button>
          </div>
        </div>
      </Panel>

      <PermissionGate resource="equipment_usage_records" action="create">
        <Panel title="开始新测试">
          {optionsQuery.isPending ? <LoadingState label="正在加载设备和样品" /> : null}
          {optionsQuery.isError ? <ErrorNotice error={optionsQuery.error} fallback="无法加载设备使用选项" /> : null}
          <div className="mb-3 grid gap-3 md:grid-cols-2">
            <div className="space-y-2">
              <QrScannerPanel title="扫码/输入设备编号" placeholder="设备编号" onDetected={(code) => lookupEquipment.mutate(code)}>
                <SelectedChips ids={form.equipment_ids} labelFor={equipmentLabelFor} onRemove={removeEquipment} />
              </QrScannerPanel>
              {lookupEquipment.isError ? <ErrorNotice error="未找到设备" fallback="未找到设备" /> : null}
            </div>
            <div className="space-y-2">
              <QrScannerPanel title="扫码/输入样品编号" placeholder="样品编号" onDetected={(code) => lookupSample.mutate(code)}>
                <SelectedChips ids={form.sample_ids} labelFor={sampleLabelFor} onRemove={removeSample} />
              </QrScannerPanel>
              {lookupSample.isError ? <ErrorNotice error="未找到样品" fallback="未找到样品" /> : null}
            </div>
          </div>
          <div className="grid gap-3 md:grid-cols-3">
            <Field label="开始时间">
              <input className={inputClass} type="datetime-local" value={form.start_time} onChange={(event) => setForm({ ...form, start_time: event.target.value })} />
            </Field>
            <Field label="备注明细" className="md:col-span-3">
              <textarea className={textareaClass} value={form.remark} onChange={(event) => setForm({ ...form, remark: event.target.value })} />
            </Field>
          </div>
          <div className="mt-3 flex justify-end">
            <Button variant="primary" onClick={() => startUsage.mutate()} disabled={startUsage.isPending}>
              <Plus className="size-4" aria-hidden="true" />
              开始测试
            </Button>
          </div>
        </Panel>
      </PermissionGate>

      {recordsQuery.isError ? <ErrorNotice error={recordsQuery.error} fallback="无法加载设备使用记录" /> : null}
      <Modal
        title="编辑设备使用记录"
        open={editing !== null}
        onClose={() => {
          setEditing(null)
          updateUsage.reset()
        }}
      >
        <div className="space-y-3">
          <div className="grid gap-3 md:grid-cols-2">
            <Field label="开始时间">
              <input className={inputClass} type="datetime-local" value={editForm.start_time} onChange={(event) => setEditForm({ ...editForm, start_time: event.target.value })} />
            </Field>
            <Field label="结束时间">
              <input className={inputClass} type="datetime-local" value={editForm.end_time} onChange={(event) => setEditForm({ ...editForm, end_time: event.target.value })} />
            </Field>
          </div>
          <Field label="备注">
            <textarea className={textareaClass} value={editForm.remark} onChange={(event) => setEditForm({ ...editForm, remark: event.target.value })} />
          </Field>
          {updateUsage.error ? <ErrorNotice error={updateUsage.error} fallback="无法保存设备使用记录" /> : null}
          <div className="flex justify-end gap-2">
            <Button
              variant="ghost"
              onClick={() => {
                setEditing(null)
                updateUsage.reset()
              }}
            >
              取消
            </Button>
            <Button variant="primary" onClick={() => updateUsage.mutate()} disabled={updateUsage.isPending}>
              保存
            </Button>
          </div>
        </div>
      </Modal>

      {startUsage.error || endUsage.error || batchEndUsage.error || deleteUsage.error ? (
        <ErrorNotice error={startUsage.error ?? endUsage.error ?? batchEndUsage.error ?? deleteUsage.error} fallback="设备使用记录操作失败" />
      ) : null}

      <Panel title="统计">
        <div className="grid gap-3 text-sm md:grid-cols-3">
          <Stat label="总记录数" value={recordsQuery.data?.meta?.total ?? 0} />
          <Stat label="使用中" value={(recordsQuery.data?.meta as { using_count?: number } | undefined)?.using_count ?? 0} />
          <Stat label="已结束" value={(recordsQuery.data?.meta as { finished_count?: number } | undefined)?.finished_count ?? 0} />
        </div>
      </Panel>

      <div className="flex justify-end">
        <PermissionGate resource="equipment_usage_records" action="update">
          <Button variant="secondary" disabled={usingSelectedIds.length === 0 || batchEndUsage.isPending} onClick={() => batchEndUsage.mutate()}>
            <CheckCircle2 className="size-4" aria-hidden="true" />
            批量结束
          </Button>
        </PermissionGate>
      </div>

      {recordsQuery.isPending ? <LoadingState label="正在加载设备使用记录" /> : null}
      {!recordsQuery.isPending && records.length === 0 ? <EmptyState title="暂无设备使用记录" description="开始测试后会生成设备和样品的使用记录。" /> : null}
      {records.length > 0 ? (
        <DataTable>
          <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
            <tr>
              <th className="px-3 py-2 font-medium">选择</th>
              <th className="px-3 py-2 font-medium">设备</th>
              <th className="px-3 py-2 font-medium">样品</th>
              <th className="px-3 py-2 font-medium">开始时间</th>
              <th className="px-3 py-2 font-medium">结束时间</th>
              <th className="px-3 py-2 font-medium">状态</th>
              <th className="px-3 py-2 font-medium">操作人</th>
              <th className="px-3 py-2 font-medium">备注</th>
              <th className="px-3 py-2 font-medium">操作</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {records.map((record) => (
              <tr className="align-top" key={record.id}>
                <td className="px-3 py-3">
                  <input className="size-4 rounded border-slate-300 text-emerald-600" type="checkbox" checked={selectedIds.includes(record.id)} onChange={() => toggleSelected(record.id)} />
                </td>
                <td className="px-3 py-3 text-sm">
                  <div className="font-medium text-slate-900">{record.equipment_no}</div>
                  <div className="text-xs text-slate-500">{record.equipment_name}</div>
                </td>
                <td className="px-3 py-3 text-sm">
                  <div className="font-medium text-slate-900">{record.sample_no}</div>
                  <div className="text-xs text-slate-500">
                    {record.sample_name} {record.sample_model ? `(${record.sample_model})` : ''}
                  </div>
                </td>
                <td className="px-3 py-3 text-sm text-slate-700">{record.start_time}</td>
                <td className="px-3 py-3 text-sm text-slate-700">{record.end_time ?? '-'}</td>
                <td className="px-3 py-3 text-sm">
                  <StatusBadge status={record.status} />
                </td>
                <td className="px-3 py-3 text-sm text-slate-700">{record.operator_name ?? '-'}</td>
                <td className="px-3 py-3 text-sm text-slate-700">{record.remark ?? '-'}</td>
                <td className="px-3 py-3">
                  <div className="flex flex-wrap gap-2">
                    <PermissionGate resource="equipment_usage_records" action="update">
                      <Button variant="secondary" disabled={record.status === 'finished' || endUsage.isPending} onClick={() => endUsage.mutate(record)}>
                        结束
                      </Button>
                      <Button variant="secondary" onClick={() => startEdit(record)}>
                        <Edit3 className="size-4" aria-hidden="true" />
                        编辑
                      </Button>
                    </PermissionGate>
                    <PermissionGate resource="equipment_usage_records" action="delete">
                      <Button variant="danger" onClick={() => deleteUsage.mutate(record)}>
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

function dateTimeLocalValue(value: string) {
  return value.replace(' ', 'T').slice(0, 16)
}

function SelectedChips({ ids, labelFor, onRemove }: { ids: number[]; labelFor: (id: number) => string; onRemove: (id: number) => void }) {
  if (ids.length === 0) {
    return <p className="text-xs text-slate-500">{zhText('已选') ?? '已选'}: -</p>
  }

  return (
    <div className="flex flex-wrap gap-2">
      {ids.map((id) => (
        <span className="inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs text-emerald-800" key={id}>
          {labelFor(id)}
          <button type="button" className="text-emerald-700 hover:text-emerald-900" aria-label="移除" onClick={() => onRemove(id)}>
            <X className="size-3" aria-hidden="true" />
          </button>
        </span>
      ))}
    </div>
  )
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
      <div className="text-xs text-slate-500">{label}</div>
      <div className="mt-1 text-lg font-semibold text-slate-950">{value}</div>
    </div>
  )
}

function cleanParams(filters: Record<string, string | number>) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
}
