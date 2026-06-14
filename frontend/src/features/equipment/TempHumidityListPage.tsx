import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit3, Plus, Search, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { PermissionGate } from '../../components/app/PermissionGate'
import { QrScannerPanel } from '../../components/app/QrScannerPanel'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { useCurrentUser } from '../auth/useCurrentUser'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, PaginationControls, Panel } from '../system/shared'
import { type ApiCollection, type ApiResource, formatDateTime, inputClass, textareaClass } from '../system/utils'
import {
  applyDetectedEquipmentCode,
  applyLookupEquipment,
  buildTempHumidityListParams,
  emptyTempHumidityFilters,
  equipmentLookupErrorText,
  randomReadingDefault,
  type TempHumidityFilters,
} from './tempHumidityPageState'
import { tempHumiditySchema, type TempHumidityFormValues } from './tempHumiditySchema'
import type { TempHumidityEquipmentLookup } from './tempHumidityTypes'

const datetimeLocalInputClass = `${inputClass} max-w-full [max-inline-size:100%] [min-inline-size:0]`

export type TempHumidityRecord = {
  id: number
  equipment_id?: number | null
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
  const [filters, setFilters] = useState<TempHumidityFilters>(emptyTempHumidityFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(30)
  const [editing, setEditing] = useState<TempHumidityRecord | null>(null)
  const [lookupEquipment, setLookupEquipment] = useState<TempHumidityEquipmentLookup | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const recordsQuery = useQuery({
    queryKey: ['temp-humidity-records', filters, page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<TempHumidityRecord>>('/api/temp-humidity-records', {
        params: buildTempHumidityListParams(filters, page, perPage),
      })

      return response.data
    },
  })
  const lookupEquipmentMutation = useMutation({
    mutationFn: async (equipNo: string) => {
      const response = await api.get<ApiResource<TempHumidityEquipmentLookup>>('/api/temp-humidity-records/equipment-lookup', {
        params: { equip_no: equipNo },
      })

      return response.data.data
    },
    onMutate: () => {
      setLookupEquipment(null)
    },
    onSuccess: (equipment) => {
      setLookupEquipment(equipment)
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
      setLookupEquipment(null)
      lookupEquipmentMutation.reset()
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

  function updateFilter(key: keyof TempHumidityFilters, value: string) {
    setFilters((current) => ({ ...current, [key]: value }))
    setPage(1)
  }

  function openCreate() {
    setEditing(null)
    setLookupEquipment(null)
    lookupEquipmentMutation.reset()
    setFormOpen(true)
    saveRecord.reset()
  }

  function openEdit(record: TempHumidityRecord) {
    setEditing(record)
    setLookupEquipment(null)
    lookupEquipmentMutation.reset()
    setFormOpen(true)
    saveRecord.reset()
  }

  function closeForm() {
    setFormOpen(false)
    setEditing(null)
    setLookupEquipment(null)
    lookupEquipmentMutation.reset()
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
        <div className="grid gap-3 md:grid-cols-4">
          <Field label="Search">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input
                className={`${inputClass} pl-9`}
                value={filters.search}
                onChange={(event) => updateFilter('search', event.target.value)}
                placeholder={zhText('equip no, site, room, person') ?? undefined}
              />
            </div>
          </Field>
          <Field label="开始时间">
            <input className={inputClass} type="date" value={filters.record_time_from} onChange={(event) => updateFilter('record_time_from', event.target.value)} />
          </Field>
          <Field label="结束时间">
            <input className={inputClass} type="date" value={filters.record_time_to} onChange={(event) => updateFilter('record_time_to', event.target.value)} />
          </Field>
          <Field label="最低温度">
            <input className={inputClass} type="number" step="0.1" value={filters.temperature_min} onChange={(event) => updateFilter('temperature_min', event.target.value)} />
          </Field>
          <Field label="最高温度">
            <input className={inputClass} type="number" step="0.1" value={filters.temperature_max} onChange={(event) => updateFilter('temperature_max', event.target.value)} />
          </Field>
          <Field label="最低湿度">
            <input className={inputClass} type="number" step="0.1" value={filters.humidity_min} onChange={(event) => updateFilter('humidity_min', event.target.value)} />
          </Field>
          <Field label="最高湿度">
            <input className={inputClass} type="number" step="0.1" value={filters.humidity_max} onChange={(event) => updateFilter('humidity_max', event.target.value)} />
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

      <Modal title={editing ? 'Edit reading' : 'Add reading'} open={formOpen} onClose={closeForm}>
        {saveRecord.error ? <ErrorNotice error={saveRecord.error} fallback="Unable to save reading" /> : null}
        <TempHumidityForm
          record={editing}
          defaultPerson={currentUser.data?.name ?? ''}
          lookupEquipment={lookupEquipment}
          lookupError={lookupEquipmentMutation.error}
          lookupPending={lookupEquipmentMutation.isPending}
          submitting={saveRecord.isPending}
          onLookupEquipment={(equipNo) => lookupEquipmentMutation.mutate(equipNo)}
          onSubmit={(values) => saveRecord.mutateAsync(values)}
          onCancel={closeForm}
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
  lookupEquipment,
  lookupError,
  lookupPending,
  submitting,
  onLookupEquipment,
  onSubmit,
  onCancel,
}: {
  record: TempHumidityRecord | null
  defaultPerson: string
  lookupEquipment: TempHumidityEquipmentLookup | null
  lookupError: unknown
  lookupPending: boolean
  submitting: boolean
  onLookupEquipment: (equipNo: string) => void
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

  useEffect(() => {
    if (!lookupEquipment || record) {
      return
    }

    const nextValues = applyLookupEquipment(form.getValues(), lookupEquipment, Boolean(record))
    form.setValue('equip_no', nextValues.equip_no, { shouldDirty: true, shouldValidate: true })
    form.setValue('location_site', nextValues.location_site, { shouldDirty: true, shouldValidate: true })
    form.setValue('location_room', nextValues.location_room, { shouldDirty: true, shouldValidate: true })
  }, [form, lookupEquipment, record])

  function handleEquipmentDetected(equipNo: string) {
    const nextValues = applyDetectedEquipmentCode(form.getValues(), equipNo)
    form.setValue('equip_no', nextValues.equip_no, { shouldDirty: true, shouldValidate: true })
    onLookupEquipment(equipNo)
  }

  const locationSite = useWatch({ control: form.control, name: 'location_site' })
  const locationRoom = useWatch({ control: form.control, name: 'location_room' })

  return (
    <form className="space-y-3" onSubmit={form.handleSubmit(onSubmit)}>
      <input type="hidden" {...form.register('location_site')} />
      <input type="hidden" {...form.register('location_room')} />
      <input type="hidden" {...form.register('equip_no')} />
      <input type="hidden" {...form.register('record_person')} />
      <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_20rem]">
        <QrScannerPanel title="扫码/输入设备编号" placeholder="设备编号" onDetected={handleEquipmentDetected} />
        <EquipmentLookupSummary equipment={lookupEquipment} pending={lookupPending} error={lookupError} />
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <ReadOnlyFormValue label="Placement site" value={locationSite} />
        <ReadOnlyFormValue label="Placement room" value={locationRoom} />
        <Field label="Temperature">
          <input className={inputClass} type="number" step="0.1" {...form.register('temperature')} />
        </Field>
        <Field label="Humidity">
          <input className={inputClass} type="number" step="0.1" {...form.register('humidity')} />
        </Field>
        <Field label="Record time">
          <input className={datetimeLocalInputClass} type="datetime-local" {...form.register('record_time')} />
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

function ReadOnlyFormValue({ label, value }: { label: string; value?: string | null }) {
  return (
    <div className="block min-w-0">
      <div className="text-xs font-medium tracking-normal text-slate-600">{zhText(label)}</div>
      <div className="mt-1 flex min-h-9 items-center rounded-md border border-slate-200 bg-slate-50 px-3 text-sm text-slate-700">
        {value?.trim() ? value : '-'}
      </div>
    </div>
  )
}

function EquipmentLookupSummary({
  equipment,
  pending,
  error,
}: {
  equipment: TempHumidityEquipmentLookup | null
  pending: boolean
  error: unknown
}) {
  if (pending) {
    return (
      <Panel title="设备信息">
        <LoadingState label="正在查询设备" />
      </Panel>
    )
  }

  if (error) {
    const message = equipmentLookupErrorText(error)

    return (
      <Panel title="设备信息">
        {message ? <p className="text-sm text-red-700">{message}</p> : <ErrorNotice error={error} fallback="未找到设备" />}
      </Panel>
    )
  }

  if (!equipment) {
    return (
      <Panel title="设备信息">
        <p className="text-sm text-slate-500">扫码或输入设备编号后显示设备信息。</p>
      </Panel>
    )
  }

  return (
    <Panel title="设备信息">
      <dl className="grid gap-2 text-sm">
        <div>
          <dt className="text-slate-500">设备编号</dt>
          <dd className="font-medium text-slate-900">{equipment.equipment_no}</dd>
        </div>
        <div>
          <dt className="text-slate-500">设备名称</dt>
          <dd className="font-medium text-slate-900">{equipment.name}</dd>
        </div>
        <div>
          <dt className="text-slate-500">型号</dt>
          <dd>{equipment.model ?? '-'}</dd>
        </div>
        <div>
          <dt className="text-slate-500">状态</dt>
          <dd>{equipment.status}</dd>
        </div>
        <div>
          <dt className="text-slate-500">校准日期</dt>
          <dd>{equipment.calibration_date ?? '-'}</dd>
        </div>
        <div>
          <dt className="text-slate-500">下次校准</dt>
          <dd>{equipment.next_calibration_date ?? '-'}</dd>
        </div>
      </dl>
    </Panel>
  )
}

function recordDefaults(record: TempHumidityRecord | null, defaultPerson: string): TempHumidityFormValues {
  return {
    location_site: record?.location_site ?? '',
    location_room: record?.location_room ?? '',
    equip_no: record?.equip_no ?? '',
    temperature: record?.temperature != null ? String(record.temperature) : randomReadingDefault(24.5, 25.5),
    humidity: record?.humidity != null ? String(record.humidity) : randomReadingDefault(60, 65),
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
