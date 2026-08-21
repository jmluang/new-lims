import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Edit3, Eye, Plus, Search, Trash2, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { useEffectivePermissions } from '../auth/useCurrentUser'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, PaginationControls, Panel } from '../system/shared'
import { type ApiCollection, type ApiResource, formatBytes, inputClass, textareaClass } from '../system/utils'
import {
  CardEntry,
  DetailEntry,
  EquipmentLedgerCards,
  EquipmentLedgerTable,
  EquipmentScannerBlock,
  EquipmentSnapshotTable,
  FieldError,
  SampleScannerBlock,
  SystemScannerBlock,
} from './InspectionSharedFields'
import {
  photometricCurveEquipmentQueryKey,
  photometricCurveMutationHandlers,
  photometricCurveRecordsQueryKey,
} from './photometricCurveQueries'
import {
  addEquipmentSnapshot,
  buildPhotometricCurveEquipmentListParams,
  buildPhotometricCurveInspectionListParams,
  buildPhotometricCurveInspectionPayload,
  deriveAverageAngle,
  emptyPhotometricCurveEquipmentFilters,
  emptyPhotometricCurveInspectionFilters,
  emptyPhotometricCurveInspectionForm,
  inspectionFormFromRecord,
  normalizeMeasurementInput,
  photometricCurveFieldErrors,
  photometricCurveMeasurementFields,
  photometricCurveMediaLimits,
  photometricCurveProbes,
  probeLabel,
  removeEquipmentSnapshot,
  selectedSample,
  selectedSystem,
  type PhotometricCurveEquipmentFilters,
  type PhotometricCurveEquipmentLedgerRow,
  type PhotometricCurveEquipmentOption,
  type PhotometricCurveInspectionFilters,
  type PhotometricCurveInspectionForm,
  type PhotometricCurveInspectionRecord,
  type PhotometricCurveMedia,
  type PhotometricCurveProbe,
  type PhotometricCurveSampleOption,
  type PhotometricCurveSystemOption,
  type PhotometricCurveView,
} from './photometricCurveInspectionSchema'

const RESOURCE = 'photometric_curve_inspection_records'
const BASE = '/api/photometric-curve-inspection-records'

