import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit3, Plus } from 'lucide-react'
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
  Modal,
  PageShell,
  Panel,
  StatusBadge,
} from '../shared'
import { type ApiCollection, type ApiResource, inputClass, textareaClass } from '../utils'
import { type PermissionCatalog, PermissionMatrix } from './PermissionMatrix'

type Group = {
  id: number
  name: string
  description?: string | null
  is_system: boolean
  status: 'active' | 'disabled'
  permissions: string[]
}

type GroupForm = {
  name: string
  description: string
  status: 'active' | 'disabled'
  is_system: boolean
}

const emptyForm: GroupForm = { name: '', description: '', status: 'active', is_system: false }

export function GroupListPage() {
  const queryClient = useQueryClient()
  const [selectedGroupId, setSelectedGroupId] = useState<number | null>(null)
  const [editingGroup, setEditingGroup] = useState<Group | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const [form, setForm] = useState<GroupForm>(emptyForm)
  const groupsQuery = useQuery({
    queryKey: ['system-groups'],
    queryFn: async () => {
      const response = await api.get<ApiCollection<Group>>('/api/system/groups')

      return response.data.data
    },
  })
  const catalogQuery = useQuery({
    queryKey: ['permission-catalog'],
    queryFn: async () => {
      const response = await api.get<ApiResource<PermissionCatalog>>('/api/system/permissions/catalog')

      return response.data.data
    },
  })
  const saveGroup = useMutation({
    mutationFn: async () => {
      if (editingGroup) {
        await api.put(`/api/system/groups/${editingGroup.id}`, form)
        return
      }

      await api.post('/api/system/groups', form)
    },
    onSuccess: async () => {
      setEditingGroup(null)
      setFormOpen(false)
      setForm(emptyForm)
      await queryClient.invalidateQueries({ queryKey: ['system-groups'] })
    },
  })
  const savePermissions = useMutation({
    mutationFn: async ({ group, permissions }: { group: Group; permissions: string[] }) => {
      await api.put(`/api/system/groups/${group.id}/permissions`, { permissions })
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['system-groups'] }),
  })
  const groups = groupsQuery.data ?? []
  const selectedGroup = groups.find((group) => group.id === selectedGroupId) ?? groups[0]

  function editGroup(group: Group) {
    setEditingGroup(group)
    setFormOpen(true)
    setForm({
      name: group.name,
      description: group.description ?? '',
      status: group.status,
      is_system: group.is_system,
    })
  }

  return (
    <PageShell
      title="Group Permissions"
      description="Assign permissions directly to groups. A user may belong to multiple groups."
      actions={
        <PermissionGate resource="system.groups" action="create">
          <Button
            variant="primary"
            onClick={() => {
              setEditingGroup(null)
              setForm(emptyForm)
              setFormOpen(true)
            }}
          >
            <Plus className="size-4" aria-hidden="true" />
            New group
          </Button>
        </PermissionGate>
      }
    >
      <div className="grid gap-4 xl:grid-cols-[minmax(0,420px)_1fr]">
        <div className="space-y-4">
          <Panel title="Groups">
            {groupsQuery.isPending ? <LoadingState label="Loading groups" /> : null}
            {groupsQuery.isError ? <ErrorNotice error={groupsQuery.error} fallback="Unable to load groups" /> : null}
            {!groupsQuery.isPending && groups.length === 0 ? (
              <EmptyState title="No groups" description="Create a group before assigning permissions." />
            ) : null}
            <div className="space-y-2">
              {groups.map((group) => (
                <button
                  className={`w-full rounded-md border px-3 py-2 text-left ${
                    selectedGroup?.id === group.id ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200 bg-white'
                  }`}
                  type="button"
                  onClick={() => setSelectedGroupId(group.id)}
                  key={group.id}
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-sm font-medium text-slate-900">{group.name}</span>
                    <StatusBadge status={group.status} />
                  </div>
                  <div className="mt-1 text-xs text-slate-500">{group.permissions.length} permissions</div>
                </button>
              ))}
            </div>
          </Panel>
        </div>

        <Panel title="Permission matrix" description={selectedGroup ? `Editing ${selectedGroup.name}` : 'Select a group'}>
          {catalogQuery.isPending ? <LoadingState label="Loading permissions" /> : null}
          {catalogQuery.isError ? <ErrorNotice error={catalogQuery.error} fallback="Unable to load permission catalog" /> : null}
          {savePermissions.error ? <ErrorNotice error={savePermissions.error} fallback="Unable to save permissions" /> : null}
          {selectedGroup && catalogQuery.data ? (
            <div className="space-y-3">
              <PermissionGate resource="system.groups" action="update">
                <Button variant="secondary" onClick={() => editGroup(selectedGroup)}>
                  <Edit3 className="size-4" aria-hidden="true" />
                  Edit group info
                </Button>
              </PermissionGate>
              <PermissionMatrix
                key={selectedGroup.id}
                catalog={catalogQuery.data}
                selectedPermissions={selectedGroup.permissions}
                saving={savePermissions.isPending}
                onSave={(permissions) => savePermissions.mutate({ group: selectedGroup, permissions })}
              />
            </div>
          ) : null}
        </Panel>
      </div>

      <Modal
        title={editingGroup ? 'Edit group' : 'Create group'}
        open={formOpen}
        onClose={() => {
          setFormOpen(false)
          setEditingGroup(null)
        }}
      >
        {saveGroup.error ? <ErrorNotice error={saveGroup.error} fallback="Unable to save group" /> : null}
        <form className="space-y-3" onSubmit={(event) => event.preventDefault()}>
          <Field label="Name">
            <input className={inputClass} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
          </Field>
          <Field label="Description">
            <textarea
              className={textareaClass}
              value={form.description}
              onChange={(event) => setForm({ ...form, description: event.target.value })}
            />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Status">
              <select
                className={inputClass}
                value={form.status}
                onChange={(event) => setForm({ ...form, status: event.target.value as GroupForm['status'] })}
              >
                <option value="active">active</option>
                <option value="disabled">disabled</option>
              </select>
            </Field>
            <label className="flex items-center gap-2 pt-6 text-sm text-slate-700">
              <input
                className="size-4 rounded border-slate-300 text-emerald-600"
                type="checkbox"
                checked={form.is_system}
                onChange={(event) => setForm({ ...form, is_system: event.target.checked })}
              />
              System group
            </label>
          </div>
          <PermissionGate resource="system.groups" action={editingGroup ? 'update' : 'create'}>
            <Button variant="primary" disabled={saveGroup.isPending || form.name === ''} onClick={() => saveGroup.mutate()}>
              Save group
            </Button>
          </PermissionGate>
        </form>
      </Modal>

      <DataTable>
        <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
          <tr>
            <th className="px-3 py-2 font-medium">Group</th>
            <th className="px-3 py-2 font-medium">Description</th>
            <th className="px-3 py-2 font-medium">Status</th>
            <th className="px-3 py-2 font-medium">Permissions</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-200">
          {groups.map((group) => (
            <tr key={group.id}>
              <td className="px-3 py-2 font-medium text-slate-900">{group.name}</td>
              <td className="px-3 py-2 text-slate-600">{group.description ?? '-'}</td>
              <td className="px-3 py-2">
                <StatusBadge status={group.status} />
              </td>
              <td className="px-3 py-2 text-slate-600">{group.permissions.length}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
    </PageShell>
  )
}
