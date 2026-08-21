import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit3, Eye, Plus, Search, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { useEffectivePermissions } from '../auth/useCurrentUser'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, PaginationControls, Panel } from '../system/shared'
import { type ApiCollection, type ApiResource, inputClass, textareaClass } from '../system/utils'
import { AttachmentPicker, MediaGallery } from './InspectionMediaComponents'
import {
  CardEntry,
  DetailEntry,
  EquipmentLedgerCards,
  EquipmentLedgerTable,
  EquipmentScannerBlock,
  EquipmentSnapshotTable,
  FieldError,
  StandardScannerBlock,
  SystemScannerBlock,
} from './InspectionSharedFields'
import { addEquipmentSnapshot, removeEquipmentSnapshot, selectedStandard, selectedSystem } from './inspectionShared'
import {
  photometricCurveCalibrationEquipmentQueryKey,
  photometricCurveCalibrationMutationHandlers,
  photometricCurveCalibrationRecordsQueryKey,
} from './photometricCurveCalibrationQueries'
import {
  buildPhotometricCurveCalibrationEquipmentListParams,
  buildPhotometricCurveCalibrationListParams,
  buildPhotometricCurveCalibrationPayload,
  calibrationFormFromRecord,
  emptyPhotometricCurveCalibrationEquipmentFilters,
  emptyPhotometricCurveCalibrationFilters,
  emptyPhotometricCurveCalibrationForm,
  photometricCurveCalibrationFieldErrors,
  photometricCurveCalibrationMeasurementFields,
  photometricCurveProbes,
  probeLabel,
  type PhotometricCurveCalibrationEquipmentFilters,
  type PhotometricCurveCalibrationEquipmentLedgerRow,
  type PhotometricCurveCalibrationEquipmentOption,
  type PhotometricCurveCalibrationFilters,
  type PhotometricCurveCalibrationForm,
  type PhotometricCurveCalibrationRecord,
  type PhotometricCurveCalibrationSystemOption,
  type PhotometricCurveCalibrationView,
  type PhotometricCurveProbe,
} from './photometricCurveCalibrationSchema'

const RESOURCE = 'photometric_curve_calibration_records'
const BASE = '/api/photometric-curve-calibration-records'

