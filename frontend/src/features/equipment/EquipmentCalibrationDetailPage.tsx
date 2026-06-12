import { useQuery } from '@tanstack/react-query'
import { Link, useRouterState } from '@tanstack/react-router'
import { ArrowLeft } from 'lucide-react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { DataTable, ErrorNotice, LoadingState, PageShell, Panel, StatusBadge } from '../system/shared'
import { type ApiResource } from '../system/utils'

type CalibrationDetail = {
  id: number
  calibration_name: string
  calibration_time: string
  operator_name?: string | null
  result: string
  remark?: string | null
  attachment_files?: string[]
  photo_files?: string[]
  created_at?: string | null
  updated_at?: string | null
  devices: Array<{ id: number; equipment_no: string; equipment_name: string; equipment_model?: string | null; remark?: string | null }>
  standards: Array<{ id: number; standard_no: string; standard_name: string; standard_model?: string | null; remark?: string | null }>
}

export function EquipmentCalibrationDetailPage() {
  const pathname = useRouterState({ select: (state) => state.location.pathname })
  const calibrationId = calibrationIdFromPath(pathname)

  const detailQuery = useQuery({
    queryKey: ['equipment-calibration', calibrationId],
    enabled: calibrationId !== null,
    queryFn: async () => {
      const response = await api.get<ApiResource<CalibrationDetail>>(`/api/equipment-calibrations/${calibrationId}`)

      return response.data.data
    },
  })

  const record = detailQuery.data

  return (
    <PageShell
      title="Calibration record"
      description="Device calibration record detail."
      actions={
        <div className="flex items-center gap-2">
          {record ? (
            <PermissionGate resource="equipment_calibrations" action="update">
              <Link
                className="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-emerald-900/15 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-emerald-50"
                to="/equipment/calibrations/$calibrationId/edit"
                params={{ calibrationId: String(record.id) }}
              >
                编辑
              </Link>
            </PermissionGate>
          ) : null}
          <Link className="inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100" to="/equipment/calibrations">
            <ArrowLeft className="size-4" aria-hidden="true" />
            {zhText('Back to list')}
          </Link>
        </div>
      }
    >
      {detailQuery.isError ? <ErrorNotice error={detailQuery.error} fallback="无法加载定标记录" /> : null}
      {detailQuery.isPending ? <LoadingState label="正在加载定标记录" /> : null}

      {record ? (
        <div className="space-y-4">
          <Panel title="基础信息">
            <div className="grid gap-3 text-sm md:grid-cols-4">
              <Detail label="定标名称" value={record.calibration_name} />
              <Detail label="定标时间" value={record.calibration_time} />
              <div>
                <div className="text-xs font-medium uppercase text-slate-500">{zhText('结果')}</div>
                <div className="mt-1">
                  <StatusBadge status={record.result} />
                </div>
              </div>
              <Detail label="操作人" value={record.operator_name} />
              <Detail label="创建时间" value={record.created_at} />
              <Detail label="更新时间" value={record.updated_at} />
              <Detail label="备注" value={record.remark} />
            </div>
          </Panel>

          <Panel title="设备明细">
            <ChildTable
              rows={record.devices.map((device) => ({ id: device.id, no: device.equipment_no, name: device.equipment_name, model: device.equipment_model, remark: device.remark }))}
            />
          </Panel>

          <Panel title="标准件明细">
            <ChildTable
              rows={record.standards.map((standard) => ({ id: standard.id, no: standard.standard_no, name: standard.standard_name, model: standard.standard_model, remark: standard.remark }))}
            />
          </Panel>

          <div className="grid gap-4 md:grid-cols-2">
            <Panel title="附件">
              <FileList files={record.attachment_files ?? []} />
            </Panel>
            <Panel title="现场照片">
              <FileList files={record.photo_files ?? []} />
            </Panel>
          </div>
        </div>
      ) : null}
    </PageShell>
  )
}

function ChildTable({ rows }: { rows: Array<{ id: number; no: string; name: string; model?: string | null; remark?: string | null }> }) {
  if (rows.length === 0) {
    return <p className="text-sm text-slate-500">-</p>
  }

  return (
    <DataTable>
      <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
        <tr>
          <th className="px-3 py-2 font-medium">编号</th>
          <th className="px-3 py-2 font-medium">名称</th>
          <th className="px-3 py-2 font-medium">型号</th>
          <th className="px-3 py-2 font-medium">备注</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-slate-200">
        {rows.map((row) => (
          <tr key={row.id}>
            <td className="px-3 py-2 text-sm font-medium text-slate-900">{row.no}</td>
            <td className="px-3 py-2 text-sm text-slate-700">{row.name}</td>
            <td className="px-3 py-2 text-sm text-slate-700">{row.model ?? '-'}</td>
            <td className="px-3 py-2 text-sm text-slate-700">{row.remark ?? '-'}</td>
          </tr>
        ))}
      </tbody>
    </DataTable>
  )
}

function FileList({ files }: { files: string[] }) {
  if (files.length === 0) {
    return <p className="text-sm text-slate-500">-</p>
  }

  return (
    <ul className="space-y-1 text-sm text-slate-700">
      {files.map((file) => (
        <li className="truncate" key={file}>
          {file}
        </li>
      ))}
    </ul>
  )
}

function Detail({ label, value }: { label: string; value?: string | number | null }) {
  return (
    <div>
      <div className="text-xs font-medium uppercase text-slate-500">{zhText(label)}</div>
      <div className="mt-1 text-slate-900">{value || '-'}</div>
    </div>
  )
}

function calibrationIdFromPath(pathname: string) {
  const match = pathname.match(/^\/equipment\/calibrations\/(\d+)$/)

  return match ? Number(match[1]) : null
}
