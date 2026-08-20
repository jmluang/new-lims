import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit3, Eye, Plus, Search, Trash2, X } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { QrScannerPanel } from '../../components/app/QrScannerPanel'
import { api } from '../../lib/api'
import {
  integratingSphereEquipmentQueryKey,
  integratingSphereMutationHandlers,
  integratingSphereRecordsQueryKey,
} from './integratingSphereQueries'
import { useEffectivePermissions } from '../auth/useCurrentUser'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, PaginationControls, Panel } from '../system/shared'
import { type ApiCollection, type ApiResource, inputClass, localDateTimeInputValue, textareaClass } from '../system/utils'
import {
  addEquipmentSnapshot,
  buildIntegratingSphereEquipmentListParams,
  buildIntegratingSphereInspectionListParams,
  buildIntegratingSphereInspectionPayload,
  emptyIntegratingSphereEquipmentFilters,
  emptyIntegratingSphereInspectionFilters,
  emptyIntegratingSphereInspectionForm,
  equipmentEntryKey,
  integratingSphereFieldErrors,
  integratingSphereMeasurementFields,
  inspectionFormFromRecord,
  normalizeMeasurementInput,
  removeEquipmentSnapshot,
  selectedSample,
  type IntegratingSphereEquipmentFilters,
  type IntegratingSphereEquipmentLedgerRow,
  type IntegratingSphereEquipmentOption,
  type IntegratingSphereFormEquipment,
  type IntegratingSphereInspectionFilters,
  type IntegratingSphereInspectionForm,
  type IntegratingSphereInspectionRecord,
  type IntegratingSphereSampleOption,
  type IntegratingSphereView,
} from './integratingSphereInspectionSchema'

const RESOURCE = 'integrating_sphere_inspection_records'