export function PhotometricCurveCalibrationPage() {
  const queryClient = useQueryClient()
  const permissions = useEffectivePermissions()
  const [view, setView] = useState<PhotometricCurveCalibrationView>('records')
  const [filters, setFilters] = useState<PhotometricCurveCalibrationFilters>(emptyPhotometricCurveCalibrationFilters)
  const [equipmentFilters, setEquipmentFilters] = useState<PhotometricCurveCalibrationEquipmentFilters>(
    emptyPhotometricCurveCalibrationEquipmentFilters,
  )
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [equipmentPage, setEquipmentPage] = useState(1)
  const [equipmentPerPage, setEquipmentPerPage] = useState(15)
  const [editorOpen, setEditorOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState<PhotometricCurveCalibrationForm>(() => emptyPhotometricCurveCalibrationForm())
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [detail, setDetail] = useState<PhotometricCurveCalibrationRecord | null>(null)

  const canDelete = Boolean(permissions.data?.resources[RESOURCE]?.actions.delete)

  const recordsQuery = useQuery({
    queryKey: [...photometricCurveCalibrationRecordsQueryKey, filters, page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<PhotometricCurveCalibrationRecord>>(BASE, {
        params: buildPhotometricCurveCalibrationListParams(filters, page, perPage),
      })

      return response.data
    },
  })

  const equipmentLedgerQuery = useQuery({
    queryKey: [...photometricCurveCalibrationEquipmentQueryKey, equipmentFilters, equipmentPage, equipmentPerPage],
    enabled: view === 'equipment',
    queryFn: async () => {
      const response = await api.get<ApiCollection<PhotometricCurveCalibrationEquipmentLedgerRow>>(`${BASE}/equipment`, {
        params: buildPhotometricCurveCalibrationEquipmentListParams(equipmentFilters, equipmentPage, equipmentPerPage),
      })

      return response.data
    },
  })

  const lookupEquipment = useMutation({
    mutationFn: async (code: string) => {
      const response = await api.get<ApiResource<PhotometricCurveCalibrationEquipmentOption>>(`${BASE}/lookup`, {
        params: { type: 'equipment', code },
      })

      return response.data.data
    },
    onSuccess: (device) => {
      setForm((current) => ({ ...current, equipment: addEquipmentSnapshot(current.equipment, device) }))
      setFieldErrors((current) => ({ ...current, equipment: '' }))
    },
  })

  const lookupStandard = useMutation({
    mutationFn: async (code: string) => {
      const response = await api.get<ApiResource<PhotometricCurveCalibrationEquipmentOption>>(`${BASE}/lookup`, {
        params: { type: 'standard', code },
      })

      return response.data.data
    },
    onSuccess: (standard) => {
      setForm((current) => ({ ...current, standard: selectedStandard(standard) }))
      setFieldErrors((current) => ({ ...current, standard: '' }))
    },
  })

  const lookupSystem = useMutation({
    mutationFn: async (code: string) => {
      const response = await api.get<ApiResource<PhotometricCurveCalibrationSystemOption>>(`${BASE}/lookup`, {
        params: { type: 'system', code },
      })

      return response.data.data
    },
    onSuccess: (system) => {
      setForm((current) => ({ ...current, system: selectedSystem(system) }))
      setFieldErrors((current) => ({ ...current, system: '' }))
    },
  })

  const mutationHandlers = photometricCurveCalibrationMutationHandlers(queryClient, closeEditor)

  const saveRecord = useMutation({
    mutationFn: async () => {
      if (editingId === null) {
        await api.post(BASE, buildPhotometricCurveCalibrationPayload(form, 'create'))

        return
      }

      await api.post(`${BASE}/${editingId}`, buildPhotometricCurveCalibrationPayload(form, 'update'))
    },
    onError: (error) => setFieldErrors(photometricCurveCalibrationFieldErrors(error)),
    onSuccess: mutationHandlers.saveSuccess,
  })

  const deleteRecord = useMutation({
    mutationFn: async (record: PhotometricCurveCalibrationRecord) => {
      await api.delete(`${BASE}/${record.id}`)
    },
    onSuccess: mutationHandlers.deleteSuccess,
  })

  const records = recordsQuery.data?.data ?? []
  const equipmentRows = equipmentLedgerQuery.data?.data ?? []

  function openCreate() {
    setEditingId(null)
    setForm(emptyPhotometricCurveCalibrationForm())
    setFieldErrors({})
    saveRecord.reset()
    lookupEquipment.reset()
    lookupStandard.reset()
    lookupSystem.reset()
    setEditorOpen(true)
  }

  function openEdit(record: PhotometricCurveCalibrationRecord) {
    setEditingId(record.id)
    setForm(calibrationFormFromRecord(record))
    setFieldErrors({})
    saveRecord.reset()
    lookupEquipment.reset()
    lookupStandard.reset()
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
      buildPhotometricCurveCalibrationPayload(form, editingId === null ? 'create' : 'update')
    } catch (error) {
      setFieldErrors(photometricCurveCalibrationFieldErrors(error))

      return
    }

    saveRecord.mutate()
  }

  return (
    <PageShell
      title="配光曲线定标记录"
      description="记录配光曲线定标过程中的使用设备、系统编码、标准件编号、探头与测试距离、定标系数、光电参数及附件。"
      actions={
        <PermissionGate resource={RESOURCE} action="create">
          <Button variant="primary" onClick={openCreate}>
            <Plus className="size-4" aria-hidden="true" />
            新增定标记录
          </Button>
        </PermissionGate>
      }
    >
      <div className="flex flex-wrap gap-2" role="tablist" data-photometric-curve-calibration-views>
        <Button
          role="tab"
          aria-selected={view === 'records'}
          variant={view === 'records' ? 'primary' : 'secondary'}
          onClick={() => setView('records')}
        >
          定标记录总表
        </Button>
        <Button
          role="tab"
          aria-selected={view === 'equipment'}
          variant={view === 'equipment' ? 'primary' : 'secondary'}
          onClick={() => setView('equipment')}
        >
          使用设备总表
        </Button>
      </div>

      {view === 'records' ? (
        <>
          <Panel title="Filters">
            <div className="grid gap-3 md:grid-cols-5">
              <Field label="搜索">
                <div className="relative">
                  <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
                  <input
                    className={`${inputClass} pl-9`}
                    value={filters.search}
                    onChange={(event) => {
                      setFilters({ ...filters, search: event.target.value })
                      setPage(1)
                    }}
                    placeholder="标准件/系统/设备编号或名称"
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
                  <option value="">全部探头</option>
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
                    setFilters(emptyPhotometricCurveCalibrationFilters)
                    setPage(1)
                  }}
                >
                  重置
                </Button>
              </div>
            </div>
          </Panel>

          {recordsQuery.isError ? <ErrorNotice error={recordsQuery.error} fallback="无法加载配光曲线定标记录" /> : null}
          {deleteRecord.error ? <ErrorNotice error={deleteRecord.error} fallback="无法删除配光曲线定标记录" /> : null}
          {recordsQuery.isPending ? <LoadingState label="正在加载配光曲线定标记录" /> : null}
          {!recordsQuery.isPending && records.length === 0 ? (
            <EmptyState
              title="暂无配光曲线定标记录"
              description="新增定标记录后会显示标准件编号、系统编码、探头、测试距离、定标系数、测量值、记录日期和操作人。"
            />
          ) : null}

          {records.length > 0 ? (
            <>
              <CalibrationRecordTable
                records={records}
                canDelete={canDelete}
                onDetail={setDetail}
                onEdit={openEdit}
                onDelete={(record) => deleteRecord.mutate(record)}
              />
              <CalibrationRecordCards
                records={records}
                canDelete={canDelete}
                onDetail={setDetail}
                onEdit={openEdit}
                onDelete={(record) => deleteRecord.mutate(record)}
              />
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
              <Field label="定标记录ID">
                <input
                  className={inputClass}
                  inputMode="numeric"
                  value={equipmentFilters.calibration_record_id}
                  onChange={(event) => {
                    setEquipmentFilters({ ...equipmentFilters, calibration_record_id: event.target.value })
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
                    setEquipmentFilters(emptyPhotometricCurveCalibrationEquipmentFilters)
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
            <EmptyState title="暂无使用设备记录" description="新增定标记录并录入设备后，这里会显示每条记录与设备的关联。" />
          ) : null}

          {equipmentRows.length > 0 ? (
            <>
              <EquipmentLedgerTable rows={equipmentRows} recordLabel="定标记录ID" />
              <EquipmentLedgerCards
                rows={equipmentRows}
                recordLabel="定标记录ID"
                marker="data-mobile-photometric-curve-calibration-equipment"
              />
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

      <Modal
        title={editingId === null ? '新增配光曲线定标记录' : '编辑配光曲线定标记录'}
        size="wide"
        open={editorOpen}
        onClose={closeEditor}
        footer={
          <>
            <Button variant="ghost" onClick={closeEditor}>
              取消
            </Button>
            <Button variant="primary" onClick={submitEditor} disabled={saveRecord.isPending}>
              保存定标记录
            </Button>
          </>
        }
      >
        <CalibrationRecordFormFields
          form={form}
          recordId={editingId}
          fieldErrors={fieldErrors}
          equipmentLookupFailed={lookupEquipment.isError}
          standardLookupFailed={lookupStandard.isError}
          systemLookupFailed={lookupSystem.isError}
          onEquipmentCode={(code) => lookupEquipment.mutate(code)}
          onStandardCode={(code) => lookupStandard.mutate(code)}
          onSystemCode={(code) => lookupSystem.mutate(code)}
          onRemoveEquipment={(key) => setForm((current) => ({ ...current, equipment: removeEquipmentSnapshot(current.equipment, key) }))}
          onChange={(patch) => setForm((current) => ({ ...current, ...patch }))}
        />
        {saveRecord.error ? <ErrorNotice error={saveRecord.error} fallback="无法保存配光曲线定标记录" /> : null}
      </Modal>

      <Modal title="配光曲线定标记录详情" size="wide" open={detail !== null} onClose={() => setDetail(null)}>
        {detail ? <CalibrationRecordDetail record={detail} /> : null}
      </Modal>
    </PageShell>
  )
}

type RecordActionProps = {
  records: PhotometricCurveCalibrationRecord[]
  canDelete: boolean
  onDetail: (record: PhotometricCurveCalibrationRecord) => void
  onEdit: (record: PhotometricCurveCalibrationRecord) => void
  onDelete: (record: PhotometricCurveCalibrationRecord) => void
}

export function CalibrationRecordTable({ records, canDelete, onDetail, onEdit, onDelete }: RecordActionProps) {
  return (
    <DataTable>
      <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
        <tr>
          <th className="px-3 py-2 font-medium">ID</th>
          <th className="px-3 py-2 font-medium">标准件编号</th>
          <th className="px-3 py-2 font-medium">系统编码</th>
          <th className="px-3 py-2 font-medium">探头</th>
          <th className="px-3 py-2 font-medium">测试距离(m)</th>
          <th className="px-3 py-2 font-medium">定标系数</th>
          <th className="px-3 py-2 font-medium">峰值光强(cd)</th>
          <th className="px-3 py-2 font-medium">光通量(lm)</th>
          <th className="px-3 py-2 font-medium">日期</th>
          <th className="px-3 py-2 font-medium">操作人</th>
          <th className="px-3 py-2 font-medium">操作</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-slate-200">
        {records.map((record) => (
          <tr className="align-top" key={record.id}>
            <td className="px-3 py-3 text-sm font-medium text-slate-900">{record.id}</td>
            <td className="px-3 py-3 text-sm font-medium text-slate-900">{record.standard_no}</td>
            <td className="px-3 py-3 text-sm font-medium text-slate-900">{record.system_code}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{probeLabel(record.probe)}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.test_distance}</td>
            <td className="px-3 py-3 text-sm text-slate-700" data-record-calibration-coefficient>
              {record.calibration_coefficient}
            </td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.peak_luminous_intensity}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.luminous_flux}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.recorded_at}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.operator_name ?? '-'}</td>
            <td className="px-3 py-3">
              <CalibrationRecordActions record={record} canDelete={canDelete} onDetail={onDetail} onEdit={onEdit} onDelete={onDelete} />
            </td>
          </tr>
        ))}
      </tbody>
    </DataTable>
  )
}

