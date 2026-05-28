import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Save } from 'lucide-react'
import { useMemo, useState } from 'react'
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
import { type ApiCollection, inputClass, textareaClass } from '../utils'

type DictionaryItem = {
  id: number
  label: string
  value: string
  color?: string | null
  sort_order?: number | null
  is_default: boolean
  status: 'active' | 'disabled'
}

type DictionarySet = {
  id: number
  code: string
  name: string
  description?: string | null
  status: 'active' | 'disabled'
  items: DictionaryItem[]
}

type SetForm = {
  code: string
  name: string
  description: string
  status: 'active' | 'disabled'
}

type ItemForm = {
  label: string
  value: string
  color: string
  sort_order: string
  is_default: boolean
  status: 'active' | 'disabled'
}

const emptySetForm: SetForm = { code: '', name: '', description: '', status: 'active' }
const emptyItemForm: ItemForm = { label: '', value: '', color: '', sort_order: '0', is_default: false, status: 'active' }
const emptyDictionaries: DictionarySet[] = []

export function DictionaryListPage() {
  const queryClient = useQueryClient()
  const [selectedSetId, setSelectedSetId] = useState<number | null>(null)
  const [editingSet, setEditingSet] = useState<DictionarySet | null>(null)
  const [setFormOpen, setSetFormOpen] = useState(false)
  const [setForm, setSetForm] = useState<SetForm>(emptySetForm)
  const [editingItem, setEditingItem] = useState<DictionaryItem | null>(null)
  const [itemFormOpen, setItemFormOpen] = useState(false)
  const [itemForm, setItemForm] = useState<ItemForm>(emptyItemForm)
  const dictionariesQuery = useQuery({
    queryKey: ['dictionaries'],
    queryFn: async () => {
      const response = await api.get<ApiCollection<DictionarySet>>('/api/dictionaries')

      return response.data.data
    },
  })
  const dictionaries = dictionariesQuery.data ?? emptyDictionaries
  const selectedSet = useMemo(
    () => dictionaries.find((dictionary) => dictionary.id === selectedSetId) ?? dictionaries[0],
    [dictionaries, selectedSetId],
  )
  const saveSet = useMutation({
    mutationFn: async () => {
      if (editingSet) {
        await api.put(`/api/dictionaries/${editingSet.id}`, setForm)
        return
      }

      await api.post('/api/dictionaries', setForm)
    },
    onSuccess: async () => {
      setEditingSet(null)
      setSetFormOpen(false)
      setSetForm(emptySetForm)
      await queryClient.invalidateQueries({ queryKey: ['dictionaries'] })
    },
  })
  const saveItem = useMutation({
    mutationFn: async () => {
      if (!selectedSet) {
        return
      }

      const payload = {
        ...itemForm,
        color: itemForm.color || null,
        sort_order: Number(itemForm.sort_order || 0),
      }

      if (editingItem) {
        await api.put(`/api/dictionaries/${selectedSet.id}/items/${editingItem.id}`, payload)
        return
      }

      await api.post(`/api/dictionaries/${selectedSet.id}/items`, payload)
    },
    onSuccess: async () => {
      setEditingItem(null)
      setItemFormOpen(false)
      setItemForm(emptyItemForm)
      await queryClient.invalidateQueries({ queryKey: ['dictionaries'] })
    },
  })

  function editSet(dictionary: DictionarySet) {
    setEditingSet(dictionary)
    setSetFormOpen(true)
    setSetForm({
      code: dictionary.code,
      name: dictionary.name,
      description: dictionary.description ?? '',
      status: dictionary.status,
    })
  }

  function editItem(item: DictionaryItem) {
    setEditingItem(item)
    setItemFormOpen(true)
    setItemForm({
      label: item.label,
      value: item.value,
      color: item.color ?? '',
      sort_order: String(item.sort_order ?? 0),
      is_default: item.is_default,
      status: item.status,
    })
  }

  return (
    <PageShell
      title="Data Dictionaries"
      description="Manage selectable values such as sample status, conclusions and equipment states."
      actions={
        <PermissionGate resource="system.dictionaries" action="create">
          <Button
            variant="primary"
            onClick={() => {
              setEditingSet(null)
              setSetForm(emptySetForm)
              setSetFormOpen(true)
            }}
          >
            <Plus className="size-4" aria-hidden="true" />
            New set
          </Button>
        </PermissionGate>
      }
    >
      {dictionariesQuery.isPending ? <LoadingState label="Loading dictionaries" /> : null}
      {dictionariesQuery.isError ? <ErrorNotice error={dictionariesQuery.error} fallback="Unable to load dictionaries" /> : null}

      <div className="grid gap-4 xl:grid-cols-[360px_minmax(0,1fr)]">
        <Panel title="Dictionary sets">
          {dictionaries.length === 0 ? <EmptyState title="No dictionary sets" description="Create a set before adding items." /> : null}
          <div className="space-y-2">
            {dictionaries.map((dictionary) => (
              <button
                className={`w-full rounded-md border px-3 py-2 text-left ${
                  selectedSet?.id === dictionary.id ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200 bg-white'
                }`}
                type="button"
                onClick={() => setSelectedSetId(dictionary.id)}
                key={dictionary.id}
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="text-sm font-semibold text-slate-900">{dictionary.name}</span>
                  <StatusBadge status={dictionary.status} />
                </div>
                <div className="mt-1 text-xs text-slate-500">
                  {dictionary.code} · {dictionary.items.length} items
                </div>
              </button>
            ))}
          </div>
        </Panel>

        <Panel title={selectedSet ? `${selectedSet.name} items` : 'Items'}>
          {selectedSet ? (
            <>
              <div className="mb-3 flex flex-wrap gap-2">
                <PermissionGate resource="system.dictionaries" action="update">
                  <Button variant="secondary" onClick={() => editSet(selectedSet)}>
                    Edit set
                  </Button>
                  <Button
                    variant="secondary"
                    onClick={() => {
                      setEditingItem(null)
                      setItemForm(emptyItemForm)
                      setItemFormOpen(true)
                    }}
                  >
                    <Plus className="size-4" aria-hidden="true" />
                    New item
                  </Button>
                </PermissionGate>
              </div>
              <DataTable>
                <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                  <tr>
                    <th className="px-3 py-2 font-medium">Label</th>
                    <th className="px-3 py-2 font-medium">Value</th>
                    <th className="px-3 py-2 font-medium">Color</th>
                    <th className="px-3 py-2 font-medium">Status</th>
                    <th className="px-3 py-2 font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {selectedSet.items.map((item) => (
                    <tr key={item.id}>
                      <td className="px-3 py-2 font-medium text-slate-900">{item.label}</td>
                      <td className="px-3 py-2 text-slate-600">{item.value}</td>
                      <td className="px-3 py-2">
                        <span className="inline-flex items-center gap-2 text-xs text-slate-600">
                          <span className="size-3 rounded-sm border border-slate-300" style={{ backgroundColor: item.color ?? '#e2e8f0' }} />
                          {item.color ?? '-'}
                        </span>
                      </td>
                      <td className="px-3 py-2">
                        <StatusBadge status={item.status} />
                      </td>
                      <td className="px-3 py-2">
                        <PermissionGate resource="system.dictionaries" action="update">
                          <Button variant="ghost" onClick={() => editItem(item)}>
                            Edit
                          </Button>
                        </PermissionGate>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>

              <div className="space-y-2 md:hidden">
                {selectedSet.items.map((item) => (
                  <button className="w-full rounded-md border border-slate-200 bg-white p-3 text-left" type="button" onClick={() => editItem(item)} key={item.id}>
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-sm font-semibold text-slate-900">{item.label}</span>
                      <StatusBadge status={item.status} />
                    </div>
                    <div className="mt-1 text-xs text-slate-500">{item.value}</div>
                  </button>
                ))}
              </div>
            </>
          ) : null}
        </Panel>

      </div>
      <Modal title={editingSet ? 'Edit set' : 'Create set'} open={setFormOpen} onClose={() => setSetFormOpen(false)}>
        {saveSet.error ? <ErrorNotice error={saveSet.error} fallback="Unable to save dictionary set" /> : null}
        <div className="space-y-3">
          <Field label="Code">
            <input className={inputClass} value={setForm.code} onChange={(event) => setSetForm({ ...setForm, code: event.target.value })} />
          </Field>
          <Field label="Name">
            <input className={inputClass} value={setForm.name} onChange={(event) => setSetForm({ ...setForm, name: event.target.value })} />
          </Field>
          <Field label="Description">
            <textarea className={textareaClass} value={setForm.description} onChange={(event) => setSetForm({ ...setForm, description: event.target.value })} />
          </Field>
          <Field label="Status">
            <select className={inputClass} value={setForm.status} onChange={(event) => setSetForm({ ...setForm, status: event.target.value as SetForm['status'] })}>
              <option value="active">active</option>
              <option value="disabled">disabled</option>
            </select>
          </Field>
          <PermissionGate resource="system.dictionaries" action={editingSet ? 'update' : 'create'}>
            <Button variant="primary" disabled={saveSet.isPending || setForm.code === '' || setForm.name === ''} onClick={() => saveSet.mutate()}>
              <Save className="size-4" aria-hidden="true" />
              Save set
            </Button>
          </PermissionGate>
        </div>
      </Modal>
      <Modal title={editingItem ? 'Edit item' : 'Create item'} open={itemFormOpen} onClose={() => setItemFormOpen(false)}>
        {saveItem.error ? <ErrorNotice error={saveItem.error} fallback="Unable to save dictionary item" /> : null}
        <div className="space-y-3">
          <Field label="Label">
            <input className={inputClass} value={itemForm.label} onChange={(event) => setItemForm({ ...itemForm, label: event.target.value })} />
          </Field>
          <Field label="Value">
            <input className={inputClass} value={itemForm.value} onChange={(event) => setItemForm({ ...itemForm, value: event.target.value })} />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Color">
              <input className={inputClass} value={itemForm.color} onChange={(event) => setItemForm({ ...itemForm, color: event.target.value })} />
            </Field>
            <Field label="Sort">
              <input
                className={inputClass}
                type="number"
                value={itemForm.sort_order}
                onChange={(event) => setItemForm({ ...itemForm, sort_order: event.target.value })}
              />
            </Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Status">
              <select className={inputClass} value={itemForm.status} onChange={(event) => setItemForm({ ...itemForm, status: event.target.value as ItemForm['status'] })}>
                <option value="active">active</option>
                <option value="disabled">disabled</option>
              </select>
            </Field>
            <label className="flex items-center gap-2 pt-6 text-sm text-slate-700">
              <input
                className="size-4 rounded border-slate-300 text-emerald-600"
                type="checkbox"
                checked={itemForm.is_default}
                onChange={(event) => setItemForm({ ...itemForm, is_default: event.target.checked })}
              />
              Default
            </label>
          </div>
          <PermissionGate resource="system.dictionaries" action="update">
            <Button variant="primary" disabled={saveItem.isPending || !selectedSet || itemForm.label === '' || itemForm.value === ''} onClick={() => saveItem.mutate()}>
              <Save className="size-4" aria-hidden="true" />
              Save item
            </Button>
          </PermissionGate>
        </div>
      </Modal>
    </PageShell>
  )
}