export function IntegratingSphereInspectionPage() {
  const queryClient = useQueryClient()
  const permissions = useEffectivePermissions()
  const [view, setView] = useState<IntegratingSphereView>('records')
  const [filters, setFilters] = useState<IntegratingSphereInspectionFilters>(emptyIntegratingSphereInspectionFilters)
  const [equipmentFilters, setEquipmentFilters] = useState<IntegratingSphereEquipmentFilters>(emptyIntegratingSphereEquipmentFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [equipmentPage, setEquipmentPage] = useState(1)
  const [equipmentPerPage, setEquipmentPerPage] = useState(15)
  const [editorOpen, setEditorOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState<IntegratingSphereInspectionForm>(() => emptyIntegratingSphereInspectionForm(localDateTimeInputValue()))
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [detail, setDetail] = useState<IntegratingSphereInspectionRecord | null>(null)
  const canDelete = Boolean(permissions.data?.resources[RESOURCE]?.actions.delete)
  const recordsQuery = useQuery({
    queryKey: [...integratingSphereRecordsQueryKey, filters, page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<IntegratingSphereInspectionRecord>>('/api/integrating-sphere-inspection-records', {
        params: buildIntegratingSphereInspectionListParams(filters, page, perPage),
      })

      return response.data
    },
  })
  const equipmentLedgerQuery = useQuery({
    queryKey: [...integratingSphereEquipmentQueryKey, equipmentFilters, equipmentPage, equipmentPerPage],
    enabled: view === 'equipment',
    queryFn: async () => {
      const response = await api.get<ApiCollection<IntegratingSphereEquipmentLedgerRow>>(
        '/api/integrating-sphere-inspection-records/equipment',
        { params: buildIntegratingSphereEquipmentListParams(equipmentFilters, equipmentPage, equipmentPerPage) },
      )

      return response.data
    },
  })
  const lookupEquipment = useMutation({
    mutationFn: async (code: string) => {
      const response = await api.get<ApiResource<IntegratingSphereEquipmentOption>>('/api/integrating-sphere-inspection-records/lookup', {
        params: { type: 'equipment', code },
      })

      return response.data.data
    },
    onSuccess: (device) => {
      setForm((current) => ({ ...current, equipment: addEquipmentSnapshot(current.equipment, device) }))
      setFieldErrors((current) => ({ ...current, equipment: '' }))
    },
  })
  const lookupSample = useMutation({
    mutationFn: async (code: string) => {
      const response = await api.get<ApiResource<IntegratingSphereSampleOption>>('/api/integrating-sphere-inspection-records/lookup', {
        params: { type: 'sample', code },
      })

      return response.data.data
    },
    onSuccess: (sample) => {
      // A lookup is the operator explicitly replacing the sample, so it is marked as
      // such and will re-snapshot on save; anything loaded from the record stays retained.
      setForm((current) => ({ ...current, sample: selectedSample(sample) }))
      setFieldErrors((current) => ({ ...current, sample: '' }))
    },
  })
  const mutationHandlers = integratingSphereMutationHandlers(queryClient, closeEditor)
  const saveRecord = useMutation({
    mutationFn: async () => {
      if (editingId === null) {
        await api.post('/api/integrating-sphere-inspection-records', buildIntegratingSphereInspectionPayload(form, 'create'))

        return
      }

      await api.put(
        `/api/integrating-sphere-inspection-records/${editingId}`,
        buildIntegratingSphereInspectionPayload(form, 'update'),
      )
    },
    onError: (error) => setFieldErrors(integratingSphereFieldErrors(error)),
    onSuccess: mutationHandlers.saveSuccess,
  })
  const deleteRecord = useMutation({
    mutationFn: async (record: IntegratingSphereInspectionRecord) => {
      await api.delete(`/api/integrating-sphere-inspection-records/${record.id}`)
    },
    onSuccess: mutationHandlers.deleteSuccess,
  })
  const records = recordsQuery.data?.data ?? []
  const equipmentRows = equipmentLedgerQuery.data?.data ?? []

  function openCreate() {
    setEditingId(null)
    setForm(emptyIntegratingSphereInspectionForm(localDateTimeInputValue()))
    setFieldErrors({})
    saveRecord.reset()
    lookupEquipment.reset()
    lookupSample.reset()
    setEditorOpen(true)
  }

  function openEdit(record: IntegratingSphereInspectionRecord) {
    setEditingId(record.id)
    setForm(inspectionFormFromRecord(record))
    setFieldErrors({})
    saveRecord.reset()
    lookupEquipment.reset()
    lookupSample.reset()
    setEditorOpen(true)
  }

  function closeEditor() {
    setEditorOpen(false)
    setEditingId(null)
    setFieldErrors({})
  }

  function submitEditor() {
    setFieldErrors({})

    try {
      buildIntegratingSphereInspectionPayload(form, editingId === null ? 'create' : 'update')
    } catch (error) {
      setFieldErrors(integratingSphereFieldErrors(error))

      return
    }

    saveRecord.mutate()
  }

  return (
    <PageShell
      title="积分球点检记录"
      description="扫码或手输设备编号后选择样品，记录积分球点检测量值并保留使用设备的历史快照。"
      actions={
        <PermissionGate resource={RESOURCE} action="create">
          <Button variant="primary" onClick={openCreate}>
            <Plus className="size-4" aria-hidden="true" />
            新增点检记录
          </Button>
        </PermissionGate>
      }
    >
      <div className="flex flex-wrap gap-2" role="tablist" data-integrating-sphere-views>
        {(
          [
            ['records', '点检记录总表'],
            ['equipment', '使用设备总表'],
          ] as Array<[IntegratingSphereView, string]>
        ).map(([value, label]) => (
          <Button
            key={value}
            role="tab"
            aria-selected={view === value}
            variant={view === value ? 'primary' : 'secondary'}
            onClick={() => setView(value)}
          >
            {label}
          </Button>
        ))}
      </div>

      {view === 'records' ? (
        <>
          <Panel title="Filters">
            <div className="grid gap-3 md:grid-cols-4">
              <Field label="样品/设备">
                <div className="relative">
                  <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
                  <input
                    className={`${inputClass} pl-9`}
                    value={filters.search}
                    onChange={(event) => {
                      setFilters({ ...filters, search: event.target.value })
                      setPage(1)
                    }}
                    placeholder="样品编号/设备编号/设备名称"
                  />
                </div>
              </Field>
              <Field label="开始日期">
                <input
                  className={inputClass}
                  type="date"
                  value={filters.date_from}
                  onChange={(event) => {
                    setFilters({ ...filters, date_from: event.target.value })
                    setPage(1)
                  }}
                />
              </Field>
              <Field label="结束日期">
                <input
                  className={inputClass}
                  type="date"
                  value={filters.date_to}
                  onChange={(event) => {
                    setFilters({ ...filters, date_to: event.target.value })
                    setPage(1)
                  }}
                />
              </Field>
              <div className="flex items-end">
                <Button
                  variant="secondary"
                  onClick={() => {
                    setFilters(emptyIntegratingSphereInspectionFilters)
                    setPage(1)
                  }}
                >
                  重置
                </Button>
              </div>
            </div>
          </Panel>

          {recordsQuery.isError ? <ErrorNotice error={recordsQuery.error} fallback="无法加载积分球点检记录" /> : null}
          {deleteRecord.error ? <ErrorNotice error={deleteRecord.error} fallback="无法删除积分球点检记录" /> : null}
          {recordsQuery.isPending ? <LoadingState label="正在加载积分球点检记录" /> : null}
          {!recordsQuery.isPending && records.length === 0 ? (
            <EmptyState title="暂无积分球点检记录" description="新增点检记录后会显示测量值、记录日期和操作人。" />
          ) : null}

          {records.length > 0 ? (
            <>
              <InspectionRecordTable records={records} canDelete={canDelete} onDetail={setDetail} onEdit={openEdit} onDelete={(record) => deleteRecord.mutate(record)} />
              <InspectionRecordCards records={records} canDelete={canDelete} onDetail={setDetail} onEdit={openEdit} onDelete={(record) => deleteRecord.mutate(record)} />
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
        </>
      ) : (
        <>
          <Panel title="Filters">
            <div className="grid gap-3 md:grid-cols-5">
              <Field label="搜索">
                <div className="relative">
                  <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
                  <input
                    className={`${inputClass} pl-9`}
                    value={equipmentFilters.search}
                    onChange={(event) => {
                      setEquipmentFilters({ ...equipmentFilters, search: event.target.value })
                      setEquipmentPage(1)
                    }}
                    placeholder="编号/名称/制造商/型号/序列号/ID"
                  />
                </div>
              </Field>
              <Field label="点检记录ID">
                <input
                  className={inputClass}
                  inputMode="numeric"
                  value={equipmentFilters.inspection_record_id}
                  onChange={(event) => {
                    setEquipmentFilters({ ...equipmentFilters, inspection_record_id: event.target.value })
                    setEquipmentPage(1)
                  }}
                />
              </Field>
              <Field label="设备台账ID">
                <input
                  className={inputClass}
                  inputMode="numeric"
                  value={equipmentFilters.equipment_id}
                  onChange={(event) => {
                    setEquipmentFilters({ ...equipmentFilters, equipment_id: event.target.value })
                    setEquipmentPage(1)
                  }}
                />
              </Field>
              <Field label="开始日期">
                <input
                  className={inputClass}
                  type="date"
                  value={equipmentFilters.date_from}
                  onChange={(event) => {
                    setEquipmentFilters({ ...equipmentFilters, date_from: event.target.value })
                    setEquipmentPage(1)
                  }}
                />
              </Field>
              <Field label="结束日期">
                <input
                  className={inputClass}
                  type="date"
                  value={equipmentFilters.date_to}
                  onChange={(event) => {
                    setEquipmentFilters({ ...equipmentFilters, date_to: event.target.value })
                    setEquipmentPage(1)
                  }}
                />
              </Field>
              <div className="flex items-end">
                <Button
                  variant="secondary"
                  onClick={() => {
                    setEquipmentFilters(emptyIntegratingSphereEquipmentFilters)
                    setEquipmentPage(1)
                  }}
                >
                  重置
                </Button>
              </div>
            </div>
          </Panel>

          {equipmentLedgerQuery.isError ? <ErrorNotice error={equipmentLedgerQuery.error} fallback="无法加载使用设备总表" /> : null}
          {equipmentLedgerQuery.isPending ? <LoadingState label="正在加载使用设备总表" /> : null}
          {!equipmentLedgerQuery.isPending && equipmentRows.length === 0 ? (
            <EmptyState title="暂无使用设备记录" description="新增点检记录并录入设备后，这里会显示每条记录与设备的关联。" />
          ) : null}

          {equipmentRows.length > 0 ? (
            <>
              <InspectionEquipmentTable rows={equipmentRows} />
              <InspectionEquipmentCards rows={equipmentRows} />
            </>
          ) : null}

          <PaginationControls
            meta={equipmentLedgerQuery.data?.meta}
            page={equipmentPage}
            perPage={equipmentPerPage}
            onPageChange={setEquipmentPage}
            onPerPageChange={(nextPerPage) => {
              setEquipmentPerPage(nextPerPage)
              setEquipmentPage(1)
            }}
          />
        </>
      )}

      <Modal title={editingId === null ? '新增积分球点检记录' : '编辑积分球点检记录'} size="wide" open={editorOpen} onClose={closeEditor}>
        <InspectionRecordFormFields
          form={form}
          fieldErrors={fieldErrors}
          equipmentLookupFailed={lookupEquipment.isError}
          sampleLookupFailed={lookupSample.isError}
          onEquipmentCode={(code) => lookupEquipment.mutate(code)}
          onSampleCode={(code) => lookupSample.mutate(code)}
          onRemoveEquipment={(key) => setForm((current) => ({ ...current, equipment: removeEquipmentSnapshot(current.equipment, key) }))}
          onChange={(patch) => setForm((current) => ({ ...current, ...patch }))}
        />
        {saveRecord.error ? <ErrorNotice error={saveRecord.error} fallback="无法保存积分球点检记录" /> : null}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="ghost" onClick={closeEditor}>
            取消
          </Button>
          <Button variant="primary" onClick={submitEditor} disabled={saveRecord.isPending}>
            保存点检记录
          </Button>
        </div>
      </Modal>

      <Modal title="积分球点检记录详情" size="wide" open={detail !== null} onClose={() => setDetail(null)}>
        {detail ? <InspectionRecordDetail record={detail} /> : null}
      </Modal>
    </PageShell>
  )
}

type RecordActionProps = {
  records: IntegratingSphereInspectionRecord[]
  canDelete: boolean
  onDetail: (record: IntegratingSphereInspectionRecord) => void
  onEdit: (record: IntegratingSphereInspectionRecord) => void
  onDelete: (record: IntegratingSphereInspectionRecord) => void
}

/**
 * The desktop table stays at the measurements an operator scans a list for; the
 * complete device snapshot lives in the detail modal so the row set keeps fitting
 * a common laptop width.
 */
export function InspectionRecordTable({ records, canDelete, onDetail, onEdit, onDelete }: RecordActionProps) {
  return (
    <DataTable>
      <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
        <tr>
          <th className="px-3 py-2 font-medium">ID</th>
          <th className="px-3 py-2 font-medium">样品编号</th>
          <th className="px-3 py-2 font-medium">色品坐标 X</th>
          <th className="px-3 py-2 font-medium">色品坐标 Y</th>
          <th className="px-3 py-2 font-medium">主波长</th>
          <th className="px-3 py-2 font-medium">峰值波长</th>
          <th className="px-3 py-2 font-medium">色温</th>
          <th className="px-3 py-2 font-medium">显色指数 Ra</th>
          <th className="px-3 py-2 font-medium">光通量</th>
          <th className="px-3 py-2 font-medium">使用设备</th>
          <th className="px-3 py-2 font-medium">记录日期</th>
          <th className="px-3 py-2 font-medium">操作人</th>
          <th className="px-3 py-2 font-medium">操作</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-slate-200">
        {records.map((record) => (
          <tr className="align-top" key={record.id}>
            <td className="px-3 py-3 text-sm font-medium text-slate-900">{record.id}</td>
            <td className="px-3 py-3 text-sm font-medium text-slate-900">{record.sample_no}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.chromaticity_x}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.chromaticity_y}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.dominant_wavelength}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.peak_wavelength}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.color_temperature}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.color_rendering_index}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.luminous_flux}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{equipmentSummary(record)}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.recorded_at}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.operator_name ?? '-'}</td>
            <td className="px-3 py-3">
              <InspectionRecordActions record={record} canDelete={canDelete} onDetail={onDetail} onEdit={onEdit} onDelete={onDelete} />
            </td>
          </tr>
        ))}
      </tbody>
    </DataTable>
  )
}

