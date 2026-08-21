import { X } from 'lucide-react'
import { QrScannerPanel } from '../../components/app/QrScannerPanel'
import { DataTable, ErrorNotice } from '../system/shared'
import {
  equipmentEntryKey,
  type InspectionEquipmentLedgerRow,
  type InspectionEquipmentSnapshot,
  type InspectionFormEquipment,
  type InspectionFormSample,
  type InspectionFormSystem,
} from './inspectionShared'

/**
 * The presentation every inspection workflow shares: the three scanner blocks, the
 * selected-equipment snapshot list, the flattened used-equipment ledger and the
 * detail snapshot table.
 *
 * Each workflow keeps its own record list, measurement grid and editor — those carry
 * domain meaning — but the pieces below say exactly the same thing about the same
 * evidence in both, down to the retained/selected/orphan notices, so they are
 * written once.
 */

export function FieldError({ message }: { message?: string }) {
  if (!message) {
    return null
  }

  return <p className="mt-1 text-xs text-red-600">{message}</p>
}

export function CardEntry({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="min-w-0">
      <dt className="text-slate-500">{label}</dt>
      <dd className="truncate font-medium text-slate-800">{value}</dd>
    </div>
  )
}

export function DetailEntry({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className="mt-0.5 break-words font-medium text-slate-900">{value}</dd>
    </div>
  )
}

export function EquipmentScannerBlock({
  devices,
  lookupFailed,
  error,
  onCode,
  onRemove,
}: {
  devices: InspectionFormEquipment[]
  lookupFailed: boolean
  error?: string
  onCode: (code: string) => void
  onRemove: (key: string) => void
}) {
  return (
    <div className="space-y-2">
      <QrScannerPanel title="使用设备（先录入）" placeholder="扫码/手输设备编号" onDetected={onCode}>
        <SelectedEquipment devices={devices} onRemove={onRemove} />
      </QrScannerPanel>
      {lookupFailed ? <ErrorNotice error="未找到设备" fallback="未找到设备" /> : null}
      <FieldError message={error} />
    </div>
  )
}

export function SampleScannerBlock({
  sample,
  lookupFailed,
  error,
  onCode,
}: {
  sample: InspectionFormSample | null
  lookupFailed: boolean
  error?: string
  onCode: (code: string) => void
}) {
  return (
    <div className="space-y-2">
      <QrScannerPanel title="样品编号" placeholder="扫码/手输样品编号" onDetected={onCode}>
        {sample ? (
          <div className="space-y-1">
            <p className="text-xs text-slate-600">
              <span className="font-semibold text-slate-900">{sample.sample_no}</span>
              {sample.sample_name ? ` · ${sample.sample_name}` : ''}
              {sample.model ? ` · ${sample.model}` : ''}
            </p>
            {sample.source === 'retained' && sample.id === null ? (
              <p className="text-xs text-amber-700" data-orphan-sample-notice>
                该样品已从样品台账删除，保存时保留历史快照；如需更换请重新扫码。
              </p>
            ) : null}
            {sample.source === 'retained' && sample.id !== null ? (
              <p className="text-xs text-slate-500" data-retained-sample-notice>
                沿用记录中的样品编号快照；重新扫码或手输才会替换为台账当前编号。
              </p>
            ) : null}
            {sample.source === 'selected' ? (
              <p className="text-xs text-emerald-700" data-selected-sample-notice>
                本次录入，保存时按台账当前编号记录。
              </p>
            ) : null}
          </div>
        ) : (
          <p className="text-xs text-slate-500">尚未录入样品</p>
        )}
      </QrScannerPanel>
      {lookupFailed ? <ErrorNotice error="未找到样品" fallback="未找到样品" /> : null}
      <FieldError message={error} />
    </div>
  )
}

export function SystemScannerBlock({
  system,
  lookupFailed,
  error,
  onCode,
}: {
  system: InspectionFormSystem | null
  lookupFailed: boolean
  error?: string
  onCode: (code: string) => void
}) {
  return (
    <div className="space-y-2">
      <QrScannerPanel title="系统编码" placeholder="扫码/手输系统编码" onDetected={onCode}>
        {system ? (
          <div className="space-y-1">
            <p className="text-xs text-slate-600">
              <span className="font-semibold text-slate-900">{system.code}</span>
              {system.name ? ` · ${system.name}` : ''}
            </p>
            {system.source === 'retained' && system.id === null ? (
              <p className="text-xs text-amber-700" data-orphan-system-notice>
                该系统已从设备系统台账删除，保存时保留历史快照；如需更换请重新扫码。
              </p>
            ) : null}
            {system.source === 'retained' && system.id !== null ? (
              <p className="text-xs text-slate-500" data-retained-system-notice>
                沿用记录中的系统编码快照；重新扫码或手输才会替换为台账当前编码。
              </p>
            ) : null}
            {system.source === 'selected' ? (
              <p className="text-xs text-emerald-700" data-selected-system-notice>
                本次录入，保存时按台账当前编码记录。
              </p>
            ) : null}
          </div>
        ) : (
          <p className="text-xs text-slate-500">尚未录入系统编码</p>
        )}
      </QrScannerPanel>
      {lookupFailed ? <ErrorNotice error="未找到系统编码" fallback="未找到系统编码" /> : null}
      <FieldError message={error} />
    </div>
  )
}

/**
 * Shows exactly what will be stored: devices resolved from the live ledger, and
 * retained snapshots whose ledger row has since been deleted. The latter are
 * labelled so the operator can see the record still carries them and that removing
 * one throws away the only remaining evidence of that device.
 */
export function SelectedEquipment({
  devices,
  onRemove,
}: {
  devices: InspectionFormEquipment[]
  onRemove: (key: string) => void
}) {
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

/**
 * The global used-equipment ledger view. Every row is one association between an
 * inspection record and a device, carrying the three ids the sheet is keyed on.
 */
export function EquipmentLedgerTable({ rows, recordLabel }: { rows: InspectionEquipmentLedgerRow[]; recordLabel: string }) {
  return (
    <DataTable>
      <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
        <tr>
          <th className="px-3 py-2 font-medium">ID</th>
          <th className="px-3 py-2 font-medium">{recordLabel}</th>
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

/**
 * The mobile form of the same ledger. `marker` is the data attribute the owning
 * page is identified by, so each workflow keeps its own hook for tests and styling.
 */
export function EquipmentLedgerCards({
  rows,
  recordLabel,
  marker,
}: {
  rows: InspectionEquipmentLedgerRow[]
  recordLabel: string
  marker: string
}) {
  return (
    <div className="space-y-3 md:hidden" {...{ [marker]: true }}>
      {rows.map((row) => (
        <article className="rounded-lg border border-emerald-900/10 bg-white p-4 shadow-sm" key={row.id}>
          <div className="min-w-0">
            <h3 className="truncate text-sm font-semibold text-slate-950">{row.equipment_no}</h3>
            <p className="mt-0.5 truncate text-xs text-slate-500">{row.equipment_name}</p>
          </div>
          <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
            <CardEntry label="ID" value={row.id} />
            <CardEntry label={recordLabel} value={row.inspection_record_id} />
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

/** The used-equipment snapshots a single record carries, as shown in its detail. */
export function EquipmentSnapshotTable({ devices }: { devices: InspectionEquipmentSnapshot[] }) {
  return (
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
          {devices.map((device) => (
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
  )
}
