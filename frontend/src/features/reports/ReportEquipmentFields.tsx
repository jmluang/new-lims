import { Trash2 } from 'lucide-react'
import { QrScannerPanel } from '../../components/app/QrScannerPanel'
import { Button, ErrorNotice, Field, Panel } from '../system/shared'
import { inputClass } from '../system/utils'
import type { EquipmentRow } from './lm79Api'

export function ReportEquipmentFields({ devices, active, editable, pending, error, notice, onCode, onRemove, onCalibrationChange }: {
  devices: EquipmentRow[]
  active: boolean
  editable: boolean
  pending: boolean
  error: unknown
  notice: string
  onCode: (code: string) => void
  onRemove: (index: number) => void
  onCalibrationChange: (index: number, field: 'cal_cert' | 'cal_org' | 'cal_due', value: string) => void
}) {
  return <div className="space-y-4">
    {active && editable ? <QrScannerPanel title="从设备台账添加测试设备" placeholder="扫码或输入设备编号" onDetected={onCode}>
      <p className="text-xs leading-5 text-slate-500">扫描设备标签二维码，或输入台账设备编号后添加。名称、型号、序列号和下次校准日期自动带入。</p>
    </QrScannerPanel> : null}
    {pending ? <p role="status" className="text-sm text-slate-600">正在查询设备台账…</p> : null}
    {error ? <div role="alert"><ErrorNotice error={error} fallback="设备查询失败，请稍后重试" /></div> : null}
    {notice ? <p role="status" className="text-sm text-emerald-800">{notice}</p> : null}
    <Panel title="测试设备与校准溯源">
      <p className="mb-4 text-sm leading-6 text-slate-500">报告保留添加时的设备资料。校准证书编号、校准机构及报告采用的有效期可补充填写。</p>
      {devices.length === 0 ? <p className="py-4 text-sm text-slate-500">尚未添加测试设备，请扫码或输入设备编号。</p> : <div className="space-y-4">
        {devices.map((device, index) => <article key={device.snapshot_id ?? device.equipment_id ?? index} className="rounded-lg border border-emerald-900/10 p-4">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0"><h3 className="break-words text-sm font-semibold text-slate-900">{device.equipment_no || '历史设备记录'} · {device.name}</h3><p className="mt-1 text-xs text-slate-500">{device.snapshot_id ? '沿用报告中的设备快照' : '设备台账 · 本次添加'}{!device.equipment_id ? '（未关联台账，可移除后重新扫码关联）' : ''}</p></div>
            <Button variant="ghost" className="size-10 shrink-0 px-0 hover:bg-red-50 hover:text-red-600" title="移除此设备" aria-label={`移除设备 ${device.equipment_no || device.name}`} onClick={() => onRemove(index)}><Trash2 aria-hidden="true" className="size-4" /></Button>
          </div>
          <dl className="my-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 text-sm">
            {([['manufacturer', '生产厂家'], ['model', '型号'], ['serial', '出厂序列号'], ['next_calibration_date', '台账下次校准日期']] as const).map(([field, label]) => <div key={field} className="min-w-0"><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 break-words text-slate-800">{device[field] || '—'}</dd></div>)}
          </dl>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {([['cal_cert', '校准证书编号'], ['cal_org', '校准机构'], ['cal_due', '报告采用的校准有效期']] as const).map(([field, label]) => <Field key={field} label={label}><input className={inputClass} value={device[field] ?? ''} onChange={event => onCalibrationChange(index, field, event.target.value)} /></Field>)}
          </div>
        </article>)}
      </div>}
    </Panel>
  </div>
}