export function InspectionRecordCards({ records, canDelete, onDetail, onEdit, onDelete }: RecordActionProps) {
  return (
    <div className="space-y-3 md:hidden" data-mobile-integrating-sphere-records>
      {records.map((record) => (
        <article className="rounded-lg border border-emerald-900/10 bg-white p-4 shadow-sm" key={record.id}>
          <div className="min-w-0">
            <h3 className="truncate text-sm font-semibold text-slate-950">样品 {record.sample_no}</h3>
            <p className="mt-0.5 truncate text-xs text-slate-500">{equipmentSummary(record)}</p>
          </div>
          <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
            <CardEntry label="ID" value={record.id} />
            <CardEntry label="色品坐标 X" value={record.chromaticity_x} />
            <CardEntry label="色品坐标 Y" value={record.chromaticity_y} />
            <CardEntry label="色温" value={`${record.color_temperature} K`} />
            <CardEntry label="显色指数 Ra" value={record.color_rendering_index} />
            <CardEntry label="光通量" value={`${record.luminous_flux} lm`} />
            <CardEntry label="主波长" value={`${record.dominant_wavelength} nm`} />
            <CardEntry label="记录日期" value={record.recorded_at} />
            <CardEntry label="操作人" value={record.operator_name ?? '-'} />
          </dl>
          <div className="mt-3">
            <InspectionRecordActions record={record} canDelete={canDelete} onDetail={onDetail} onEdit={onEdit} onDelete={onDelete} />
          </div>
        </article>
      ))}
    </div>
  )
}

