import { Trash2 } from 'lucide-react'
import { MediaDownloadButton } from '../equipment/InspectionMediaComponents'
import { Button, Field } from '../system/shared'
import { attachmentTypes, type ReportMedia } from './lm79Api'

export function ReportFileField({ type, fileKey, editable, reportId, media, selected, showLabel = true, onSelect, onRemove }: {
  type: typeof attachmentTypes[number]
  fileKey: number
  editable: boolean
  reportId?: number
  media: ReportMedia[]
  selected: File[]
  showLabel?: boolean
  onSelect: (files: File[]) => void
  onRemove: (id: number) => void
}) {
  const input = <input key={fileKey} aria-label={type.label} className="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2" type="file" accept={type.accept} multiple={type.name === 'photos'} disabled={!editable} onChange={event => onSelect(Array.from(event.target.files ?? []))} />
  return <div className="space-y-2">
    {showLabel ? <Field label={type.label}>{input}</Field> : input}
    {type.description ? <p className="text-xs leading-5 text-slate-500">{type.description}</p> : null}
    {selected.length ? <p role="status" className="break-all text-xs text-emerald-800">待保存：{selected.map(file => file.name).join('、')}</p> : null}
    {media.map(file => <div key={file.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-slate-50 px-3 py-2 text-sm">
      <span className="min-w-0 break-all">{file.file_name} · {(file.size / 1024).toFixed(1)} KB</span>
      <div className="flex shrink-0 items-center gap-2">
        {reportId ? <MediaDownloadButton baseUrl="/api/lm79-reports" recordId={reportId} media={file} /> : null}
        {editable ? <Button variant="ghost" className="size-11 px-0 hover:bg-red-50 hover:text-red-600" title="移除此文件" aria-label={`移除文件 ${file.file_name}`} onClick={() => onRemove(file.id)}><Trash2 aria-hidden="true" className="size-4" /></Button> : null}
      </div>
    </div>)}
  </div>
}
