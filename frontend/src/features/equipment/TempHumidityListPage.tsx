import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit3, Plus, Search, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { PermissionGate } from '../../components/app/PermissionGate'
import { useCurrentUser } from '../auth/useCurrentUser'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, Panel } from '../system/shared'
import { type ApiCollection, formatDateTime, inputClass, textareaClass } from '../system/utils'
import { tempHumiditySchema, type TempHumidityFormValues } from './tempHumiditySchema'

export type TempHumidityRecord = {
  id: number
  equip_no?: string | null
  equipment_name?: string | null
  temperature?: string | number | null
  humidity?: string | number | null
  location_site?: string | null
  location_room?: string | null
  record_person?: string | null
  remark?: string | null
  record_time?: string | null
  created_at?: string | null
}

export function TempHumidityListPage() {
  const queryClient = useQueryClient()
  const currentUser = useCurrentUser()
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<TempHumidityRecord | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const recordsQuery = useQuery({
    queryKey: ['temp-humidity-records', search],
    queryFn: async () => {
      const params = search ? { search } : undefined
      const response = await api.get<ApiCollection<TempHumidityRecord>>('/api/temp-humidity-records', { params })

      return response.data
    },
  })
  const saveRecord = useMutation({
    mutationFn: async (values: TempHumidityFormValues) => {
      const payload = {
        location_site: values.location_site,
        location_room: values.location_room,
        record_person: values.record_person,
        equip_no: emptyToNull(values.equip_no),
        temperature: numberOrNull(values.temperature),
        humidity: numberOrNull(values.humidity),
        remark: emptyToNull(values.remark),
        record_time: emptyToNull(values.record_time),
      }

      if (editing) {
        await api.put(`/api/temp-humidity-records/${editing.id}`, payload)
        return
      }

      await api.post('/api/temp-humidity-records', payload)
    },
    onSuccess: async () => {
      setFormOpen(false)
      setEditing(null)
      await queryClient.invalidateQueries({ queryKey: ['temp-humidity-records'] })
    },
  })
  const deleteRecord = useMutation({
    mutationFn: async (record: TempHumidityRecord) => {
      await api.delete(`/api/temp-humidity-records/${record.id}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['temp-humidity-records'] })
    },
  })
  const records = recordsQuery.data?.data ?? []

  function openCreate() {
    setEditing(null)
    setFormOpen(true)
    saveRecord.reset()
  }

  function openEdit(record: TempHumidityRecord) {
    setEditing(record)
    setFormOpen(true)
    saveRecord.reset()
  }

  return (
    <PageShell
      title="Temperature & Humidity Records"
      description="Readings pushed automatically by monitoring devices, newest first. You can also record entries manually."
      actions={
        <PermissionGate resource="temp_humidity_records" action="create">
          <Button variant="primary" onClick={openCreate}>
            <Plus className="size-4" aria-hidden="true" />
            Add reading
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
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder={zhText('equip no, site, room, person') ?? undefined}
              />
            </div>
          </Field>
        </div>
      </Panel>

      {recordsQuery.isError ? <ErrorNotice error={recordsQuery.error} fallback="Unable to load readings" /> : null}
      {saveRecord.error || deleteRecord.error ? (
        <ErrorNotice error={saveRecord.error ?? deleteRecord.error} fallback="Reading operation failed" />
      ) : null}
      {recordsQuery.isPending ? <LoadingState label="Loading readings" /> : null}
      {!recordsQuery.isPending && records.length === 0 ? (
        <EmptyState title="No readings found" description="Devices will appear here once they push readings." />
      ) : null}
      {records.length > 0 ? (
        <>
          <DataTable>
            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">Equipment no</th>
                <th className="px-3 py-2 font-medium">Equipment name</th>
                <th className="px-3 py-2 font-medium">Temperature</th>
                <th className="px-3 py-2 font-medium">Humidity</th>
                <th className="px-3 py-2 font-medium">Placement site</th>
                <th className="px-3 py-2 font-medium">Placement room</th>
                <th className="px-3 py-2 font-medium">Record person</th>
                <th className="px-3 py-2 font-medium">Record time</th>
                <th className="px-3 py-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {records.map((record) => (
                <tr key={record.id}>
                  <td className="px-3 py-3 text-sm font-medium text-slate-900">{record.equip_no ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{record.equipment_name ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{formatNumber(record.temperature)}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{formatNumber(record.humidity)}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{record.location_site ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{record.location_room ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{record.record_person ?? '-'}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{formatDateTime(record.record_time)}</td>
                  <td className="px-3 py-3">
                    <RecordActions onEdit={() => openEdit(record)} onDelete={() => deleteRecord.mutate(record)} />
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          <div className="space-y-3 md:hidden">
            {records.map((record) => (
              <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" key={record.id}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h2 className="truncate text-sm font-semibold text-slate-950">{record.equip_no ?? '-'}</h2>
                    <p className="truncate text-xs text-slate-500">{record.equipment_name ?? '-'}</p>
                  </div>
                  <div className="shrink-0 text-right text-sm text-slate-700">
                    <div>{formatNumber(record.temperature)} ℃</div>
                    <div>{formatNumber(record.humidity)} %</div>
                  </div>
                </div>
                <dl className="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-500">
                  <div>
                    <dt>{zhText('Placement site')}</dt>
                    <dd className="text-slate-700">{record.location_site ?? '-'}</dd>
                  </div>
                  <div>
                    <dt>{zhText('Placement room')}</dt>
                    <dd className="text-slate-700">{record.location_room ?? '-'}</dd>
                  </div>
                  <div>
                    <dt>{zhText('Record person')}</dt>
                    <dd className="text-slate-700">{record.record_person ?? '-'}</dd>
                  </div>
                  <div>
                    <dt>{zhText('Record time')}</dt>
                    <dd className="text-slate-700">{formatDateTime(record.record_time)}</dd>
                  </div>
                </dl>
                <div className="mt-3">
                  <RecordActions onEdit={() => openEdit(record)} onDelete={() => deleteRecord.mutate(record)} />
                </div>
              </article>
            ))}
          </div>
        </>
      ) : null}

      <Modal
        title={editing ? 'Edit reading' : 'Add reading'}
        open={formOpen}
        onClose={() => {
          setFormOpen(false)
          setEditing(null)
        }}
      >
        {saveRecord.error ? <ErrorNotice error={saveRecord.error} fallback="Unable to save reading" /> : null}
        <TempHumidityForm
          record={editing}
          defaultPerson={currentUser.data?.name ?? ''}
          submitting={saveRecord.isPending}
          onSubmit={(values) => saveRecord.mutateAsync(values)}
          onCancel={() => {
            setFormOpen(false)
            setEditing(null)
          }}
        />
      </Modal>
    </PageShell>
  )
}

function RecordActions({ onEdit, onDelete }: { onEdit: () => void; onDelete: () => void }) {
  return (
    <div className="flex flex-wrap gap-2">
      <PermissionGate resource="temp_humidity_records" action="update">
        <Button variant="secondary" onClick={onEdit}>
          <Edit3 className="size-4" aria-hidden="true" />
          {zhText('Edit')}
        </Button>
      </PermissionGate>
      <PermissionGate resource="temp_humidity_records" action="delete">
        <Button variant="danger" onClick={onDelete}>
          <Trash2 className="size-4" aria-hidden="true" />
          {zhText('Delete')}
        </Button>
      </PermissionGate>
    </div>
  )
}

function TempHumidityForm({
  record,
  defaultPerson,
  submitting,
  onSubmit,
  onCancel,
}: {
  record: TempHumidityRecord | null
  defaultPerson: string
  submitting: boolean
  onSubmit: (values: TempHumidityFormValues) => Promise<void>
  onCancel: () => void
}) {
  const form = useForm<TempHumidityFormValues>({
    resolver: zodResolver(tempHumiditySchema),
    defaultValues: recordDefaults(record, defaultPerson),
  })

  useEffect(() => {
    form.reset(recordDefaults(record, defaultPerson))
  }, [record, defaultPerson, form])

  return (
    <form className="space-y-3" onSubmit={form.handleSubmit(onSubmit)}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Placement site">
          <input className={inputClass} {...form.register('location_site')} />
        </Field>
        <Field label="Placement room">
          <input className={inputClass} {...form.register('location_room')} />
        </Field>
        <Field label="Equipment no">
          <input className={inputClass} {...form.register('equip_no')} />
        </Field>
        <Field label="Record person">
          <input className={inputClass} {...form.register('record_person')} />
        </Field>
        <Field label="Temperature">
          <input className={inputClass} type="number" step="0.1" {...form.register('temperature')} />
        </Field>
        <Field label="Humidity">
          <input className={inputClass} type="number" step="0.1" {...form.register('humidity')} />
        </Field>
        <Field label="Record time">
          <input className={inputClass} type="datetime-local" {...form.register('record_time')} />
        </Field>
        <Field label="Remark" className="sm:col-span-2">
          <textarea className={textareaClass} {...form.register('remark')} />
        </Field>
      </div>
      <div className="mt-3 flex justify-end gap-2">
        <Button type="button" variant="ghost" onClick={onCancel}>
          {zhText('Cancel')}
        </Button>
        <Button type="submit" variant="primary" disabled={submitting}>
          {zhText('Save reading')}
        </Button>
      </div>
    </form>
  )
}

function recordDefaults(record: TempHumidityRecord | null, defaultPerson: string): TempHumidityFormValues {
  return {
    location_site: record?.location_site ?? '',
    location_room: record?.location_room ?? '',
    equip_no: record?.equip_no ?? '',
    temperature: record?.temperature != null ? String(record.temperature) : '',
    humidity: record?.humidity != null ? String(record.humidity) : '',
    record_person: record?.record_person ?? defaultPerson,
    remark: record?.remark ?? '',
    record_time: record ? toDatetimeLocal(record.record_time) : nowLocal(),
  }
}

function formatNumber(value?: string | number | null) {
  if (value === null || value === undefined || value === '') {
    return '-'
  }

  return String(value)
}

function emptyToNull(value?: string | null) {
  const trimmed = value?.trim()
  return trimmed ? trimmed : null
}

function numberOrNull(value?: string | null) {
  const trimmed = value?.trim()
  return trimmed ? Number(trimmed) : null
}

function nowLocal() {
  const date = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

function toDatetimeLocal(value?: string | null) {
  if (!value) {
    return ''
  }

  return value.replace(' ', 'T').slice(0, 16)
}