function CardEntry({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="min-w-0">
      <dt className="text-slate-500">{label}</dt>
      <dd className="truncate font-medium text-slate-800">{value}</dd>
    </div>
  )
}

function InspectionRecordActions({
  record,
  canDelete,
  onDetail,
  onEdit,
  onDelete,
}: {
  record: IntegratingSphereInspectionRecord
  canDelete: boolean
  onDetail: (record: IntegratingSphereInspectionRecord) => void
  onEdit: (record: IntegratingSphereInspectionRecord) => void
  onDelete: (record: IntegratingSphereInspectionRecord) => void
}) {
  return (
    <div className="flex flex-wrap gap-2">
      <Button variant="secondary" onClick={() => onDetail(record)}>
        <Eye className="size-4" aria-hidden="true" />
        详情
      </Button>
      <PermissionGate resource={RESOURCE} action="update">
        <Button variant="secondary" onClick={() => onEdit(record)}>
          <Edit3 className="size-4" aria-hidden="true" />
          编辑
        </Button>
      </PermissionGate>
      {canDelete ? (
        <Button variant="danger" onClick={() => onDelete(record)}>
          <Trash2 className="size-4" aria-hidden="true" />
          删除
        </Button>
      ) : null}
    </div>
  )
}

/**
 * The global used-equipment ledger view. Every row is one association between an
 * inspection record and a device, carrying the three ids the sheet is keyed on.
 */
