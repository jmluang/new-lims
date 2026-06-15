import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useRouterState } from '@tanstack/react-router'
import { ArrowLeft, Save, X } from 'lucide-react'
import { useState } from 'react'
import { QrScannerPanel } from '../../components/app/QrScannerPanel'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { Button, ErrorNotice, Field, LoadingState, PageShell, Panel } from '../system/shared'
import { type ApiResource, inputClass, localDateTimeInputValue, textareaClass } from '../system/utils'
import { buildEquipmentCalibrationPayload, EquipmentCalibrationValidationError } from './equipmentCalibrationSchema'

type RowState = {
  key: string
  equipment_id?: number
  label: string
  remark: string
}

type CalibrationProjectOption = {
  id: number
  project_no: string
  project_name: string
  status: string
}

type CalibrationDetail = {
  id: number
  calibration_project_id?: number | null
  calibration_name: string
  calibration_time: string
  result: string
  remark?: string | null
  attachment_files?: string[]
  photo_files?: string[]
  devices: Array<{ equipment_id?: number | null; equipment_no: string; equipment_name: string; remark?: string | null }>
  standards: Array<{ equipment_id?: number | null; standard_no: string; standard_name: string; remark?: string | null }>
}

let rowSeed = 0
function nextKey() {
  rowSeed += 1
  return `row-${rowSeed}`
}

export function EquipmentCalibrationFormPage() {
  const pathname = useRouterState({ select: (state) => state.location.pathname })
  const editingId = calibrationIdFromEditPath(pathname)

  const projectsQuery = useQuery({
    queryKey: ['calibration-projects', 'options'],
    retry: false,
    queryFn: async () => {
      const response = await api.get<{ data: CalibrationProjectOption[] }>('/api/calibration-projects')

      return response.data.data
    },
  })

  const detailQuery = useQuery({
    queryKey: ['equipment-calibration', editingId],
    enabled: editingId !== null,
    queryFn: async () => {
      const response = await api.get<ApiResource<CalibrationDetail>>(`/api/equipment-calibrations/${editingId}`)

      return response.data.data
    },
  })

  const loading = (editingId !== null && detailQuery.isPending) || projectsQuery.isPending

  return (
    <PageShell
      title={editingId !== null ? 'Edit calibration record' : 'New calibration record'}
      description="Record device calibration with standards, results and attachments."
      actions={
        <Link className="inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100" to="/equipment/calibrations">
          <ArrowLeft className="size-4" aria-hidden="true" />
          {zhText('Back to list')}
        </Link>
      }
    >
      {detailQuery.isError ? <ErrorNotice error={detailQuery.error} fallback="无法加载定标记录" /> : null}
      {loading ? (
        <LoadingState label="正在加载定标记录" />
      ) : (
        <CalibrationForm key={editingId ?? 'new'} editingId={editingId} detail={detailQuery.data ?? null} projects={projectsQuery.data ?? []} />
      )}
    </PageShell>
  )
}

