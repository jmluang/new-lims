import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit3, Plus, Printer, Save, Search, Trash2, Users } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, Panel, StatusBadge } from '../system/shared'
import { type ApiCollection, inputClass } from '../system/utils'

export type EquipmentSystem = {
  id: number
  name: string
  code: string
  status: 'active' | 'disabled'
  equipment_count?: number
}

type Equipment = {
  id: number
  equipment_no: string
  name: string
  model?: string | null
  location_id?: number | null
  location?: { name: string } | null
  system_id?: number | null
}

type SystemForm = {
  name: string
  code: string
  status: 'active' | 'disabled'
  equipment_ids: number[]
}

type SystemFilters = {
  search: string
  status: string
}

const emptyFilters: SystemFilters = { search: '', status: '' }
const emptyForm: SystemForm = { name: '', code: '', status: 'active', equipment_ids: [] }

export function EquipmentSystemPage() {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<EquipmentSystem | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const [form, setForm] = useState<SystemForm>(emptyForm)
  const [manageEquipmentSystem, setManageEquipmentSystem] = useState<EquipmentSystem | null>(null)
  const [manageEquipmentOpen, setManageEquipmentOpen] = useState(false)
  const [selectedEquipmentIds, setSelectedEquipmentIds] = useState<number[]>([])
  const [managingSystemId, setManagingSystemId] = useState<number | null>(null)
  const [filters, setFilters] = useState<SystemFilters>(emptyFilters)
  const [selectedIds, setSelectedIds] = useState<number[]>([])

  const systemsQuery = useQuery({
    queryKey: ['equipment-systems'],
    queryFn: async () => {
      const response = await api.get<ApiCollection<EquipmentSystem>>('/api/equipment-systems')
      return response.data.data
    },
  })
  const equipmentQuery = useQuery({
    queryKey: ['equipment'],
    queryFn: async () => {
      const response = await api.get<ApiCollection<Equipment>>('/api/equipment', { params: { per_page: 1000 } })
      return response.data.data
    },
  })
  const saveSystem = useMutation({
    mutationFn: async () => {
      const payload = {
        name: form.name,
        code: form.code,
        status: form.status,
        equipment_ids: form.equipment_ids,
      }

      if (editing) {
        await api.put(`/api/equipment-systems/${editing.id}`, payload)
        return
      }

      await api.post('/api/equipment-systems', payload)
    },
    onSuccess: async () => {
      setEditing(null)
      setFormOpen(false)
      setForm(emptyForm)
      await queryClient.invalidateQueries({ queryKey: ['equipment-systems'] })
      await queryClient.invalidateQueries({ queryKey: ['equipment'] })
    },
  })
  const disableSystem = useMutation({
    mutationFn: async (system: EquipmentSystem) => {
      await api.delete(`/api/equipment-systems/${system.id}`)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['equipment-systems'] }),
  })

  const systems = (systemsQuery.data ?? []).filter((system) => {
    if (filters.search) {
      const search = filters.search.toLowerCase()
      if (!system.name.toLowerCase().includes(search) && !system.code.toLowerCase().includes(search)) {
        return false
      }
    }
    if (filters.status && system.status !== filters.status) {
      return false
    }
    return true
  })

  function openCreate() {
    setEditing(null)
    setForm(emptyForm)
    setFormOpen(true)
  }

  function openEdit(system: EquipmentSystem) {
    setEditing(system)
    const systemEquipmentIds = (equipmentQuery.data ?? [])
      .filter((eq) => eq.system_id === system.id)
      .map((eq) => eq.id)
    setForm({
      name: system.name,
      code: system.code,
      status: system.status,
      equipment_ids: systemEquipmentIds,
    })
    setFormOpen(true)
  }

  function openManageEquipment(system: EquipmentSystem) {
    setManagingSystemId(system.id)
    setManageEquipmentSystem(system)
    const equipmentData = equipmentQuery.data ?? []
    const systemEquipmentIds = equipmentData
      .filter((eq) => eq.system_id === system.id)
      .map((eq) => eq.id)
    setSelectedEquipmentIds(systemEquipmentIds)
    setManagingSystemId(null)
    setManageEquipmentOpen(true)
  }

  function printSystemLabels(system: EquipmentSystem) {
    const equipmentData = equipmentQuery.data ?? []
    const systemEquipmentIds = equipmentData
      .filter((eq) => eq.system_id === system.id)
      .map((eq) => eq.id)
    if (systemEquipmentIds.length === 0) return
    localStorage.setItem('new_lims_label_equipment_ids', JSON.stringify(systemEquipmentIds))
    window.location.assign('/equipment/labels')
  }

  const saveEquipmentAssignment = useMutation({
    mutationFn: async () => {
      if (!manageEquipmentSystem) return
      await api.put(`/api/equipment-systems/${manageEquipmentSystem.id}`, {
        equipment_ids: selectedEquipmentIds,
      })
    },
    onSuccess: async () => {
      setManageEquipmentSystem(null)
      setManageEquipmentOpen(false)
      setSelectedEquipmentIds([])
      await queryClient.invalidateQueries({ queryKey: ['equipment-systems'] })
      await queryClient.invalidateQueries({ queryKey: ['equipment'] })
    },
  })

  function toggleSelected(id: number) {
    setSelectedIds((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]))
  }

  return (
    <PageShell
      title="设备系统"
      description="将多台设备分组到一个命名的设备系统中。"
      actions={
        <PermissionGate resource="equipment_systems" action="create">
          <Button variant="primary" onClick={openCreate}>
            <Plus className="size-4" aria-hidden="true" />
            新建系统
          </Button>
        </PermissionGate>
      }
    >
      <Panel title="筛选">
        <div className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="搜索">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input
                className={`${inputClass} pl-9`}
                value={filters.search}
                onChange={(event) => setFilters({ ...filters, search: event.target.value })}
                placeholder="名称、代码"
              />
            </div>
          </Field>
          <Field label="状态">
            <select className={inputClass} value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
              <option value="">全部</option>
              <option value="active">启用</option>
              <option value="disabled">禁用</option>
            </select>
          </Field>
        </div>
      </Panel>

      {systemsQuery.isPending ? <LoadingState label="加载系统中" /> : null}
      {systemsQuery.isError ? <ErrorNotice error={systemsQuery.error} fallback="无法加载系统" /> : null}
      {disableSystem.error ? <ErrorNotice error={disableSystem.error} fallback="系统无法禁用" /> : null}
      {!systemsQuery.isPending && systems.length === 0 ? <EmptyState title="暂无系统" description="请先创建系统，然后从设备台账中分配设备。" /> : null}

      {systems.length > 0 ? (
        <>
          <DataTable>
            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">选择</th>
                <th className="px-3 py-2 font-medium">系统名称</th>
                <th className="px-3 py-2 font-medium">系统代码</th>
                <th className="px-3 py-2 font-medium">设备数量</th>
                <th className="px-3 py-2 font-medium">状态</th>
                <th className="px-3 py-2 font-medium">操作</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {systems.map((system) => (
                <tr key={system.id}>
                  <td className="px-3 py-3">
                    <input
                      className="size-4 rounded border-slate-300 text-emerald-600"
                      type="checkbox"
                      checked={selectedIds.includes(system.id)}
                      onChange={() => toggleSelected(system.id)}
                    />
                  </td>
                  <td className="px-3 py-3 text-sm font-medium text-slate-900">{system.name}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{system.code}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{system.equipment_count ?? 0}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">
                    <StatusBadge status={system.status} />
                  </td>
                  <td className="px-3 py-3">
                    <div className="flex flex-wrap gap-2">
                      <PermissionGate resource="equipment_systems" action="update">
                        <Button variant="secondary" onClick={() => openEdit(system)}>
                          <Edit3 className="size-4" aria-hidden="true" />
                          编辑
                        </Button>
                      </PermissionGate>
                      <PermissionGate resource="equipment_systems" action="update">
                        <Button variant="secondary" onClick={() => openManageEquipment(system)} disabled={managingSystemId === system.id}>
                          <Users className="size-4" aria-hidden="true" />
                          {managingSystemId === system.id ? '加载中...' : '管理设备'}
                        </Button>
                      </PermissionGate>
                      <PermissionGate resource="equipment_labels" action="print">
                        <Button variant="secondary" onClick={() => printSystemLabels(system)} disabled={!equipmentQuery.data || (equipmentQuery.data ?? []).filter((eq) => eq.system_id === system.id).length === 0}>
                          <Printer className="size-4" aria-hidden="true" />
                          打印标签
                        </Button>
                      </PermissionGate>
                      <PermissionGate resource="equipment_systems" action="delete">
                        <Button variant="danger" onClick={() => disableSystem.mutate(system)}>
                          <Trash2 className="size-4" aria-hidden="true" />
                          禁用
                        </Button>
                      </PermissionGate>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>

          <div className="space-y-3 md:hidden">
            {systems.map((system) => (
              <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" key={system.id}>
                <div className="flex items-start justify-between gap-3">
                  <label className="flex min-w-0 items-start gap-3">
                    <input
                      className="mt-0.5 size-4 rounded border-slate-300 text-emerald-600"
                      type="checkbox"
                      checked={selectedIds.includes(system.id)}
                      onChange={() => toggleSelected(system.id)}
                    />
                    <span className="min-w-0">
                      <span className="block truncate text-sm font-semibold text-slate-950">{system.name}</span>
                      <span className="block truncate text-xs text-slate-500">{system.code}</span>
                    </span>
                  </label>
                  <StatusBadge status={system.status} />
                </div>
                <div className="mt-2 flex flex-wrap gap-2 text-xs text-slate-500">
                  <span>设备: {system.equipment_count ?? 0}</span>
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                  <PermissionGate resource="equipment_systems" action="update">
                    <Button variant="secondary" onClick={() => openEdit(system)}>
                      <Edit3 className="size-4" aria-hidden="true" />
                      编辑
                    </Button>
                  </PermissionGate>
                  <PermissionGate resource="equipment_systems" action="update">
                    <Button variant="secondary" onClick={() => openManageEquipment(system)} disabled={managingSystemId === system.id}>
                      <Users className="size-4" aria-hidden="true" />
                      {managingSystemId === system.id ? '加载中...' : '管理设备'}
                    </Button>
                  </PermissionGate>
                  <PermissionGate resource="equipment_labels" action="print">
                    <Button variant="secondary" onClick={() => printSystemLabels(system)} disabled={!equipmentQuery.data || (equipmentQuery.data ?? []).filter((eq) => eq.system_id === system.id).length === 0}>
                      <Printer className="size-4" aria-hidden="true" />
                      打印标签
                    </Button>
                  </PermissionGate>
                  <PermissionGate resource="equipment_systems" action="delete">
                    <Button variant="danger" onClick={() => disableSystem.mutate(system)}>
                      <Trash2 className="size-4" aria-hidden="true" />
                      禁用
                    </Button>
                  </PermissionGate>
                </div>
              </article>
            ))}
          </div>
        </>
      ) : null}

      <Modal
        title={editing ? '编辑系统' : '新建系统'}
        open={formOpen}
        onClose={() => {
          setFormOpen(false)
          setEditing(null)
        }}
      >
        {saveSystem.error ? <ErrorNotice error={saveSystem.error} fallback="无法保存系统" /> : null}
        <div className="space-y-3">
          <Field label="系统名称">
            <input className={inputClass} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
          </Field>
          <Field label="系统代码">
            <input className={inputClass} value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} />
          </Field>
          <Field label="状态">
            <select className={inputClass} value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value as SystemForm['status'] })}>
              <option value="active">启用</option>
              <option value="disabled">禁用</option>
            </select>
          </Field>
          <Field label="分配设备">
            <div className="max-h-40 overflow-y-auto border border-slate-200 rounded-md p-2">
              {equipmentQuery.isPending ? (
                <div className="text-sm text-slate-500">加载设备中...</div>
              ) : (equipmentQuery.data ?? []).length === 0 ? (
                <div className="text-sm text-slate-500">暂无设备</div>
              ) : (
                (equipmentQuery.data ?? []).map((equipment) => (
                  <label key={equipment.id} className="flex items-center space-x-2 py-1">
                    <input
                      type="checkbox"
                      checked={form.equipment_ids.includes(equipment.id)}
                      onChange={(event) => {
                        if (event.target.checked) {
                          setForm({ ...form, equipment_ids: [...form.equipment_ids, equipment.id] })
                        } else {
                          setForm({ ...form, equipment_ids: form.equipment_ids.filter((id) => id !== equipment.id) })
                        }
                      }}
                      className="rounded border-slate-300"
                    />
                    <span className="text-sm">
                      {equipment.equipment_no} - {equipment.name}
                      {equipment.model ? ` (${equipment.model})` : ''}
                      {equipment.location?.name ? ` - ${equipment.location.name}` : ''}
                    </span>
                  </label>
                ))
              )}
            </div>
          </Field>
          <PermissionGate resource="equipment_systems" action={editing ? 'update' : 'create'}>
            <Button variant="primary" disabled={saveSystem.isPending || form.name === '' || form.code === ''} onClick={() => saveSystem.mutate()}>
              <Save className="size-4" aria-hidden="true" />
              保存系统
            </Button>
          </PermissionGate>
        </div>
      </Modal>

      <Modal
        title={manageEquipmentSystem ? `管理设备 - ${manageEquipmentSystem.name}` : '管理设备'}
        open={manageEquipmentOpen}
        onClose={() => {
          setManageEquipmentOpen(false)
          setManageEquipmentSystem(null)
          setSelectedEquipmentIds([])
        }}
      >
        {saveEquipmentAssignment.error ? <ErrorNotice error={saveEquipmentAssignment.error} fallback="无法保存设备分配" /> : null}
        <div className="space-y-3">
          <div className="max-h-60 overflow-y-auto border border-slate-200 rounded-md p-2">
            {equipmentQuery.isPending ? (
              <div className="text-sm text-slate-500">加载设备中...</div>
            ) : (equipmentQuery.data ?? []).length === 0 ? (
              <div className="text-sm text-slate-500">暂无设备</div>
            ) : (
              (equipmentQuery.data ?? []).map((equipment) => (
                <label key={equipment.id} className="flex items-center space-x-2 py-1">
                  <input
                    type="checkbox"
                    checked={selectedEquipmentIds.includes(equipment.id)}
                    onChange={(event) => {
                      if (event.target.checked) {
                        setSelectedEquipmentIds([...selectedEquipmentIds, equipment.id])
                      } else {
                        setSelectedEquipmentIds(selectedEquipmentIds.filter((id) => id !== equipment.id))
                      }
                    }}
                    className="rounded border-slate-300"
                  />
                  <span className="text-sm">
                    {equipment.equipment_no} - {equipment.name}
                    {equipment.model ? ` (${equipment.model})` : ''}
                    {equipment.location?.name ? ` - ${equipment.location.name}` : ''}
                  </span>
                </label>
              ))
            )}
          </div>
          <div className="flex justify-end">
            <Button variant="primary" disabled={saveEquipmentAssignment.isPending} onClick={() => saveEquipmentAssignment.mutate()}>
              <Save className="size-4" aria-hidden="true" />
              保存分配
            </Button>
          </div>
        </div>
      </Modal>
    </PageShell>
  )
}