export function InspectionEquipmentTable({ rows }: { rows: IntegratingSphereEquipmentLedgerRow[] }) {
  return (
    <DataTable>
      <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
        <tr>
          <th className="px-3 py-2 font-medium">ID</th>
          <th className="px-3 py-2 font-medium">点检记录ID</th>
          <th className="px-3 py-2 font-medium">设备台账ID</th>
          <th className="px-3 py-2 font-medium">设备编号</th>
          <th className="px-3 py-2 font-medium">名称</th>
          <th className="px-3 py-2 font-medium">制造商</th>
          <th className="px-3 py-2 font-medium">型号</th>
          <th className="px-3 py-2 font-medium">序列号</th>
          <th className="px-3 py-2 font-medium">下次校准</th>
          <th className="px-3 py-2 font-medium">日期</th>
          <th className="px-3 py-2 font-medium">操作人</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-slate-200">
        {rows.map((row) => (
          <tr className="align-top" key={row.id}>
            <td className="px-3 py-3 text-sm font-medium text-slate-900">{row.id}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{row.inspection_record_id}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{row.equipment_id ?? '已删除'}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{row.equipment_no}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{row.equipment_name}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{row.manufacturer ?? '-'}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{row.model ?? '-'}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{row.serial_no ?? '-'}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{row.next_calibration_date ?? '-'}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{row.recorded_at ?? '-'}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{row.operator_name ?? '-'}</td>
          </tr>
        ))}
      </tbody>
    </DataTable>
  )
}