export function CalibrationRecordCards({ records, canDelete, onDetail, onEdit, onDelete }: RecordActionProps) {
  return (
    <div className="space-y-3 md:hidden" data-mobile-photometric-curve-calibration-records>
      {records.map((record) => (
        <article className="rounded-lg border border-emerald-900/10 bg-white p-4 shadow-sm" key={record.id}>
          <div className="min-w-0">
            <h3 className="truncate text-sm font-semibold text-slate-950">标准件 {record.standard_no}</h3>
            <p className="mt-0.5 truncate text-xs text-slate-500" data-mobile-system-code>
              系统编码 {record.system_code}
            </p>
          </div>
          <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
            <CardEntry label="ID" value={record.id} />
            <CardEntry label="探头" value={probeLabel(record.probe)} />
            <CardEntry label="测试距离" value={`${record.test_distance} m`} />
            <CardEntry label="定标系数" value={record.calibration_coefficient} />
            <CardEntry label="峰值光强" value={`${record.peak_luminous_intensity} cd`} />
            <CardEntry label="光通量" value={`${record.luminous_flux} lm`} />
            <CardEntry label="电压/电流" value={`${record.voltage} V / ${record.current} A`} />
            <CardEntry label="功率/因数" value={`${record.power} W / ${record.power_factor}`} />
            <CardEntry label="频率" value={`${record.frequency} Hz`} />
            <CardEntry label="附件" value={`${record.photos.length} 图 · ${record.files.length} 件`} />
            <CardEntry label="记录日期" value={record.recorded_at} />
            <CardEntry label="操作人" value={record.operator_name ?? '-'} />
          </dl>
          <div className="mt-3">
            <CalibrationRecordActions record={record} canDelete={canDelete} onDetail={onDetail} onEdit={onEdit} onDelete={onDelete} />
          </div>
        </article>
      ))}
    </div>
  )
}