function CalibrationForm({ editingId, detail, projects }: { editingId: number | null; detail: CalibrationDetail | null; projects: CalibrationProjectOption[] }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [basic, setBasic] = useState(() => initialBasic(detail))
  const [devices, setDevices] = useState<RowState[]>(() => initialRows(detail?.devices ?? [], (row) => row.equipment_no, (row) => row.equipment_name))
  const [standards, setStandards] = useState<RowState[]>(() => initialRows(detail?.standards ?? [], (row) => row.standard_no, (row) => row.standard_name))
  const [attachments, setAttachments] = useState(() => (detail?.attachment_files ?? []).join('\n'))
  const [photos, setPhotos] = useState(() => (detail?.photo_files ?? []).join('\n'))
  const [validationError, setValidationError] = useState<string | null>(null)

  const lookupEquipment = useMutation({
    mutationFn: async (code: string) => {
      const response = await api.get<{ data: Array<{ id: number; equipment_no: string; name: string }> }>('/api/equipment', { params: { search: code, per_page: 10 } })
      const match = response.data.data.find((item) => item.equipment_no === code) ?? response.data.data[0]

      if (!match) {
        throw new Error('未找到设备')
      }

      return match
    },
  })

  function addRow(target: 'devices' | 'standards', code: string) {
    setValidationError(null)
    lookupEquipment.mutate(code, {
      onSuccess: (match) => {
        const row: RowState = { key: nextKey(), equipment_id: match.id, label: `${match.equipment_no} - ${match.name}`, remark: '' }

        if (target === 'devices') {
          setDevices((current) => (current.some((item) => item.equipment_id === match.id) ? current : [...current, row]))
        } else {
          setStandards((current) => (current.some((item) => item.equipment_id === match.id) ? current : [...current, row]))
        }
      },
    })
  }

  const saveCalibration = useMutation({
    mutationFn: async () => {
      const payload = buildEquipmentCalibrationPayload({
        calibration_project_id: basic.calibration_project_id ? Number(basic.calibration_project_id) : null,
        calibration_name: basic.calibration_name,
        calibration_time: basic.calibration_time,
        result: basic.result,
        remark: basic.remark,
        attachment_files: splitLines(attachments),
        photo_files: splitLines(photos),
        devices: devices.map((row) => ({ equipment_id: row.equipment_id, remark: row.remark })),
        standards: standards.map((row) => ({ equipment_id: row.equipment_id, remark: row.remark })),
      })

      if (editingId !== null) {
        await api.put(`/api/equipment-calibrations/${editingId}`, payload)
      } else {
        await api.post('/api/equipment-calibrations', payload)
      }
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['equipment-calibrations'] })
      void navigate({ to: '/equipment/calibrations' })
    },
    onError: (error) => {
      if (error instanceof EquipmentCalibrationValidationError) {
        setValidationError(error.message)
      }
    },
  })

  return (
    <div className="space-y-4">
      <Panel title="基础信息">
        <div className="grid gap-3 md:grid-cols-2">
          <Field label="定标项目">
            <select className={inputClass} value={basic.calibration_project_id} onChange={(event) => setBasic({ ...basic, calibration_project_id: event.target.value })}>
              <option value="">{zhText('None')}</option>
              {projects.map((project) => (
                <option value={project.id} key={project.id}>
                  {project.project_no} - {project.project_name}
                </option>
              ))}
            </select>
          </Field>
          <Field label="定标名称">
            <input className={inputClass} value={basic.calibration_name} onChange={(event) => setBasic({ ...basic, calibration_name: event.target.value })} />
          </Field>
          <Field label="定标时间">
            <input className={inputClass} type="datetime-local" value={basic.calibration_time} onChange={(event) => setBasic({ ...basic, calibration_time: event.target.value })} />
          </Field>
          <Field label="结果">
            <select className={inputClass} value={basic.result} onChange={(event) => setBasic({ ...basic, result: event.target.value })}>
              <option value="qualified">{zhText('qualified')}</option>
              <option value="unqualified">{zhText('unqualified')}</option>
            </select>
          </Field>
          <Field label="备注" className="md:col-span-2">
            <textarea className={textareaClass} value={basic.remark} onChange={(event) => setBasic({ ...basic, remark: event.target.value })} />
          </Field>
        </div>
      </Panel>

      <div className="grid gap-4 md:grid-cols-2">
        <div className="space-y-2">
          <QrScannerPanel title="设备明细" placeholder="设备编号" onDetected={(code) => addRow('devices', code)} />
          <RowList rows={devices} onChangeRemark={(key, remark) => setDevices((current) => current.map((row) => (row.key === key ? { ...row, remark } : row)))} onRemove={(key) => setDevices((current) => current.filter((row) => row.key !== key))} />
        </div>
        <div className="space-y-2">
          <QrScannerPanel title="标准件明细" placeholder="标准件编号" onDetected={(code) => addRow('standards', code)} />
          <RowList rows={standards} onChangeRemark={(key, remark) => setStandards((current) => current.map((row) => (row.key === key ? { ...row, remark } : row)))} onRemove={(key) => setStandards((current) => current.filter((row) => row.key !== key))} />
        </div>
      </div>

      <Panel title="附件与现场照片">
        <div className="grid gap-3 md:grid-cols-2">
          <Field label="附件（每行一个）">
            <textarea className={textareaClass} value={attachments} onChange={(event) => setAttachments(event.target.value)} />
          </Field>
          <Field label="现场照片（每行一个）">
            <textarea className={textareaClass} value={photos} onChange={(event) => setPhotos(event.target.value)} />
          </Field>
        </div>
      </Panel>

      {lookupEquipment.isError ? <ErrorNotice error="未找到设备" fallback="未找到设备" /> : null}
      {validationError ? <ErrorNotice error={validationError} fallback={validationError} /> : null}
      {saveCalibration.error && !(saveCalibration.error instanceof EquipmentCalibrationValidationError) ? <ErrorNotice error={saveCalibration.error} fallback="无法保存定标记录" /> : null}

      <div className="flex justify-end">
        <Button variant="primary" onClick={() => saveCalibration.mutate()} disabled={saveCalibration.isPending}>
          <Save className="size-4" aria-hidden="true" />
          {zhText('Save')}
        </Button>
      </div>
    </div>
  )
}

function initialBasic(detail: CalibrationDetail | null) {
  if (!detail) {
    return {
      calibration_project_id: '',
      calibration_name: '',
      calibration_time: localDateTimeInputValue(),
      result: 'qualified',
      remark: '',
    }
  }

  return {
    calibration_project_id: detail.calibration_project_id ? String(detail.calibration_project_id) : '',
    calibration_name: detail.calibration_name,
    calibration_time: detail.calibration_time.replace(' ', 'T').slice(0, 16),
    result: detail.result,
    remark: detail.remark ?? '',
  }
}

function initialRows<T extends { equipment_id?: number | null; remark?: string | null }>(rows: T[], getNo: (row: T) => string, getName: (row: T) => string): RowState[] {
  return rows.map((row) => ({
    key: nextKey(),
    equipment_id: row.equipment_id ?? undefined,
    label: `${getNo(row)} - ${getName(row)}`,
    remark: row.remark ?? '',
  }))
}

function RowList({ rows, onChangeRemark, onRemove }: { rows: RowState[]; onChangeRemark: (key: string, remark: string) => void; onRemove: (key: string) => void }) {
  if (rows.length === 0) {
    return <p className="text-xs text-slate-500">{zhText('已选')}: -</p>
  }

  return (
    <div className="space-y-2">
      {rows.map((row) => (
        <div className="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-2 py-1.5" key={row.key}>
          <span className="min-w-0 flex-1 truncate text-sm text-slate-800">{row.label}</span>
          <input className={`${inputClass} h-8 max-w-40`} value={row.remark} placeholder="备注" onChange={(event) => onChangeRemark(row.key, event.target.value)} />
          <button type="button" className="text-slate-400 hover:text-red-600" aria-label="移除" onClick={() => onRemove(row.key)}>
            <X className="size-4" aria-hidden="true" />
          </button>
        </div>
      ))}
    </div>
  )
}

function splitLines(value: string) {
  return value
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '')
}

function calibrationIdFromEditPath(pathname: string) {
  const match = pathname.match(/^\/equipment\/calibrations\/(\d+)\/edit$/)

  return match ? Number(match[1]) : null
}