export function InspectionEquipmentCards({ rows }: { rows: IntegratingSphereEquipmentLedgerRow[] }) {
  return (
    <div className="space-y-3 md:hidden" data-mobile-integrating-sphere-equipment>
      {rows.map((row) => (
        <article className="rounded-lg border border-emerald-900/10 bg-white p-4 shadow-sm" key={row.id}>
          <div className="min-w-0">
            <h3 className="truncate text-sm font-semibold text-slate-950">{row.equipment_no}</h3>
            <p className="mt-0.5 truncate text-xs text-slate-500">{row.equipment_name}</p>
          </div>
          <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
            <CardEntry label="ID" value={row.id} />
            <CardEntry label="点检记录ID" value={row.inspection_record_id} />
            <CardEntry label="设备台账ID" value={row.equipment_id ?? '已删除'} />
            <CardEntry label="制造商" value={row.manufacturer ?? '-'} />
            <CardEntry label="型号" value={row.model ?? '-'} />
            <CardEntry label="序列号" value={row.serial_no ?? '-'} />
            <CardEntry label="下次校准" value={row.next_calibration_date ?? '-'} />
            <CardEntry label="日期" value={row.recorded_at ?? '-'} />
            <CardEntry label="操作人" value={row.operator_name ?? '-'} />
          </dl>
        </article>
      ))}
    </div>
  )
}

