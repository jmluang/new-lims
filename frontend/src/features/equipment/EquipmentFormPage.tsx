import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useRouterState } from '@tanstack/react-router'
import { ArrowLeft } from 'lucide-react'
import { api } from '../../lib/api'
import { ErrorNotice, LoadingState, PageShell, Panel } from '../system/shared'
import type { ApiCollection, ApiResource } from '../system/utils'
import { EquipmentForm } from './EquipmentForm'
import type { Equipment, EquipmentLocation, FieldPermissionMeta } from './EquipmentListPage'
import type { EquipmentFormValues } from './equipmentSchema'

export function EquipmentFormPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const pathname = useRouterState({ select: (state) => state.location.pathname })
  const equipmentId = equipmentIdFromPath(pathname)
  const isEditing = equipmentId !== null
  const locationsQuery = useQuery({
    queryKey: ['equipment-locations'],
    queryFn: async () => {
      const response = await api.get<ApiCollection<EquipmentLocation>>('/api/equipment-locations')

      return response.data.data
    },
  })
  const equipmentQuery = useQuery({
    queryKey: ['equipment-record', equipmentId],
    enabled: isEditing,
    queryFn: async () => {
      const response = await api.get<ApiResource<Equipment> & { meta?: { fields?: FieldPermissionMeta } }>(`/api/equipment/${equipmentId}`)

      return response.data
    },
  })
  const createMetaQuery = useQuery({
    queryKey: ['equipment-form-meta'],
    enabled: !isEditing,
    queryFn: async () => {
      const response = await api.get<ApiCollection<Equipment>>('/api/equipment', { params: { per_page: 1 } })

      return response.data.meta?.fields as FieldPermissionMeta | undefined
    },
  })
  const saveEquipment = useMutation({
    mutationFn: async (values: EquipmentFormValues) => {
      const payload = normalizeEquipmentPayload(values)

      if (isEditing) {
        await api.put(`/api/equipment/${equipmentId}`, payload)
        return
      }

      await api.post('/api/equipment', payload)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['equipment'] })
      await navigate({ to: '/equipment' })
    },
  })
  const fieldPermissions = equipmentQuery.data?.meta?.fields ?? createMetaQuery.data
  const loading =
    locationsQuery.isPending ||
    (isEditing && equipmentQuery.isPending) ||
    (!isEditing && createMetaQuery.isPending)

  return (
    <PageShell
      title={isEditing ? 'Edit equipment' : 'Create equipment'}
      description="Instrument ledger, calibration dates, locations and batch label printing."
      actions={
        <Link className="inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100" to="/equipment">
          <ArrowLeft className="size-4" aria-hidden="true" />
          返回列表
        </Link>
      }
    >
      <Panel title={isEditing ? 'Edit equipment' : 'Create equipment'}>
        {locationsQuery.isError ? <ErrorNotice error={locationsQuery.error} fallback="Unable to load locations" /> : null}
        {equipmentQuery.isError ? <ErrorNotice error={equipmentQuery.error} fallback="Unable to load equipment" /> : null}
        {createMetaQuery.isError ? <ErrorNotice error={createMetaQuery.error} fallback="Unable to load equipment" /> : null}
        {loading ? <LoadingState label="Loading data" /> : null}
        {!loading && !locationsQuery.isError && !equipmentQuery.isError && !createMetaQuery.isError ? (
          <EquipmentForm
            equipment={equipmentQuery.data?.data ?? null}
            locations={locationsQuery.data ?? []}
            fieldPermissions={fieldPermissions}
            submitting={saveEquipment.isPending}
            error={saveEquipment.error}
            onSubmit={(values) => saveEquipment.mutateAsync(values)}
            onCancel={() => navigate({ to: '/equipment' })}
          />
        ) : null}
      </Panel>
    </PageShell>
  )
}

function equipmentIdFromPath(pathname: string) {
  const match = pathname.match(/^\/equipment\/(\d+)\/edit$/)

  return match ? Number(match[1]) : null
}

function normalizeEquipmentPayload(values: EquipmentFormValues) {
  const payload = Object.fromEntries(
    Object.entries(values)
      .filter(([, value]) => value !== undefined)
      .map(([key, value]) => [key, value === '' ? null : value]),
  ) as Record<string, unknown>

  payload.location_id = values.location_id ? Number(values.location_id) : null

  for (const field of ['manual_files', 'instruction_files', 'calibration_files', 'other_files'] as const) {
    if (values[field] !== undefined) {
      payload[field] = splitFiles(values[field])
    }
  }

  return payload
}

function splitFiles(value?: string) {
  if (!value) {
    return null
  }

  return value
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}
