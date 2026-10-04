import { RefreshCw, Trash2 } from 'lucide-react'
import { useRef } from 'react'
import { MediaDownloadButton } from '../equipment/InspectionMediaComponents'
import { Button, Field } from '../system/shared'
import { attachmentTypes, type MeasurementImportResult, type ReportMedia } from './lm79Api'

export function ReportFileField({ type, fileKey, editable, reportId, media, selected, showLabel = true, onSelect, onRemove, parsing, imported, record, parseError, onParse }: {
  type: typeof attachmentTypes[number]
  fileKey: number
  editable: boolean
  reportId?: number
  media: ReportMedia[]
  selected: File[]
  showLabel?: boolean
  onSelect: (files: File[]) => void
  onRemove: (id: number) => void
  parsing?: boolean
  imported?: MeasurementImportResult
  record?: number
  parseError?: string
  onParse?: (record?: number) => void
}) {
  const inputRef = useRef<HTMLInputElement>(null)
  return <div className="space-y-2">
    <div className="flex flex-wrap items-center gap-3">
      {editable ? <label className="relative inline-flex h-9 shrink-0 items-center rounded-md border border-emerald-900/15 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-within:ring-2 focus-within:ring-emerald-600">
        {showLabel ? `选择 ${type.label}` : '选择文件'}
        <input ref={inputRef} key={fileKey} aria-label={type.label} className="absolute inset-0 h-full w-full cursor-pointer opacity-0" type="file" accept={type.accept} multiple={type.name === 'photos'} onChange={event => onSelect(Array.from(event.target.files ?? []))} />
      </label> : null}
      {selected.length ? <><span className="min-w-0 break-all text-sm text-slate-700">{selected.map(file => file.name).join('、')}</span>{editable ? <Button variant="ghost" className="size-9 shrink-0 px-0 hover:bg-red-50 hover:text-red-600" title="移除待上传文件" aria-label={`移除待上传文件 ${type.label}`} onClick={() => { if (inputRef.current) inputRef.current.value = ''; onSelect([]) }}><Trash2 className="size-4" aria-hidden="true" /></Button> : null}</> : null}
      {!selected.length && !media.length && type.description ? <span className="text-xs text-slate-500">{type.description}</span> : null}
      {media.map(file => <div key={file.id} className={`flex min-w-0 flex-1 flex-wrap items-center justify-between gap-2 text-sm ${type.name === 'photos' ? 'basis-full' : ''}`}>
        <span className="min-w-0 break-all text-slate-700">{file.file_name} <span className="text-xs text-slate-400">{(file.size / 1024).toFixed(1)} KB</span></span>
        <div className="flex shrink-0 items-center gap-2">
          {editable && onParse ? <Button variant="ghost" className="size-9 px-0" title="重新解析并回填" aria-label={`重新解析 ${type.label}`} onClick={() => onParse(record)}><RefreshCw className="size-4" aria-hidden="true" /></Button> : null}
          {reportId ? <MediaDownloadButton baseUrl="/api/lm79-reports" recordId={reportId} media={file} /> : null}
          {editable ? <Button variant="ghost" className="size-9 px-0 hover:bg-red-50 hover:text-red-600" title="移除此文件" aria-label={`移除文件 ${file.file_name}`} onClick={() => onRemove(file.id)}><Trash2 aria-hidden="true" className="size-4" /></Button> : null}
        </div>
      </div>)}
    </div>
    {onParse && imported && imported.records.length > 1 ? <Field label="检测记录"><select aria-label={`${type.label}检测记录`} className="h-9 max-w-full rounded-md border border-emerald-900/20 px-2 text-sm" disabled={!editable} value={record ?? ''} onChange={event => { if (event.target.value) onParse(Number(event.target.value)) }}>
        <option value="">请选择本次报告采用的记录</option>
        {imported.records.map(row => <option key={row.index} value={row.index}>{`第 ${row.index} 条 · 样品 ${row.sample || '—'} · ${row.model} · ${row.date}`}</option>)}
      </select></Field> : null}
    {parsing ? <p role="status" className="text-xs text-slate-600">正在解析并回填…</p> : parseError ? <div className="flex flex-wrap items-center gap-2"><p role="alert" className="text-sm text-red-700">{parseError}</p>{editable && onParse ? <Button variant="ghost" onClick={() => onParse(record)}>重试解析</Button> : null}</div> : imported ? <p role="status" className="text-xs text-emerald-800">{imported.notice}{imported.selected_record && imported.spectrum_point_count ? ` 光谱 ${imported.spectrum_point_count.toLocaleString('en-US')} 点。` : ''}</p> : null}
  </div>
}
