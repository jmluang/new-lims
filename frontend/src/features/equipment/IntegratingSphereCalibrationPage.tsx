import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit3, Eye, Plus, Search, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { useEffectivePermissions } from '../auth/useCurrentUser'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, PaginationControls, Panel } from '../system/shared'
import { type ApiCollection, type ApiResource, inputClass, textareaClass } from '../system/utils'
import {
  AttachmentPicker,
  FieldError,
  MediaGallery,
} from './InspectionMediaComponents'
import {
  CardEntry,
  DetailEntry,
  EquipmentLedgerCards,
  EquipmentLedgerTable,
  EquipmentScannerBlock,
  EquipmentSnapshotTable,
  StandardScannerBlock,
  SystemScannerBlock,
} from './InspectionSharedFields'
import {
  addEquipmentSnapshot,
  removeEquipmentSnapshot,
  selectedStandard,
  selectedSystem,
} from './inspectionShared'
import {
  integratingSphereCalibrationEquipmentQueryKey,
  integratingSphereCalibrationFormOptionsQueryKey,
  integratingSphereCalibrationMutationHandlers,
  integratingSphereCalibrationRecordsQueryKey,
} from './integratingSphereCalibrationQueries'
import {
  buildIntegratingSphereCalibrationEquipmentListParams,
  buildIntegratingSphereCalibrationListParams,
  buildIntegratingSphereCalibrationPayload,
  calibrationFormFromRecord,
  emptyIntegratingSphereCalibrationEquipmentFilters,
  emptyIntegratingSphereCalibrationFilters,
  emptyIntegratingSphereCalibrationForm,
  integratingSphereCalibrationFieldErrors,
  integratingSphereCalibrationMeasurementFields,
  type CatalogFormOptions,
  type IntegratingSphereCalibrationEquipmentFilters,
  type IntegratingSphereCalibrationEquipmentLedgerRow,
  type IntegratingSphereCalibrationEquipmentOption,
  type IntegratingSphereCalibrationFilters,
  type IntegratingSphereCalibrationForm,
  type IntegratingSphereCalibrationRecord,
  type IntegratingSphereCalibrationSystemOption,
  type IntegratingSphereCalibrationView,
} from './integratingSphereCalibrationSchema'

const RESOURCE = 'integrating_sphere_calibration_records'
const BASE = '/api/integrating-sphere-calibration-records'