export function PhotometricCurveInspectionPage() {
  const queryClient = useQueryClient()
  const permissions = useEffectivePermissions()
  const [view, setView] = useState<PhotometricCurveView>('records')
  const [filters, setFilters] = useState<PhotometricCurveInspectionFilters>(emptyPhotometricCurveInspectionFilters)
  const [equipmentFilters, setEquipmentFilters] = useState<PhotometricCurveEquipmentFilters>(emptyPhotometricCurveEquipmentFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [equipmentPage, setEquipmentPage] = useState(1)
  const [equipmentPerPage, setEquipmentPerPage] = useState(15)
  const [editorOpen, setEditorOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState<PhotometricCurveInspectionForm>(() => emptyPhotometricCurveInspectionForm())
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [detail, setDetail] = useState<PhotometricCurveInspectionRecord | null>(null)
  const canDelete = Boolean(permissions.data?.resources[RESOURCE]?.actions.delete)
  const recordsQuery = useQuery({
    queryKey: [...photometricCurveRecordsQueryKey, filters, page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<PhotometricCurveInspectionRecord>>(BASE, {
        params: buildPhotometricCurveInspectionListParams(filters, page, perPage),
      })

      return response.data
    },
  })
  const equipmentLedgerQuery = useQuery({
    queryKey: [...photometricCurveEquipmentQueryKey, equipmentFilters, equipmentPage, equipmentPerPage],
    enabled: view === 'equipment',
    queryFn: async () => {
      const response = await api.get<ApiCollection<PhotometricCurveEquipmentLedgerRow>>(`${BASE}/equipment`, {
        params: buildPhotometricCurveEquipmentListParams(equipmentFilters, equipmentPage, equipmentPerPage),
      })

      return response.data
    },
  })
  const lookupEquipment = useMutation({
    mutationFn: async (code: string) => {
      const response = await api.get<ApiResource<PhotometricCurveEquipmentOption>>(`${BASE}/lookup`, {
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
      const response = await api.get<ApiResource<PhotometricCurveSampleOption>>(`${BASE}/lookup`, {
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
  const lookupSystem = useMutation({
    mutationFn: async (code: string) => {
      const response = await api.get<ApiResource<PhotometricCurveSystemOption>>(`${BASE}/lookup`, {
        params: { type: 'system', code },
      })

      return response.data.data
    },
    onSuccess: (system) => {
      // The system code is scanned or typed on its own, never derived from the
      // selected devices, and a lookup is the operator explicitly replacing it.
      setForm((current) => ({ ...current, system: selectedSystem(system) }))
      setFieldErrors((current) => ({ ...current, system: '' }))
    },
  })
  const mutationHandlers = photometricCurveMutationHandlers(queryClient, closeEditor)
  const saveRecord = useMutation({
    mutationFn: async () => {
      // Attachments make a JSON body impossible, so both paths post multipart; the
      // edit carries `_method=PUT` because PHP does not populate file bodies on a PUT.
      if (editingId === null) {
        await api.post(BASE, buildPhotometricCurveInspectionPayload(form, 'create'))

        return
      }

      await api.post(`${BASE}/${editingId}`, buildPhotometricCurveInspectionPayload(form, 'update'))
    },
    onError: (error) => setFieldErrors(photometricCurveFieldErrors(error)),
    onSuccess: mutationHandlers.saveSuccess,
  })
  const deleteRecord = useMutation({
    mutationFn: async (record: PhotometricCurveInspectionRecord) => {
      await api.delete(`${BASE}/${record.id}`)
    },
    onSuccess: mutationHandlers.deleteSuccess,
  })
  const records = recordsQuery.data?.data ?? []
  const equipmentRows = equipmentLedgerQuery.data?.data ?? []

  function openCreate() {
    setEditingId(null)
    setForm(emptyPhotometricCurveInspectionForm())
    setFieldErrors({})
    saveRecord.reset()
    lookupEquipment.reset()
    lookupSample.reset()
    lookupSystem.reset()
    setEditorOpen(true)
  }

  function openEdit(record: PhotometricCurveInspectionRecord) {
    setEditingId(record.id)
    setForm(inspectionFormFromRecord(record))
    setFieldErrors({})
    saveRecord.reset()
    lookupEquipment.reset()
    lookupSample.reset()
    lookupSystem.reset()
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
      buildPhotometricCurveInspectionPayload(form, editingId === null ? 'create' : 'update')
    } catch (error) {
      setFieldErrors(photometricCurveFieldErrors(error))

      return
    }

    saveRecord.mutate()
  }

  return (
    <PageShell
      title="配光曲线点检记录"
      description="扫码或手输设备编号、样品编号与系统编码（三者独立录入），再记录配光曲线点检测量值、照片与文件，并保留使用设备的历史快照。"
      actions={
        <PermissionGate resource={RESOURCE} action="create">
          <Button variant="primary" onClick={openCreate}>
            <Plus className="size-4" aria-hidden="true" />
            新增点检记录
          </Button>
        </PermissionGate>
      }
    >
      <div className="flex flex-wrap gap-2" role="tablist" data-photometric-curve-views>
        {(
          [
            ['records', '点检记录总表'],
            ['equipment', '使用设备总表'],
          ] as Array<[PhotometricCurveView, string]>
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
            <div className="grid gap-3 md:grid-cols-5">
              <Field label="样品/系统/设备">
                <div className="relative">
                  <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
                  <input
                    className={`${inputClass} pl-9`}
                    value={filters.search}
                    onChange={(event) => {
                      setFilters({ ...filters, search: event.target.value })
                      setPage(1)
                    }}
                    placeholder="样品编号/系统编码/设备编号"
                  />
                </div>
              </Field>
              <Field label="探头">
                <select
                  className={inputClass}
                  value={filters.probe}
                  onChange={(event) => {
                    setFilters({ ...filters, probe: event.target.value })
                    setPage(1)
                  }}
                >
                  <option value="">全部</option>
                  {photometricCurveProbes.map((probe) => (
                    <option key={probe.value} value={probe.value}>
                      {probe.label}
                    </option>
                  ))}
                </select>
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
                    setFilters(emptyPhotometricCurveInspectionFilters)
                    setPage(1)
                  }}
                >
                  重置
                </Button>
              </div>
            </div>
          </Panel>

          {recordsQuery.isError ? <ErrorNotice error={recordsQuery.error} fallback="无法加载配光曲线点检记录" /> : null}
          {deleteRecord.error ? <ErrorNotice error={deleteRecord.error} fallback="无法删除配光曲线点检记录" /> : null}
          {recordsQuery.isPending ? <LoadingState label="正在加载配光曲线点检记录" /> : null}
          {!recordsQuery.isPending && records.length === 0 ? (
            <EmptyState title="暂无配光曲线点检记录" description="新增点检记录后会显示样品编号、系统编码、平均角度、探头、测量值、记录日期和操作人。" />
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
                    setEquipmentFilters(emptyPhotometricCurveEquipmentFilters)
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

      <Modal title={editingId === null ? '新增配光曲线点检记录' : '编辑配光曲线点检记录'} size="wide" open={editorOpen} onClose={closeEditor}>
        <InspectionRecordFormFields
          form={form}
          recordId={editingId}
          fieldErrors={fieldErrors}
          equipmentLookupFailed={lookupEquipment.isError}
          sampleLookupFailed={lookupSample.isError}
          systemLookupFailed={lookupSystem.isError}
          onEquipmentCode={(code) => lookupEquipment.mutate(code)}
          onSampleCode={(code) => lookupSample.mutate(code)}
          onSystemCode={(code) => lookupSystem.mutate(code)}
          onRemoveEquipment={(key) => setForm((current) => ({ ...current, equipment: removeEquipmentSnapshot(current.equipment, key) }))}
          onChange={(patch) => setForm((current) => ({ ...current, ...patch }))}
        />
        {saveRecord.error ? <ErrorNotice error={saveRecord.error} fallback="无法保存配光曲线点检记录" /> : null}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="ghost" onClick={closeEditor}>
            取消
          </Button>
          <Button variant="primary" onClick={submitEditor} disabled={saveRecord.isPending}>
            保存点检记录
          </Button>
        </div>
      </Modal>

      <Modal title="配光曲线点检记录详情" size="wide" open={detail !== null} onClose={() => setDetail(null)}>
        {detail ? <InspectionRecordDetail record={detail} /> : null}
      </Modal>
    </PageShell>
  )
}

type RecordActionProps = {
  records: PhotometricCurveInspectionRecord[]
  canDelete: boolean
  onDetail: (record: PhotometricCurveInspectionRecord) => void
  onEdit: (record: PhotometricCurveInspectionRecord) => void
  onDelete: (record: PhotometricCurveInspectionRecord) => void
}

/**
 * The desktop table stays at the values an operator scans a list for, keyed by the
 * sample number and the system code the measurement was taken on. The full
 * measurement set, the attachments and the device snapshots live in the detail modal
 * and in the global used-equipment ledger, so the row set keeps fitting a laptop.
 */
export function InspectionRecordTable({ records, canDelete, onDetail, onEdit, onDelete }: RecordActionProps) {
  return (
    <DataTable>
      <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
        <tr>
          <th className="px-3 py-2 font-medium">ID</th>
          <th className="px-3 py-2 font-medium">样品编号</th>
          <th className="px-3 py-2 font-medium">系统编码</th>
          <th className="px-3 py-2 font-medium">平均角度</th>
          <th className="px-3 py-2 font-medium">探头</th>
          <th className="px-3 py-2 font-medium">测试距离(m)</th>
          <th className="px-3 py-2 font-medium">峰值光强(cd)</th>
          <th className="px-3 py-2 font-medium">光通量(lm)</th>
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
            <td className="px-3 py-3 text-sm font-medium text-slate-900">{record.system_code ?? '-'}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.average_angle}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{probeLabel(record.probe)}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.test_distance}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.peak_luminous_intensity}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.luminous_flux}</td>
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
    <div className="space-y-3 md:hidden" data-mobile-photometric-curve-records>
      {records.map((record) => (
        <article className="rounded-lg border border-emerald-900/10 bg-white p-4 shadow-sm" key={record.id}>
          <div className="min-w-0">
            <h3 className="truncate text-sm font-semibold text-slate-950">样品 {record.sample_no}</h3>
            <p className="mt-0.5 truncate text-xs text-slate-500" data-mobile-system-code>
              系统编码 {record.system_code ?? '-'}
            </p>
          </div>
          <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
            <CardEntry label="ID" value={record.id} />
            <CardEntry label="探头" value={probeLabel(record.probe)} />
            <CardEntry label="平均角度" value={record.average_angle} />
            <CardEntry label="测试距离" value={`${record.test_distance} m`} />
            <CardEntry label="峰值光强" value={`${record.peak_luminous_intensity} cd`} />
            <CardEntry label="光通量" value={`${record.luminous_flux} lm`} />
            <CardEntry label="附件" value={`${record.photos.length} 图 · ${record.files.length} 件`} />
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

function InspectionRecordActions({
  record,
  canDelete,
  onDetail,
  onEdit,
  onDelete,
}: {
  record: PhotometricCurveInspectionRecord
  canDelete: boolean
  onDetail: (record: PhotometricCurveInspectionRecord) => void
  onEdit: (record: PhotometricCurveInspectionRecord) => void
  onDelete: (record: PhotometricCurveInspectionRecord) => void
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

export function InspectionEquipmentTable({ rows }: { rows: PhotometricCurveEquipmentLedgerRow[] }) {
  return <EquipmentLedgerTable rows={rows} recordLabel="点检记录ID" />
}

export function InspectionEquipmentCards({ rows }: { rows: PhotometricCurveEquipmentLedgerRow[] }) {
  return <EquipmentLedgerCards rows={rows} recordLabel="点检记录ID" marker="data-mobile-photometric-curve-equipment" />
}

export function InspectionRecordDetail({ record }: { record: PhotometricCurveInspectionRecord }) {
  return (
    <div className="space-y-4">
      <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <DetailEntry label="样品编号" value={record.sample_no} />
        <DetailEntry label="系统编码" value={record.system_code ?? '-'} />
        <DetailEntry label="系统名称" value={record.system_name ?? '-'} />
        <DetailEntry label="探头" value={probeLabel(record.probe)} />
        <DetailEntry label="记录日期" value={record.recorded_at} />
        <DetailEntry label="操作人" value={record.operator_name ?? '-'} />
        <DetailEntry label="备注" value={record.remark ?? '-'} />
        {photometricCurveMeasurementFields.map((field) => (
          <DetailEntry key={field.name} label={field.unit ? `${field.label}（${field.unit}）` : field.label} value={String(record[field.name])} />
        ))}
        <DetailEntry label="平均角度（自动计算）" value={record.average_angle} />
      </dl>
      <EquipmentSnapshotTable devices={record.equipment} />
      <MediaGallery record={record} />
    </div>
  )
}

/**
 * Attachments are private: the bytes are fetched through the authenticated media
 * endpoints, never through a URL the browser could load on its own.
 */
function MediaGallery({ record }: { record: PhotometricCurveInspectionRecord }) {
  return (
    <div className="grid gap-4 sm:grid-cols-2" data-record-attachments>
      <Panel title={`照片（${record.photos.length}）`}>
        {record.photos.length === 0 ? (
          <p className="text-xs text-slate-500">暂无照片</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {record.photos.map((media) => (
              <MediaThumbnail key={media.id} recordId={record.id} media={media} />
            ))}
          </div>
        )}
      </Panel>
      <Panel title={`文件（${record.files.length}）`}>
        {record.files.length === 0 ? (
          <p className="text-xs text-slate-500">暂无文件</p>
        ) : (
          <ul className="space-y-2">
            {record.files.map((media) => (
              <li className="flex items-center justify-between gap-2" key={media.id}>
                <span className="min-w-0 truncate text-xs text-slate-700">
                  {media.file_name}
                  <span className="ml-1 text-slate-400">{formatBytes(media.size)}</span>
                </span>
                <MediaDownloadButton recordId={record.id} media={media} />
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </div>
  )
}

/**
 * Loads one photo through the authenticated endpoint and shows it from an object
 * URL. The URL is revoked when the thumbnail goes away, so switching records does
 * not leak a blob per photo for the lifetime of the tab.
 */
function MediaThumbnail({ recordId, media }: { recordId: number; media: PhotometricCurveMedia }) {
  const [source, setSource] = useState<string | null>(null)

  useEffect(() => {
    let objectUrl: string | null = null
    let cancelled = false

    api
      .get<Blob>(`${BASE}/${recordId}/media/${media.id}/view`, { responseType: 'blob' })
      .then((response) => {
        if (cancelled) {
          return
        }

        objectUrl = URL.createObjectURL(response.data)
        setSource(objectUrl)
      })
      .catch(() => setSource(null))

    return () => {
      cancelled = true

      if (objectUrl !== null) {
        URL.revokeObjectURL(objectUrl)
      }
    }
  }, [recordId, media.id])

  return (
    <figure className="w-24">
      {source === null ? (
        <div className="flex h-24 w-24 items-center justify-center rounded-md border border-emerald-900/10 bg-slate-50 text-xs text-slate-400">
          加载中
        </div>
      ) : (
        <img className="h-24 w-24 rounded-md border border-emerald-900/10 object-cover" src={source} alt={media.file_name} />
      )}
      <figcaption className="mt-1 truncate text-xs text-slate-500" title={media.file_name}>
        {media.file_name}
      </figcaption>
    </figure>
  )
}

function MediaDownloadButton({ recordId, media }: { recordId: number; media: PhotometricCurveMedia }) {
  const [downloading, setDownloading] = useState(false)

  async function download() {
    setDownloading(true)

    try {
      const response = await api.get<Blob>(`${BASE}/${recordId}/media/${media.id}/download`, { responseType: 'blob' })
      const objectUrl = URL.createObjectURL(response.data)
      const link = document.createElement('a')

      // The endpoint already names the attachment; naming it here as well keeps the
      // original file name when the browser saves from an object URL.
      link.href = objectUrl
      link.download = media.file_name
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(objectUrl)
    } finally {
      setDownloading(false)
    }
  }

  return (
    <Button variant="secondary" onClick={() => void download()} disabled={downloading}>
      <Download className="size-4" aria-hidden="true" />
      下载
    </Button>
  )
}

export function InspectionRecordFormFields({
  form,
  recordId,
  fieldErrors,
  equipmentLookupFailed,
  sampleLookupFailed,
  systemLookupFailed,
  onEquipmentCode,
  onSampleCode,
  onSystemCode,
  onRemoveEquipment,
  onChange,
}: {
  form: PhotometricCurveInspectionForm
  recordId: number | null
  fieldErrors: Record<string, string>
  equipmentLookupFailed: boolean
  sampleLookupFailed: boolean
  systemLookupFailed: boolean
  onEquipmentCode: (code: string) => void
  onSampleCode: (code: string) => void
  onSystemCode: (code: string) => void
  onRemoveEquipment: (key: string) => void
  onChange: (patch: Partial<PhotometricCurveInspectionForm>) => void
}) {
  const averageAngle = deriveAverageAngle(form)

  return (
    <div className="space-y-4">
      <EquipmentScannerBlock
        devices={form.equipment}
        lookupFailed={equipmentLookupFailed}
        error={fieldErrors.equipment}
        onCode={onEquipmentCode}
        onRemove={onRemoveEquipment}
      />

      <SampleScannerBlock
        sample={form.sample}
        lookupFailed={sampleLookupFailed}
        error={fieldErrors.sample}
        onCode={onSampleCode}
      />

      <SystemScannerBlock
        system={form.system}
        lookupFailed={systemLookupFailed}
        error={fieldErrors.system}
        onCode={onSystemCode}
      />

      <Panel title="测量值">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {photometricCurveMeasurementFields.map((field) => (
            <Field key={field.name} label={field.unit ? `${field.label}（${field.unit}）` : field.label}>
              <input
                className={inputClass}
                inputMode={field.scale === 0 ? 'numeric' : 'decimal'}
                value={form[field.name]}
                placeholder={field.scale === 0 ? '0' : `0.${'0'.repeat(field.scale)}`}
                onChange={(event) => onChange({ [field.name]: event.target.value } as Partial<PhotometricCurveInspectionForm>)}
                onBlur={(event) => {
                  const normalized = normalizeMeasurementInput(event.target.value, field.scale)

                  if (normalized !== null) {
                    onChange({ [field.name]: normalized } as Partial<PhotometricCurveInspectionForm>)
                  }
                }}
              />
              <FieldError message={fieldErrors[field.name]} />
            </Field>
          ))}
          {/*
            The average is derived from the four angles above and is never sent: it is
            shown read-only so the operator sees what will be stored without being able
            to let it drift away from the angles, which is what the paper form allowed.
          */}
          <Field label="平均角度（自动计算）">
            <input
              data-average-angle
              className={`${inputClass} bg-slate-100 text-slate-700`}
              value={averageAngle}
              readOnly
              aria-readonly="true"
              aria-label="平均角度（自动计算）"
            />
          </Field>
          <Field label="探头">
            <select
              className={inputClass}
              value={form.probe}
              onChange={(event) => onChange({ probe: event.target.value as PhotometricCurveProbe })}
            >
              {photometricCurveProbes.map((probe) => (
                <option key={probe.value} value={probe.value}>
                  {probe.label}
                </option>
              ))}
            </select>
            <FieldError message={fieldErrors.probe} />
          </Field>
          <Field label="备注" className="sm:col-span-2 lg:col-span-4">
            <textarea className={textareaClass} value={form.remark} onChange={(event) => onChange({ remark: event.target.value })} />
          </Field>
        </div>
      </Panel>

      <div className="grid gap-4 sm:grid-cols-2" data-inspection-attachments>
        <AttachmentPicker
          title="照片"
          collection="photos"
          recordId={recordId}
          form={form}
          error={fieldErrors.photos}
          onChange={onChange}
        />
        <AttachmentPicker
          title="文件"
          collection="files"
          recordId={recordId}
          form={form}
          error={fieldErrors.files}
          onChange={onChange}
        />
      </div>
    </div>
  )
}

/**
 * One attachment collection of the editor: the media the record already carries,
 * which stay unless the operator removes them, plus the files picked in this session.
 */
function AttachmentPicker({
  title,
  collection,
  recordId,
  form,
  error,
  onChange,
}: {
  title: string
  collection: 'photos' | 'files'
  recordId: number | null
  form: PhotometricCurveInspectionForm
  error?: string
  onChange: (patch: Partial<PhotometricCurveInspectionForm>) => void
}) {
  const limits = photometricCurveMediaLimits[collection]
  const retained = form.retained_media.filter((media) => media.collection === collection)
  const picked = collection === 'photos' ? form.new_photos : form.new_files

  function setPicked(files: File[]) {
    onChange(collection === 'photos' ? { new_photos: files } : { new_files: files })
  }

  return (
    <Panel title={`${title}（${retained.length + picked.length}/${limits.maxItems}）`}>
      <input
        className={inputClass}
        type="file"
        multiple
        accept={limits.accept}
        aria-label={`选择${title}`}
        onChange={(event) => {
          setPicked([...picked, ...Array.from(event.target.files ?? [])])
          event.target.value = ''
        }}
      />
      {retained.length > 0 ? (
        <ul className="mt-3 space-y-1" data-retained-media>
          {retained.map((media) => (
            <li className="flex items-center justify-between gap-2 text-xs" key={media.id}>
              <span className="min-w-0 truncate text-slate-700">
                {recordId !== null ? `#${media.id} · ` : ''}
                {media.file_name}
                <span className="ml-1 text-slate-400">{formatBytes(media.size)}</span>
              </span>
              <button
                type="button"
                className="text-slate-500 hover:text-red-600"
                aria-label={`移除 ${media.file_name}`}
                onClick={() =>
                  onChange({ retained_media: form.retained_media.filter((entry) => entry.id !== media.id) })
                }
              >
                <X className="size-3" aria-hidden="true" />
              </button>
            </li>
          ))}
        </ul>
      ) : null}
      {picked.length > 0 ? (
        <ul className="mt-2 space-y-1" data-new-media>
          {picked.map((file, index) => (
            <li className="flex items-center justify-between gap-2 text-xs" key={`${file.name}-${index}`}>
              <span className="min-w-0 truncate text-emerald-800">
                {file.name}
                <span className="ml-1 text-slate-400">{formatBytes(file.size)}</span>
              </span>
              <button
                type="button"
                className="text-slate-500 hover:text-red-600"
                aria-label={`移除 ${file.name}`}
                onClick={() => setPicked(picked.filter((_, position) => position !== index))}
              >
                <X className="size-3" aria-hidden="true" />
              </button>
            </li>
          ))}
        </ul>
      ) : null}
      {retained.length + picked.length === 0 ? <p className="mt-3 text-xs text-slate-500">尚未选择{title}</p> : null}
      <FieldError message={error} />
    </Panel>
  )
}