function CalibrationRecordActions({
  record,
  canDelete,
  onDetail,
  onEdit,
  onDelete,
}: {
  record: PhotometricCurveCalibrationRecord
  canDelete: boolean
  onDetail: (record: PhotometricCurveCalibrationRecord) => void
  onEdit: (record: PhotometricCurveCalibrationRecord) => void
  onDelete: (record: PhotometricCurveCalibrationRecord) => void
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

export function CalibrationRecordDetail({ record }: { record: PhotometricCurveCalibrationRecord }) {
  return (
    <div className="space-y-4">
      <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <DetailEntry label="标准件编号" value={record.standard_no} />
        <DetailEntry label="标准件名称" value={record.standard_name} />
        <DetailEntry label="标准件厂家" value={record.standard_manufacturer ?? '-'} />
        <DetailEntry label="标准件型号" value={record.standard_model ?? '-'} />
        <DetailEntry label="标准件出厂编号" value={record.standard_serial_no ?? '-'} />
        <DetailEntry label="标准件下次校准" value={record.standard_next_calibration_date ?? '-'} />
        <DetailEntry label="系统编码" value={record.system_code} />
        <DetailEntry label="系统名称" value={record.system_name ?? '-'} />
        <DetailEntry label="探头" value={probeLabel(record.probe)} />
        <DetailEntry label="测试距离" value={`${record.test_distance} m`} />
        <DetailEntry label="定标系数" value={record.calibration_coefficient} />
        <DetailEntry label="峰值光强" value={`${record.peak_luminous_intensity} cd`} />
        <DetailEntry label="光通量" value={`${record.luminous_flux} lm`} />
        <DetailEntry label="电压" value={`${record.voltage} V`} />
        <DetailEntry label="电流" value={`${record.current} A`} />
        <DetailEntry label="功率" value={`${record.power} W`} />
        <DetailEntry label="功率因数" value={record.power_factor} />
        <DetailEntry label="频率" value={`${record.frequency} Hz`} />
        <DetailEntry label="记录日期" value={record.recorded_at} />
        <DetailEntry label="操作人" value={record.operator_name ?? '-'} />
        <DetailEntry label="备注" value={record.remark ?? '-'} />
      </dl>
      <EquipmentSnapshotTable devices={record.equipment} />
      <MediaGallery baseUrl={BASE} recordId={record.id} photos={record.photos} files={record.files} />
    </div>
  )
}

export function CalibrationRecordFormFields({
  form,
  recordId,
  fieldErrors,
  equipmentLookupFailed,
  standardLookupFailed,
  systemLookupFailed,
  onEquipmentCode,
  onStandardCode,
  onSystemCode,
  onRemoveEquipment,
  onChange,
}: {
  form: PhotometricCurveCalibrationForm
  recordId: number | null
  fieldErrors: Record<string, string>
  equipmentLookupFailed: boolean
  standardLookupFailed: boolean
  systemLookupFailed: boolean
  onEquipmentCode: (code: string) => void
  onStandardCode: (code: string) => void
  onSystemCode: (code: string) => void
  onRemoveEquipment: (key: string) => void
  onChange: (patch: Partial<PhotometricCurveCalibrationForm>) => void
}) {
  return (
    // Scan entry on the left, probe/measurements/attachments on the right: the split
    // keeps the whole editor inside one laptop screen.
    <div className="grid gap-4 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:items-start">
      <div className="space-y-4">
        {/* Order: equipment -> system -> standard */}
        <EquipmentScannerBlock
          devices={form.equipment}
          lookupFailed={equipmentLookupFailed}
          error={fieldErrors.equipment}
          onCode={onEquipmentCode}
          onRemove={onRemoveEquipment}
        />

        <SystemScannerBlock system={form.system} lookupFailed={systemLookupFailed} error={fieldErrors.system} onCode={onSystemCode} />

        <StandardScannerBlock
          standard={form.standard}
          lookupFailed={standardLookupFailed}
          error={fieldErrors.standard}
          onCode={onStandardCode}
        />
      </div>

      <div className="space-y-4">
        <Panel title="探头与测量值">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
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

            {photometricCurveCalibrationMeasurementFields.map((field) => (
              <Field key={field.name} label={field.unit ? `${field.label}（${field.unit}）` : field.label}>
                <input
                  className={inputClass}
                  inputMode={field.scale === 0 ? 'numeric' : 'decimal'}
                  value={form[field.name]}
                  placeholder={field.scale === 0 ? '0' : `0.${'0'.repeat(field.scale)}`}
                  onChange={(event) => onChange({ [field.name]: event.target.value } as Partial<PhotometricCurveCalibrationForm>)}
                />
                <FieldError message={fieldErrors[field.name]} />
              </Field>
            ))}

            <Field label="备注" className="sm:col-span-2 lg:col-span-3">
              <textarea className={textareaClass} value={form.remark} onChange={(event) => onChange({ remark: event.target.value })} />
              <FieldError message={fieldErrors.remark} />
            </Field>
          </div>
        </Panel>

        <div className="grid gap-4 sm:grid-cols-2" data-inspection-attachments>
          <AttachmentPicker title="照片" collection="photos" recordId={recordId} baseUrl={BASE} form={form} error={fieldErrors.photos} onChange={onChange} />
          <AttachmentPicker title="文件" collection="files" recordId={recordId} baseUrl={BASE} form={form} error={fieldErrors.files} onChange={onChange} />
        </div>
      </div>
    </div>
  )
}