export function InspectionRecordDetail({ record }: { record: IntegratingSphereInspectionRecord }) {
  return (
    <div className="space-y-4">
      <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <DetailEntry label="样品编号" value={record.sample_no} />
        <DetailEntry label="记录日期" value={record.recorded_at} />
        <DetailEntry label="操作人" value={record.operator_name ?? '-'} />
        <DetailEntry label="备注" value={record.remark ?? '-'} />
        {integratingSphereMeasurementFields.map((field) => (
          <DetailEntry key={field.name} label={field.label} value={`${record[field.name]}${field.unit ? ` ${field.unit}` : ''}`} />
        ))}
      </dl>
      <div className="overflow-x-auto rounded-lg border border-emerald-900/10" data-inspection-equipment-snapshots>
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
            <tr>
              <th className="px-3 py-2 font-medium">设备编号</th>
              <th className="px-3 py-2 font-medium">设备名称</th>
              <th className="px-3 py-2 font-medium">生产厂家</th>
              <th className="px-3 py-2 font-medium">规格型号</th>
              <th className="px-3 py-2 font-medium">出厂编号</th>
              <th className="px-3 py-2 font-medium">下次校准日期</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {record.equipment.map((device) => (
              <tr key={device.id}>
                <td className="px-3 py-2 font-medium text-slate-900">{device.equipment_no}</td>
                <td className="px-3 py-2 text-slate-700">{device.equipment_name}</td>
                <td className="px-3 py-2 text-slate-700">{device.manufacturer ?? '-'}</td>
                <td className="px-3 py-2 text-slate-700">{device.model ?? '-'}</td>
                <td className="px-3 py-2 text-slate-700">{device.serial_no ?? '-'}</td>
                <td className="px-3 py-2 text-slate-700">{device.next_calibration_date ?? '-'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function DetailEntry({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className="mt-0.5 break-words font-medium text-slate-900">{value}</dd>
    </div>
  )
}

export function InspectionRecordFormFields({
  form,
  fieldErrors,
  equipmentLookupFailed,
  sampleLookupFailed,
  onEquipmentCode,
  onSampleCode,
  onRemoveEquipment,
  onChange,
}: {
  form: IntegratingSphereInspectionForm
  fieldErrors: Record<string, string>
  equipmentLookupFailed: boolean
  sampleLookupFailed: boolean
  onEquipmentCode: (code: string) => void
  onSampleCode: (code: string) => void
  onRemoveEquipment: (key: string) => void
  onChange: (patch: Partial<IntegratingSphereInspectionForm>) => void
}) {
  return (
    <div className="space-y-4">
      <div className="space-y-2">
        <QrScannerPanel title="使用设备（先录入）" placeholder="扫码/手输设备编号" onDetected={onEquipmentCode}>
          <SelectedEquipment devices={form.equipment} onRemove={onRemoveEquipment} />
        </QrScannerPanel>
        {equipmentLookupFailed ? <ErrorNotice error="未找到设备" fallback="未找到设备" /> : null}
        <FieldError message={fieldErrors.equipment} />
      </div>

      <div className="space-y-2">
        <QrScannerPanel title="样品编号" placeholder="扫码/手输样品编号" onDetected={onSampleCode}>
          {form.sample ? (
            <div className="space-y-1">
              <p className="text-xs text-slate-600">
                <span className="font-semibold text-slate-900">{form.sample.sample_no}</span>
                {form.sample.sample_name ? ` · ${form.sample.sample_name}` : ''}
                {form.sample.model ? ` · ${form.sample.model}` : ''}
              </p>
              {form.sample.source === 'retained' && form.sample.id === null ? (
                <p className="text-xs text-amber-700" data-orphan-sample-notice>
                  该样品已从样品台账删除，保存时保留历史快照；如需更换请重新扫码。
                </p>
              ) : null}
              {form.sample.source === 'retained' && form.sample.id !== null ? (
                <p className="text-xs text-slate-500" data-retained-sample-notice>
                  沿用记录中的样品编号快照；重新扫码或手输才会替换为台账当前编号。
                </p>
              ) : null}
              {form.sample.source === 'selected' ? (
                <p className="text-xs text-emerald-700" data-selected-sample-notice>
                  本次录入，保存时按台账当前编号记录。
                </p>
              ) : null}
            </div>
          ) : (
            <p className="text-xs text-slate-500">尚未录入样品</p>
          )}
        </QrScannerPanel>
        {sampleLookupFailed ? <ErrorNotice error="未找到样品" fallback="未找到样品" /> : null}
        <FieldError message={fieldErrors.sample} />
      </div>

      <Panel title="测量值">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {integratingSphereMeasurementFields.map((field) => (
            <Field key={field.name} label={field.unit ? `${field.label}（${field.unit}）` : field.label}>
              <input
                className={inputClass}
                inputMode={field.scale === 0 ? 'numeric' : 'decimal'}
                value={form[field.name]}
                placeholder={field.scale === 0 ? '0' : `0.${'0'.repeat(field.scale)}`}
                onChange={(event) => onChange({ [field.name]: event.target.value } as Partial<IntegratingSphereInspectionForm>)}
                onBlur={(event) => {
                  const normalized = normalizeMeasurementInput(event.target.value, field.scale)

                  if (normalized !== null) {
                    onChange({ [field.name]: normalized } as Partial<IntegratingSphereInspectionForm>)
                  }
                }}
              />
              <FieldError message={fieldErrors[field.name]} />
            </Field>
          ))}
          <Field label="记录时间">
            <input className={inputClass} type="datetime-local" value={form.recorded_at} onChange={(event) => onChange({ recorded_at: event.target.value })} />
          </Field>
          <Field label="备注" className="sm:col-span-2 lg:col-span-4">
            <textarea className={textareaClass} value={form.remark} onChange={(event) => onChange({ remark: event.target.value })} />
          </Field>
        </div>
      </Panel>
    </div>
  )
}

function FieldError({ message }: { message?: string }) {
  if (!message) {
    return null
  }

  return <p className="mt-1 text-xs text-red-600">{message}</p>
}

/**
 * Shows exactly what will be stored: devices resolved from the live ledger, and
 * retained snapshots whose ledger row has since been deleted. The latter are
 * labelled so the operator can see the record still carries them and that removing
 * one throws away the only remaining evidence of that device.
 */
function SelectedEquipment({ devices, onRemove }: { devices: IntegratingSphereFormEquipment[]; onRemove: (key: string) => void }) {
  if (devices.length === 0) {
    return <p className="text-xs text-slate-500">尚未录入设备</p>
  }

  const orphanCount = devices.filter((device) => device.equipment_id === null).length

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap gap-2">
        {devices.map((device) => (
          <span
            className={
              device.equipment_id === null
                ? 'inline-flex items-center gap-1 rounded-md border border-amber-300 bg-amber-50 px-2 py-1 text-xs text-amber-900'
                : 'inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs text-emerald-800'
            }
            key={equipmentEntryKey(device)}
          >
            {device.equipment_no}
            <button
              type="button"
              className={device.equipment_id === null ? 'text-amber-800 hover:text-amber-950' : 'text-emerald-700 hover:text-emerald-900'}
              aria-label={`移除 ${device.equipment_no}`}
              onClick={() => onRemove(equipmentEntryKey(device))}
            >
              <X className="size-3" aria-hidden="true" />
            </button>
          </span>
        ))}
      </div>
      {orphanCount > 0 ? (
        <p className="text-xs text-amber-700" data-orphan-equipment-notice>
          其中 {orphanCount} 台设备已从设备台账删除，保存时保留历史快照；移除后将无法恢复。
        </p>
      ) : null}
      <div className="overflow-x-auto rounded-md border border-emerald-900/10" data-selected-equipment-details>
        <table className="min-w-full divide-y divide-slate-200 text-xs">
          <thead className="bg-slate-50 text-left uppercase text-slate-500">
            <tr>
              <th className="px-2 py-1.5 font-medium">编号</th>
              <th className="px-2 py-1.5 font-medium">名称</th>
              <th className="px-2 py-1.5 font-medium">厂家</th>
              <th className="px-2 py-1.5 font-medium">型号</th>
              <th className="px-2 py-1.5 font-medium">序列号</th>
              <th className="px-2 py-1.5 font-medium">下次校准</th>
              <th className="px-2 py-1.5 font-medium">来源</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {devices.map((device) => (
              <tr key={equipmentEntryKey(device)}>
                <td className="px-2 py-1.5 font-medium text-slate-900">{device.equipment_no}</td>
                <td className="px-2 py-1.5 text-slate-700">{device.equipment_name}</td>
                <td className="px-2 py-1.5 text-slate-700">{device.manufacturer ?? '-'}</td>
                <td className="px-2 py-1.5 text-slate-700">{device.model ?? '-'}</td>
                <td className="px-2 py-1.5 text-slate-700">{device.serial_no ?? '-'}</td>
                <td className="px-2 py-1.5 text-slate-700">{device.next_calibration_date ?? '-'}</td>
                <td className="px-2 py-1.5">
                  {device.equipment_id === null ? (
                    <span className="rounded bg-amber-100 px-1.5 py-0.5 text-amber-900">台账已删除 · 保留快照</span>
                  ) : (
                    <span className="text-slate-500">{device.child_id === null ? '本次录入' : '设备台账'}</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function equipmentSummary(record: IntegratingSphereInspectionRecord) {
  if (record.equipment.length === 0) {
    return '-'
  }

  return record.equipment.map((device) => device.equipment_no).join('、')
}