export function IntegratingSphereCalibrationPage() {
  const queryClient = useQueryClient()
  const permissions = useEffectivePermissions()
  const [view, setView] = useState<IntegratingSphereCalibrationView>('records')
  const [filters, setFilters] = useState<IntegratingSphereCalibrationFilters>(emptyIntegratingSphereCalibrationFilters)
  const [equipmentFilters, setEquipmentFilters] = useState<IntegratingSphereCalibrationEquipmentFilters>(emptyIntegratingSphereCalibrationEquipmentFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [equipmentPage, setEquipmentPage] = useState(1)
  const [equipmentPerPage, setEquipmentPerPage] = useState(15)
  const [editorOpen, setEditorOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState<IntegratingSphereCalibrationForm>(() => emptyIntegratingSphereCalibrationForm())
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [detail, setDetail] = useState<IntegratingSphereCalibrationRecord | null>(null)

  const canDelete = Boolean(permissions.data?.resources[RESOURCE]?.actions.delete)

  const formOptionsQuery = useQuery({
    queryKey: integratingSphereCalibrationFormOptionsQueryKey,
    queryFn: async () => {
      const response = await api.get<ApiResource<CatalogFormOptions>>(`${BASE}/form-options`)

      return response.data.data
    },
  })

  const recordsQuery = useQuery({
    queryKey: [...integratingSphereCalibrationRecordsQueryKey, filters, page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<IntegratingSphereCalibrationRecord>>(BASE, {
        params: buildIntegratingSphereCalibrationListParams(filters, page, perPage),
      })

      return response.data
    },
  })

  const equipmentLedgerQuery = useQuery({
    queryKey: [...integratingSphereCalibrationEquipmentQueryKey, equipmentFilters, equipmentPage, equipmentPerPage],
    enabled: view === 'equipment',
    queryFn: async () => {
      const response = await api.get<ApiCollection<IntegratingSphereCalibrationEquipmentLedgerRow>>(`${BASE}/equipment`, {
        params: buildIntegratingSphereCalibrationEquipmentListParams(equipmentFilters, equipmentPage, equipmentPerPage),
      })

      return response.data
    },
  })

  const lookupEquipment = useMutation({
    mutationFn: async (code: string) => {
      const response = await api.get<ApiResource<IntegratingSphereCalibrationEquipmentOption>>(`${BASE}/lookup`, {
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
      const response = await api.get<ApiResource<IntegratingSphereCalibrationEquipmentOption>>(`${BASE}/lookup`, {
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
      const response = await api.get<ApiResource<IntegratingSphereCalibrationSystemOption>>(`${BASE}/lookup`, {
        params: { type: 'system', code },
      })

      return response.data.data
    },
    onSuccess: (system) => {
      setForm((current) => ({ ...current, system: selectedSystem(system) }))
      setFieldErrors((current) => ({ ...current, system: '' }))
    },
  })

  const mutationHandlers = integratingSphereCalibrationMutationHandlers(queryClient, closeEditor)

  const saveRecord = useMutation({
    mutationFn: async () => {
      if (editingId === null) {
        await api.post(BASE, buildIntegratingSphereCalibrationPayload(form, 'create'))

        return
      }

      await api.post(`${BASE}/${editingId}`, buildIntegratingSphereCalibrationPayload(form, 'update'))
    },
    onError: (error) => setFieldErrors(integratingSphereCalibrationFieldErrors(error)),
    onSuccess: mutationHandlers.saveSuccess,
  })

  const deleteRecord = useMutation({
    mutationFn: async (record: IntegratingSphereCalibrationRecord) => {
      await api.delete(`${BASE}/${record.id}`)
    },
    onSuccess: mutationHandlers.deleteSuccess,
  })

  const records = recordsQuery.data?.data ?? []
  const equipmentRows = equipmentLedgerQuery.data?.data ?? []
  const catalogOptions = formOptionsQuery.data ?? { modes: [], sensitivities: [] }

  function openCreate() {
    if (formOptionsQuery.isPending || formOptionsQuery.isError) {
      return
    }

    setEditingId(null)
    const initialForm = emptyIntegratingSphereCalibrationForm()

    if (catalogOptions.modes.length > 0) {
      initialForm.mode = { source: 'selected', code: catalogOptions.modes[0].code, label: catalogOptions.modes[0].label }
    }
    if (catalogOptions.sensitivities.length > 0) {
      initialForm.sensitivity = { source: 'selected', code: catalogOptions.sensitivities[0].code, label: catalogOptions.sensitivities[0].label }
    }

    setForm(initialForm)
    setFieldErrors({})
    saveRecord.reset()
    lookupEquipment.reset()
    lookupStandard.reset()
    lookupSystem.reset()
    setEditorOpen(true)
  }

  function openEdit(record: IntegratingSphereCalibrationRecord) {
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
      buildIntegratingSphereCalibrationPayload(form, editingId === null ? 'create' : 'update')
    } catch (error) {
      setFieldErrors(integratingSphereCalibrationFieldErrors(error))

      return
    }

    saveRecord.mutate()
  }

  return (
    <PageShell
      title="积分球定标记录"
      description="记录积分球定标过程中的使用设备、系统编码、标准件编号、模式与灵敏度、光电参数及附件。"
      actions={
        <PermissionGate resource={RESOURCE} action="create">
          <Button
            variant="primary"
            onClick={openCreate}
            disabled={formOptionsQuery.isPending || formOptionsQuery.isError}
          >
            <Plus className="size-4" aria-hidden="true" />
            新增定标记录
          </Button>
        </PermissionGate>
      }
    >
      <div className="flex flex-wrap gap-2" role="tablist" data-integrating-sphere-calibration-views>
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

      {formOptionsQuery.isError ? (
        <ErrorNotice error={formOptionsQuery.error} fallback="无法加载定标模式及灵敏度目录，请刷新重试" />
      ) : null}

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
                    placeholder="标准件/系统/设备/模式/灵敏度/ID"
                  />
                </div>
              </Field>
              <Field label="模式">
                <select
                  className={inputClass}
                  value={filters.mode_code}
                  onChange={(event) => {
                    setFilters({ ...filters, mode_code: event.target.value })
                    setPage(1)
                  }}
                >
                  <option value="">全部模式</option>
                  {catalogOptions.modes.map((mode) => (
                    <option key={mode.code} value={mode.code}>
                      {mode.label}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="灵敏度">
                <select
                  className={inputClass}
                  value={filters.sensitivity_code}
                  onChange={(event) => {
                    setFilters({ ...filters, sensitivity_code: event.target.value })
                    setPage(1)
                  }}
                >
                  <option value="">全部灵敏度</option>
                  {catalogOptions.sensitivities.map((sensitivity) => (
                    <option key={sensitivity.code} value={sensitivity.code}>
                      {sensitivity.label}
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
                    setFilters(emptyIntegratingSphereCalibrationFilters)
                    setPage(1)
                  }}
                >
                  重置
                </Button>
              </div>
            </div>
          </Panel>

          {recordsQuery.isError ? <ErrorNotice error={recordsQuery.error} fallback="无法加载积分球定标记录" /> : null}
          {deleteRecord.error ? <ErrorNotice error={deleteRecord.error} fallback="无法删除积分球定标记录" /> : null}
          {recordsQuery.isPending ? <LoadingState label="正在加载积分球定标记录" /> : null}
          {!recordsQuery.isPending && records.length === 0 ? (
            <EmptyState title="暂无积分球定标记录" description="新增定标记录后会显示标准件编号、系统编码、模式、灵敏度、测量值、记录日期和操作人。" />
          ) : null}

          {records.length > 0 ? (
            <>
              <CalibrationRecordTable records={records} canDelete={canDelete} onDetail={setDetail} onEdit={openEdit} onDelete={(record) => deleteRecord.mutate(record)} />
              <CalibrationRecordCards records={records} canDelete={canDelete} onDetail={setDetail} onEdit={openEdit} onDelete={(record) => deleteRecord.mutate(record)} />
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
                    setEquipmentFilters(emptyIntegratingSphereCalibrationEquipmentFilters)
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
              <EquipmentLedgerCards rows={equipmentRows} recordLabel="定标记录ID" marker="data-mobile-integrating-sphere-calibration-equipment" />
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

      <Modal title={editingId === null ? '新增积分球定标记录' : '编辑积分球定标记录'} size="wide" open={editorOpen} onClose={closeEditor}>
        <CalibrationRecordFormFields
          form={form}
          recordId={editingId}
          fieldErrors={fieldErrors}
          catalogOptions={catalogOptions}
          equipmentLookupFailed={lookupEquipment.isError}
          standardLookupFailed={lookupStandard.isError}
          systemLookupFailed={lookupSystem.isError}
          onEquipmentCode={(code) => lookupEquipment.mutate(code)}
          onStandardCode={(code) => lookupStandard.mutate(code)}
          onSystemCode={(code) => lookupSystem.mutate(code)}
          onRemoveEquipment={(key) => setForm((current) => ({ ...current, equipment: removeEquipmentSnapshot(current.equipment, key) }))}
          onChange={(patch) => setForm((current) => ({ ...current, ...patch }))}
        />
        {saveRecord.error ? <ErrorNotice error={saveRecord.error} fallback="无法保存积分球定标记录" /> : null}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="ghost" onClick={closeEditor}>
            取消
          </Button>
          <Button variant="primary" onClick={submitEditor} disabled={saveRecord.isPending}>
            保存定标记录
          </Button>
        </div>
      </Modal>

      <Modal title="积分球定标记录详情" size="wide" open={detail !== null} onClose={() => setDetail(null)}>
        {detail ? <CalibrationRecordDetail record={detail} /> : null}
      </Modal>
    </PageShell>
  )
}

type RecordActionProps = {
  records: IntegratingSphereCalibrationRecord[]
  canDelete: boolean
  onDetail: (record: IntegratingSphereCalibrationRecord) => void
  onEdit: (record: IntegratingSphereCalibrationRecord) => void
  onDelete: (record: IntegratingSphereCalibrationRecord) => void
}

export function CalibrationRecordTable({ records, canDelete, onDetail, onEdit, onDelete }: RecordActionProps) {
  return (
    <DataTable>
      <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
        <tr>
          <th className="px-3 py-2 font-medium">ID</th>
          <th className="px-3 py-2 font-medium">标准件编号</th>
          <th className="px-3 py-2 font-medium">系统编码</th>
          <th className="px-3 py-2 font-medium">模式</th>
          <th className="px-3 py-2 font-medium">灵敏度</th>
          <th className="px-3 py-2 font-medium">色温(K)</th>
          <th className="px-3 py-2 font-medium">Ra</th>
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
            <td className="px-3 py-3 text-sm text-slate-700">{record.mode_label}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.sensitivity_label}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.color_temperature}</td>
            <td className="px-3 py-3 text-sm text-slate-700">{record.color_rendering_index}</td>
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
    <div className="space-y-3 md:hidden" data-mobile-integrating-sphere-calibration-records>
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
            <CardEntry label="模式" value={record.mode_label} />
            <CardEntry label="灵敏度" value={record.sensitivity_label} />
            <CardEntry label="色温" value={`${record.color_temperature} K`} />
            <CardEntry label="Ra" value={record.color_rendering_index} />
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
  record: IntegratingSphereCalibrationRecord
  canDelete: boolean
  onDetail: (record: IntegratingSphereCalibrationRecord) => void
  onEdit: (record: IntegratingSphereCalibrationRecord) => void
  onDelete: (record: IntegratingSphereCalibrationRecord) => void
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

export function CalibrationRecordDetail({ record }: { record: IntegratingSphereCalibrationRecord }) {
  return (
    <div className="space-y-4">
      <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <DetailEntry label="标准件编号" value={record.standard_no} />
        <DetailEntry label="标准件名称" value={record.standard_name} />
        <DetailEntry label="标准件厂家" value={record.standard_manufacturer ?? '-'} />
        <DetailEntry label="标准件型号" value={record.standard_model ?? '-'} />
        <DetailEntry label="系统编码" value={record.system_code} />
        <DetailEntry label="系统名称" value={record.system_name ?? '-'} />
        <DetailEntry label="模式" value={record.mode_label} />
        <DetailEntry label="灵敏度" value={record.sensitivity_label} />
        <DetailEntry label="色温" value={`${record.color_temperature} K`} />
        <DetailEntry label="显色指数 Ra" value={record.color_rendering_index} />
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
  catalogOptions,
  equipmentLookupFailed,
  standardLookupFailed,
  systemLookupFailed,
  onEquipmentCode,
  onStandardCode,
  onSystemCode,
  onRemoveEquipment,
  onChange,
}: {
  form: IntegratingSphereCalibrationForm
  recordId: number | null
  fieldErrors: Record<string, string>
  catalogOptions: CatalogFormOptions
  equipmentLookupFailed: boolean
  standardLookupFailed: boolean
  systemLookupFailed: boolean
  onEquipmentCode: (code: string) => void
  onStandardCode: (code: string) => void
  onSystemCode: (code: string) => void
  onRemoveEquipment: (key: string) => void
  onChange: (patch: Partial<IntegratingSphereCalibrationForm>) => void
}) {
  const currentModeCode = form.mode?.code ?? ''
  const currentSensitivityCode = form.sensitivity?.code ?? ''

  const isModeRetainedRemoved =
    form.mode?.source === 'retained' && !catalogOptions.modes.some((m) => m.code === currentModeCode)

  const isSensitivityRetainedRemoved =
    form.sensitivity?.source === 'retained' && !catalogOptions.sensitivities.some((s) => s.code === currentSensitivityCode)

  return (
    <div className="space-y-4">
      {/* Order: equipment -> system -> standard -> mode/sensitivity -> measurements -> attachments */}
      <EquipmentScannerBlock
        devices={form.equipment}
        lookupFailed={equipmentLookupFailed}
        error={fieldErrors.equipment}
        onCode={onEquipmentCode}
        onRemove={onRemoveEquipment}
      />

      <SystemScannerBlock
        system={form.system}
        lookupFailed={systemLookupFailed}
        error={fieldErrors.system}
        onCode={onSystemCode}
      />

      <StandardScannerBlock
        standard={form.standard}
        lookupFailed={standardLookupFailed}
        error={fieldErrors.standard}
        onCode={onStandardCode}
      />

      <Panel title="模式与灵敏度">
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="模式">
            <select
              className={inputClass}
              value={currentModeCode}
              onChange={(event) => {
                const code = event.target.value
                const opt = catalogOptions.modes.find((m) => m.code === code)
                onChange({ mode: { source: 'selected', code, label: opt?.label ?? code } })
              }}
            >
              {isModeRetainedRemoved ? (
                <option value={form.mode!.code} disabled>
                  {form.mode!.label}（历史快照，已自目录移除）
                </option>
              ) : null}
              {catalogOptions.modes.map((mode) => (
                <option key={mode.code} value={mode.code}>
                  {mode.label}
                </option>
              ))}
            </select>
            {isModeRetainedRemoved ? (
              <p className="mt-1 text-xs text-amber-700" data-retained-removed-mode-notice>
                该定标模式已从系统目录移除，保存时保留历史快照；重新选择模式后将被替换。
              </p>
            ) : null}
            <FieldError message={fieldErrors.mode_code} />
          </Field>

          <Field label="灵敏度">
            <select
              className={inputClass}
              value={currentSensitivityCode}
              onChange={(event) => {
                const code = event.target.value
                const opt = catalogOptions.sensitivities.find((s) => s.code === code)
                onChange({ sensitivity: { source: 'selected', code, label: opt?.label ?? code } })
              }}
            >
              {isSensitivityRetainedRemoved ? (
                <option value={form.sensitivity!.code} disabled>
                  {form.sensitivity!.label}（历史快照，已自目录移除）
                </option>
              ) : null}
              {catalogOptions.sensitivities.map((sensitivity) => (
                <option key={sensitivity.code} value={sensitivity.code}>
                  {sensitivity.label}
                </option>
              ))}
            </select>
            {isSensitivityRetainedRemoved ? (
              <p className="mt-1 text-xs text-amber-700" data-retained-removed-sensitivity-notice>
                该灵敏度已从系统目录移除，保存时保留历史快照；重新选择灵敏度后将被替换。
              </p>
            ) : null}
            <FieldError message={fieldErrors.sensitivity_code} />
          </Field>
        </div>
      </Panel>

      <Panel title="测量值">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {integratingSphereCalibrationMeasurementFields.map((field) => (
            <Field key={field.name} label={field.unit ? `${field.label}（${field.unit}）` : field.label}>
              <input
                className={inputClass}
                inputMode={field.scale === 0 ? 'numeric' : 'decimal'}
                value={form[field.name]}
                placeholder={field.scale === 0 ? '0' : `0.${'0'.repeat(field.scale)}`}
                onChange={(event) => onChange({ [field.name]: event.target.value } as Partial<IntegratingSphereCalibrationForm>)}
              />
              <FieldError message={fieldErrors[field.name]} />
            </Field>
          ))}
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
