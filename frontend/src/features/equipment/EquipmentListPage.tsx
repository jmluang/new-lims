import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { Edit3, Plus, Printer, Search, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import {
  Button,
  DataTable,
  EmptyState,
  ErrorNotice,
  Field,
  LoadingState,
  PageShell,
  Panel,
  StatusBadge,
} from '../system/shared'
import { type ApiCollection, inputClass } from '../system/utils'
import { visibleEquipmentColumns } from './equipmentColumns'

export type FieldPermissionMeta = Record<string, { read?: boolean; update?: boolean; export?: boolean; hidden?: boolean }>

export type EquipmentLocation = {
  id: number
  parent_id?: number | null
  name: string
  code: string
  sort_order?: number | null
  status: 'active' | 'disabled'
  children?: EquipmentLocation[]
}

export type Equipment = {
  id: number
  equipment_no: string
  name: string
  manufacturer?: string | null
  model?: string | null
  serial_no?: string | null
  location_id?: number | null
  location?: EquipmentLocation | null
  legacy_placement?: string | null
  purchase_date?: string | null
  enable_date?: string | null
  calibration_date?: string | null
  calibration_duration?: string | null
  next_calibration_date?: string | null
  status: 'active' | 'disabled' | 'maintenance' | 'retired'
  device_image?: string | null
  manual_files?: string[] | null
  instruction_files?: string[] | null
  calibration_files?: string[] | null
  other_files?: string[] | null
  remark?: string | null
  _field_permissions?: FieldPermissionMeta
}

type EquipmentFilters = {
  search: string
  status: string
  location_id: string
  manufacturer: string
  calibration_due_from: string
  calibration_due_to: string
}

const emptyFilters: EquipmentFilters = {
  search: '',
  status: '',
  location_id: '',
  manufacturer: '',
  calibration_due_from: '',
  calibration_due_to: '',
}

export function EquipmentListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [filters, setFilters] = useState<EquipmentFilters>(emptyFilters)
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const equipmentQuery = useQuery({
    queryKey: ['equipment', filters],
    queryFn: async () => {
      const response = await api.get<ApiCollection<Equipment>>('/api/equipment', { params: cleanParams(filters) })

      return response.data
    },
  })
  const locationsQuery = useQuery({
    queryKey: ['equipment-locations'],
    queryFn: async () => {
      const response = await api.get<ApiCollection<EquipmentLocation>>('/api/equipment-locations')

      return response.data.data
    },
  })
  const deleteEquipment = useMutation({
    mutationFn: async (equipment: Equipment) => {
      await api.delete(`/api/equipment/${equipment.id}`)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['equipment'] }),
  })
  const equipment = equipmentQuery.data?.data ?? []
  const fieldPermissions = equipmentQuery.data?.meta?.fields as FieldPermissionMeta | undefined
  const columns = visibleEquipmentColumns(fieldPermissions)
  const flatLocations = flattenLocations(locationsQuery.data ?? [])

  function startCreate() {
    void navigate({ to: '/equipment/new' })
  }

  function startEdit(target: Equipment) {
    void navigate({ to: '/equipment/$equipmentId/edit', params: { equipmentId: String(target.id) } })
  }

  function toggleSelected(id: number) {
    setSelectedIds((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]))
  }

  function openLabels() {
    localStorage.setItem('new_lims_label_equipment_ids', JSON.stringify(selectedIds))
    window.location.assign('/equipment/labels')
  }

  return (
    <PageShell
      title="Equipment Ledger"
      description="Instrument ledger, calibration dates, locations and batch label printing."
      actions={
        <>
          <PermissionGate resource="equipment_labels" action="print">
            <Button variant="secondary" disabled={selectedIds.length === 0} onClick={openLabels}>
              <Printer className="size-4" aria-hidden="true" />
              {zhText('Labels')} ({selectedIds.length})
            </Button>
          </PermissionGate>
          <PermissionGate resource="equipment" action="create">
            <Button variant="primary" onClick={startCreate}>
              <Plus className="size-4" aria-hidden="true" />
              New equipment
            </Button>
          </PermissionGate>
        </>
      }
    >
      <Panel title="Filters">
        <div className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
          <Field label="Search">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input
                className={`${inputClass} pl-9`}
                value={filters.search}
                onChange={(event) => setFilters({ ...filters, search: event.target.value })}
                placeholder={zhText('name, no., model') ?? undefined}
              />
            </div>
          </Field>
          <Field label="Status">
            <select className={inputClass} value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
              <option value="">{zhText('All')}</option>
              {['active', 'maintenance', 'retired', 'disabled'].map((status) => (
                <option value={status} key={status}>
                  {zhText(status)}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Location">
            <select className={inputClass} value={filters.location_id} onChange={(event) => setFilters({ ...filters, location_id: event.target.value })}>
              <option value="">{zhText('All')}</option>
              {flatLocations.map((location) => (
                <option value={location.id} key={location.id}>
                  {'-'.repeat(location.depth)} {location.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Manufacturer">
            <input className={inputClass} value={filters.manufacturer} onChange={(event) => setFilters({ ...filters, manufacturer: event.target.value })} />
          </Field>
          <Field label="Due from" className="sm:col-span-2 lg:col-span-3 2xl:col-span-1">
            <input
              className={inputClass}
              type="date"
              value={filters.calibration_due_from}
              onChange={(event) => setFilters({ ...filters, calibration_due_from: event.target.value })}
            />
          </Field>
          <Field label="Due to" className="sm:col-span-2 lg:col-span-3 2xl:col-span-1">
            <input
              className={inputClass}
              type="date"
              value={filters.calibration_due_to}
              onChange={(event) => setFilters({ ...filters, calibration_due_to: event.target.value })}
            />
          </Field>
        </div>
      </Panel>

      {equipmentQuery.isError ? <ErrorNotice error={equipmentQuery.error} fallback="Unable to load equipment" /> : null}
      {deleteEquipment.error ? <ErrorNotice error={deleteEquipment.error} fallback="Unable to disable equipment" /> : null}

      {equipmentQuery.isPending ? <LoadingState label="Loading equipment" /> : null}
      {!equipmentQuery.isPending && equipment.length === 0 ? (
        <EmptyState title="No equipment found" description="Adjust filters or create the first equipment record." />
      ) : null}
      {equipment.length > 0 ? (
        <>
          <DataTable>
                <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                  <tr>
                    <th className="px-3 py-2 font-medium">Print</th>
                    {columns.map((column) => (
                      <th className="px-3 py-2 font-medium" key={column.key}>
                        {column.label}
                      </th>
                    ))}
                    <th className="px-3 py-2 font-medium">Location</th>
                    <th className="px-3 py-2 font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {equipment.map((item) => (
                    <tr className="align-top" key={item.id}>
                      <td className="px-3 py-3">
                        <input
                          className="size-4 rounded border-slate-300 text-emerald-600"
                          type="checkbox"
                          checked={selectedIds.includes(item.id)}
                          onChange={() => toggleSelected(item.id)}
                        />
                      </td>
                      {columns.map((column) => (
                        <td className="px-3 py-3 text-sm text-slate-700" key={column.key}>
                          {column.key === 'status' ? <StatusBadge status={item.status} /> : String(item[column.key] ?? '-')}
                        </td>
                      ))}
                      <td className="px-3 py-3 text-sm text-slate-700">{item.location?.name ?? '-'}</td>
                      <td className="px-3 py-3">
                        <EquipmentActions equipment={item} onEdit={startEdit} onDelete={(target) => deleteEquipment.mutate(target)} />
                      </td>
                    </tr>
                  ))}
                </tbody>
          </DataTable>

          <div className="space-y-3 md:hidden">
                {equipment.map((item) => (
                  <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" key={item.id}>
                    <div className="flex items-start justify-between gap-3">
                      <label className="flex min-w-0 items-start gap-3">
                        <input
                          className="mt-0.5 size-4 rounded border-slate-300 text-emerald-600"
                          type="checkbox"
                          checked={selectedIds.includes(item.id)}
                          onChange={() => toggleSelected(item.id)}
                        />
                        <span className="min-w-0">
                          <span className="block truncate text-sm font-semibold text-slate-950">{item.name}</span>
                          <span className="block truncate text-xs text-slate-500">
                            {item.equipment_no} · {item.model ?? '-'}
                          </span>
                        </span>
                      </label>
                      <StatusBadge status={item.status} />
                    </div>
                    <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
                      <div>
                        <dt className="text-slate-500">Location</dt>
                        <dd className="font-medium text-slate-800">{item.location?.name ?? '-'}</dd>
                      </div>
                      <div>
                        <dt className="text-slate-500">Next calibration</dt>
                        <dd className="font-medium text-slate-800">{item.next_calibration_date ?? '-'}</dd>
                      </div>
                    </dl>
                    <div className="mt-3">
                      <EquipmentActions equipment={item} onEdit={startEdit} onDelete={(target) => deleteEquipment.mutate(target)} />
                    </div>
                  </article>
                ))}
          </div>
        </>
      ) : null}
    </PageShell>
  )
}

function EquipmentActions({
  equipment,
  onEdit,
  onDelete,
}: {
  equipment: Equipment
  onEdit: (equipment: Equipment) => void
  onDelete: (equipment: Equipment) => void
}) {
  return (
    <div className="flex flex-wrap gap-2">
      <PermissionGate resource="equipment" action="update">
        <Button variant="secondary" onClick={() => onEdit(equipment)}>
          <Edit3 className="size-4" aria-hidden="true" />
          Edit
        </Button>
      </PermissionGate>
      <PermissionGate resource="equipment" action="delete">
        <Button variant="danger" onClick={() => onDelete(equipment)}>
          <Trash2 className="size-4" aria-hidden="true" />
          Disable
        </Button>
      </PermissionGate>
    </div>
  )
}

function cleanParams(filters: EquipmentFilters) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
}


function flattenLocations(locations: EquipmentLocation[], depth = 0): Array<EquipmentLocation & { depth: number }> {
  return locations.flatMap((location) => [{ ...location, depth }, ...flattenLocations(location.children ?? [], depth + 1)])
}
