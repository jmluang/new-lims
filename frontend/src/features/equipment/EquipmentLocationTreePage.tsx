import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Save, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { Button, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, Panel, StatusBadge } from '../system/shared'
import { type ApiCollection, inputClass } from '../system/utils'
import type { EquipmentLocation } from './EquipmentListPage'

type LocationForm = {
  parent_id: string
  name: string
  code: string
  sort_order: string
  status: 'active' | 'disabled'
}

const emptyForm: LocationForm = { parent_id: '', name: '', code: '', sort_order: '0', status: 'active' }

export function EquipmentLocationTreePage() {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<EquipmentLocation | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const [form, setForm] = useState<LocationForm>(emptyForm)
  const locationsQuery = useQuery({
    queryKey: ['equipment-locations'],
    queryFn: async () => {
      const response = await api.get<ApiCollection<EquipmentLocation>>('/api/equipment-locations')

      return response.data.data
    },
  })
  const saveLocation = useMutation({
    mutationFn: async () => {
      const payload = {
        parent_id: form.parent_id ? Number(form.parent_id) : null,
        name: form.name,
        code: form.code,
        sort_order: Number(form.sort_order || 0),
        status: form.status,
      }

      if (editing) {
        await api.put(`/api/equipment-locations/${editing.id}`, payload)
        return
      }

      await api.post('/api/equipment-locations', payload)
    },
    onSuccess: async () => {
      setEditing(null)
      setFormOpen(false)
      setForm(emptyForm)
      await queryClient.invalidateQueries({ queryKey: ['equipment-locations'] })
    },
  })
  const disableLocation = useMutation({
    mutationFn: async (location: EquipmentLocation) => {
      await api.delete(`/api/equipment-locations/${location.id}`)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['equipment-locations'] }),
  })
  const locations = locationsQuery.data ?? []
  const flatLocations = flattenLocations(locations)

  function editLocation(location: EquipmentLocation) {
    setEditing(location)
    setFormOpen(true)
    setForm({
      parent_id: location.parent_id ? String(location.parent_id) : '',
      name: location.name,
      code: location.code,
      sort_order: String(location.sort_order ?? 0),
      status: location.status,
    })
  }

  return (
    <PageShell
      title="Equipment Locations"
      description="Nested equipment locations with stable ordering and disable protection."
      actions={
        <PermissionGate resource="equipment_locations" action="create">
          <Button
            variant="primary"
            onClick={() => {
              setEditing(null)
              setForm(emptyForm)
              setFormOpen(true)
            }}
          >
            <Plus className="size-4" aria-hidden="true" />
            New root
          </Button>
        </PermissionGate>
      }
    >
      <Panel title="Location tree">
        {locationsQuery.isPending ? <LoadingState label="Loading locations" /> : null}
        {locationsQuery.isError ? <ErrorNotice error={locationsQuery.error} fallback="Unable to load locations" /> : null}
        {disableLocation.error ? <ErrorNotice error={disableLocation.error} fallback="Location cannot be disabled" /> : null}
        {!locationsQuery.isPending && locations.length === 0 ? (
          <EmptyState title="No locations" description="Create a root location, then add child rooms or benches." />
        ) : null}
        <div className="space-y-2">
          {locations.map((location) => (
            <LocationNode location={location} onEdit={editLocation} onDisable={(target) => disableLocation.mutate(target)} key={location.id} />
          ))}
        </div>
      </Panel>

      <Modal
        title={editing ? 'Edit location' : 'Create location'}
        open={formOpen}
        onClose={() => {
          setFormOpen(false)
          setEditing(null)
        }}
      >
          {saveLocation.error ? <ErrorNotice error={saveLocation.error} fallback="Unable to save location" /> : null}
          <div className="space-y-3">
            <Field label="Parent">
              <select className={inputClass} value={form.parent_id} onChange={(event) => setForm({ ...form, parent_id: event.target.value })}>
                <option value="">{zhText('Root')}</option>
                {flatLocations
                  .filter((location) => location.id !== editing?.id)
                  .map((location) => (
                    <option value={location.id} key={location.id}>
                      {'-'.repeat(location.depth)} {location.name}
                    </option>
                  ))}
              </select>
            </Field>
            <Field label="Name">
              <input className={inputClass} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
            </Field>
            <Field label="Code">
              <input className={inputClass} value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} />
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Sort">
                <input className={inputClass} type="number" value={form.sort_order} onChange={(event) => setForm({ ...form, sort_order: event.target.value })} />
              </Field>
              <Field label="Status">
                <select className={inputClass} value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value as LocationForm['status'] })}>
                  <option value="active">{zhText('active')}</option>
                  <option value="disabled">{zhText('disabled')}</option>
                </select>
              </Field>
            </div>
            <PermissionGate resource="equipment_locations" action={editing ? 'update' : 'create'}>
              <Button variant="primary" disabled={saveLocation.isPending || form.name === '' || form.code === ''} onClick={() => saveLocation.mutate()}>
                <Save className="size-4" aria-hidden="true" />
                Save location
              </Button>
            </PermissionGate>
          </div>
      </Modal>
    </PageShell>
  )
}

function LocationNode({
  location,
  onEdit,
  onDisable,
  depth = 0,
}: {
  location: EquipmentLocation
  onEdit: (location: EquipmentLocation) => void
  onDisable: (location: EquipmentLocation) => void
  depth?: number
}) {
  return (
    <div className="space-y-2">
      <div className="rounded-md border border-slate-200 bg-white px-3 py-2" style={{ marginLeft: depth * 16 }}>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <div className="text-sm font-semibold text-slate-900">{location.name}</div>
            <div className="text-xs text-slate-500">{location.code}</div>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={location.status} />
            <PermissionGate resource="equipment_locations" action="update">
              <Button variant="secondary" onClick={() => onEdit(location)}>
                Edit
              </Button>
            </PermissionGate>
            <PermissionGate resource="equipment_locations" action="delete">
              <Button variant="danger" onClick={() => onDisable(location)}>
                <Trash2 className="size-4" aria-hidden="true" />
                Disable
              </Button>
            </PermissionGate>
          </div>
        </div>
      </div>
      {location.children?.map((child) => <LocationNode location={child} onEdit={onEdit} onDisable={onDisable} depth={depth + 1} key={child.id} />)}
    </div>
  )
}

function flattenLocations(locations: EquipmentLocation[], depth = 0): Array<EquipmentLocation & { depth: number }> {
  return locations.flatMap((location) => [{ ...location, depth }, ...flattenLocations(location.children ?? [], depth + 1)])
}
