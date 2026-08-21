import { Download, FileText, Image as ImageIcon, X } from 'lucide-react'
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { api } from '../../lib/api'
import { cn } from '../../lib/utils'
import { Button, FileDropZone, Panel } from '../system/shared'
import { formatBytes } from '../system/utils'

import { type InspectionMedia, type MediaFormState, inspectionMediaLimits } from './inspectionShared'

export function FieldError({ message }: { message?: string }) {
  if (!message) {
    return null
  }

  return <p className="mt-1 text-xs text-red-600">{message}</p>
}

/**
 * Attachments are private: the bytes are fetched through the authenticated media
 * endpoints, never through a URL the browser could load on its own.
 */
export function MediaGallery({
  baseUrl,
  recordId,
  photos,
  files,
}: {
  baseUrl: string
  recordId: number
  photos: InspectionMedia[]
  files: InspectionMedia[]
}) {
  return (
    <div className="grid gap-4 sm:grid-cols-2" data-record-attachments>
      <Panel title={`照片（${photos.length}）`}>
        {photos.length === 0 ? (
          <p className="text-xs text-slate-500">暂无照片</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {photos.map((media) => (
              <MediaThumbnail key={media.id} baseUrl={baseUrl} recordId={recordId} media={media} />
            ))}
          </div>
        )}
      </Panel>
      <Panel title={`文件（${files.length}）`}>
        {files.length === 0 ? (
          <p className="text-xs text-slate-500">暂无文件</p>
        ) : (
          <ul className="space-y-2">
            {files.map((media) => (
              <li className="flex items-center justify-between gap-2" key={media.id}>
                <span className="min-w-0 truncate text-xs text-slate-700">
                  {media.file_name}
                  <span className="ml-1 text-slate-400">{formatBytes(media.size)}</span>
                </span>
                <MediaDownloadButton baseUrl={baseUrl} recordId={recordId} media={media} />
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </div>
  )
}

/**
 * Loads one attachment through the authenticated endpoint and hands back an
 * object URL that is revoked when the caller unmounts, so switching records does
 * not leak a blob per photo for the lifetime of the tab.
 */
function useMediaObjectUrl(baseUrl: string | undefined, recordId: number | null, media: InspectionMedia) {
  const [source, setSource] = useState<string | null>(null)

  useEffect(() => {
    if (baseUrl === undefined || recordId === null) {
      return
    }

    let objectUrl: string | null = null
    let cancelled = false

    api
      .get<Blob>(`${baseUrl}/${recordId}/media/${media.id}/view`, { responseType: 'blob' })
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
  }, [baseUrl, recordId, media.id])

  return source
}

export function MediaThumbnail({ baseUrl, recordId, media }: { baseUrl: string; recordId: number; media: InspectionMedia }) {
  const source = useMediaObjectUrl(baseUrl, recordId, media)

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

export function MediaDownloadButton({ baseUrl, recordId, media }: { baseUrl: string; recordId: number; media: InspectionMedia }) {
  const [downloading, setDownloading] = useState(false)

  async function download() {
    setDownloading(true)

    try {
      const response = await api.get<Blob>(`${baseUrl}/${recordId}/media/${media.id}/download`, { responseType: 'blob' })
      const objectUrl = URL.createObjectURL(response.data)
      const link = document.createElement('a')

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

/** Spells the accept list back to the operator: `.pdf,.xls` reads as `PDF / XLS`. */
function acceptSummary(accept: string) {
  return accept
    .split(',')
    .map((entry) => entry.trim().replace(/^image\//, '').replace(/^\./, '').toUpperCase())
    .filter((entry) => entry.length > 0)
    .join(' / ')
}

/**
 * The same check the file dialog applies, repeated for dropped files: dropping
 * bypasses the input's accept list entirely.
 */
function matchesAccept(file: File, accept: string) {
  const rules = accept
    .split(',')
    .map((rule) => rule.trim().toLowerCase())
    .filter((rule) => rule.length > 0)

  if (rules.length === 0) {
    return true
  }

  const name = file.name.toLowerCase()
  const type = file.type.toLowerCase()

  return rules.some((rule) => {
    if (rule.startsWith('.')) {
      return name.endsWith(rule)
    }

    if (rule.endsWith('/*')) {
      return type.startsWith(rule.slice(0, -1))
    }

    return type === rule
  })
}

/** Previews for freshly picked images, revoked whenever the selection changes. */
function useLocalPreviews(files: File[], enabled: boolean) {
  const urls = useMemo(
    () => (enabled && typeof URL.createObjectURL === 'function' ? files.map((file) => URL.createObjectURL(file)) : []),
    [files, enabled],
  )

  useEffect(() => () => urls.forEach((url) => URL.revokeObjectURL(url)), [urls])

  return urls
}

/**
 * One attachment collection of the editor: the media the record already carries,
 * which stay unless the operator removes them, plus the files picked in this session.
 */
export function AttachmentPicker<T extends MediaFormState>({
  title,
  collection,
  recordId,
  baseUrl,
  form,
  error,
  onChange,
}: {
  title: string
  collection: 'photos' | 'files'
  recordId: number | null
  baseUrl?: string
  form: T
  error?: string
  onChange: (patch: Partial<T>) => void
}) {
  const limits = inspectionMediaLimits[collection]
  const isPhotos = collection === 'photos'
  const retained = form.retained_media.filter((media) => media.collection === collection)
  const picked = isPhotos ? form.new_photos : form.new_files
  const previews = useLocalPreviews(picked, isPhotos)
  const [skipped, setSkipped] = useState<string[]>([])
  const total = retained.length + picked.length
  const room = Math.max(limits.maxItems - total, 0)

  function setPicked(files: File[]) {
    setSkipped([])
    onChange((isPhotos ? { new_photos: files } : { new_files: files }) as Partial<T>)
  }

  function addFiles(incoming: File[]) {
    const accepted: File[] = []
    const rejected: string[] = []

    for (const file of incoming) {
      if (!matchesAccept(file, limits.accept)) {
        rejected.push(`${file.name}（格式不支持）`)
        continue
      }

      if (picked.some((entry) => entry.name === file.name && entry.size === file.size)) {
        rejected.push(`${file.name}（已选择）`)
        continue
      }

      if (accepted.length >= room) {
        rejected.push(`${file.name}（超出 ${limits.maxItems} 个上限）`)
        continue
      }

      accepted.push(file)
    }

    if (accepted.length > 0) {
      onChange((isPhotos ? { new_photos: [...picked, ...accepted] } : { new_files: [...picked, ...accepted] }) as Partial<T>)
    }

    setSkipped(rejected)
  }

  function removeRetained(id: number) {
    setSkipped([])
    onChange({ retained_media: form.retained_media.filter((entry) => entry.id !== id) } as Partial<T>)
  }

  return (
    <Panel title={`${title}（${total}/${limits.maxItems}）`}>
      <FileDropZone
        label={room === 0 ? `已达 ${limits.maxItems} 个上限` : `点击选择或将${title}拖到此处`}
        hint={`${acceptSummary(limits.accept)} · 单个不超过 ${formatBytes(limits.maxBytes)}${room > 0 ? ` · 还可添加 ${room} 个` : ''}`}
        accept={limits.accept}
        multiple
        disabled={room === 0}
        inputProps={{ 'aria-label': `选择${title}` }}
        onFiles={addFiles}
      />

      {skipped.length > 0 ? (
        <p className="mt-2 text-xs text-amber-700" data-skipped-media>
          已忽略 {skipped.length} 个：{skipped.slice(0, 2).join('、')}
          {skipped.length > 2 ? ' 等' : ''}
        </p>
      ) : null}

      {total === 0 ? <p className="mt-3 text-xs text-slate-500">尚未选择{title}</p> : null}

      {total > 0 ? (
        <ul className={cn('mt-3', isPhotos ? 'grid grid-cols-3 gap-2 sm:grid-cols-4' : 'space-y-1.5')}>
          {retained.map((media) => (
            <li key={`retained-${media.id}`} data-retained-media>
              {isPhotos ? (
                <AttachmentTile
                  name={media.file_name}
                  meta={`${recordId !== null ? `#${media.id} · ` : ''}${formatBytes(media.size)}`}
                  preview={<RetainedPhotoPreview baseUrl={baseUrl} recordId={recordId} media={media} />}
                  onRemove={() => removeRetained(media.id)}
                />
              ) : (
                <AttachmentRow
                  name={media.file_name}
                  meta={`${recordId !== null ? `#${media.id} · ` : ''}${formatBytes(media.size)}`}
                  onRemove={() => removeRetained(media.id)}
                />
              )}
            </li>
          ))}
          {picked.map((file, index) => {
            const warning = file.size > limits.maxBytes ? `超出 ${formatBytes(limits.maxBytes)}` : undefined
            const remove = () => setPicked(picked.filter((_, position) => position !== index))

            return (
              <li key={`${file.name}-${index}`} data-new-media>
                {isPhotos ? (
                  <AttachmentTile
                    name={file.name}
                    meta={formatBytes(file.size)}
                    warning={warning}
                    fresh
                    preview={
                      previews[index] === undefined ? (
                        <ImageIcon className="size-5 text-slate-300" aria-hidden="true" />
                      ) : (
                        <img className="size-full object-cover" src={previews[index]} alt={file.name} />
                      )
                    }
                    onRemove={remove}
                  />
                ) : (
                  <AttachmentRow name={file.name} meta={formatBytes(file.size)} warning={warning} fresh onRemove={remove} />
                )}
              </li>
            )
          })}
        </ul>
      ) : null}

      <FieldError message={error} />
    </Panel>
  )
}

/** Stored photos are private, so the tile pulls its preview through the API. */
function RetainedPhotoPreview({
  baseUrl,
  recordId,
  media,
}: {
  baseUrl?: string
  recordId: number | null
  media: InspectionMedia
}) {
  const source = useMediaObjectUrl(baseUrl, recordId, media)

  if (source === null) {
    return <ImageIcon className="size-5 text-slate-300" aria-hidden="true" />
  }

  return <img className="size-full object-cover" src={source} alt={media.file_name} />
}

function AttachmentTile({
  name,
  meta,
  warning,
  preview,
  fresh = false,
  onRemove,
}: {
  name: string
  meta: string
  warning?: string
  preview: ReactNode
  fresh?: boolean
  onRemove: () => void
}) {
  return (
    <figure
      className={cn(
        'relative overflow-hidden rounded-md border bg-white',
        warning ? 'border-red-300' : fresh ? 'border-emerald-700/30' : 'border-emerald-900/10',
      )}
    >
      <div className="flex aspect-square items-center justify-center overflow-hidden bg-slate-50">{preview}</div>
      <button
        type="button"
        className="absolute right-1 top-1 rounded-full bg-white/90 p-1 text-slate-500 shadow-sm transition-colors hover:text-red-600"
        aria-label={`移除 ${name}`}
        onClick={onRemove}
      >
        <X className="size-3" aria-hidden="true" />
      </button>
      <figcaption className="px-1.5 py-1">
        <p className={cn('truncate text-xs', fresh ? 'text-emerald-800' : 'text-slate-700')} title={name}>
          {name}
        </p>
        <p className="truncate text-[11px] text-slate-400">{meta}</p>
        {warning ? <p className="truncate text-[11px] text-red-600">{warning}</p> : null}
      </figcaption>
    </figure>
  )
}

function AttachmentRow({
  name,
  meta,
  warning,
  fresh = false,
  onRemove,
}: {
  name: string
  meta: string
  warning?: string
  fresh?: boolean
  onRemove: () => void
}) {
  return (
    <div
      className={cn(
        'flex items-center gap-2 rounded-md border bg-white px-2 py-1.5',
        warning ? 'border-red-300' : fresh ? 'border-emerald-700/30' : 'border-emerald-900/10',
      )}
    >
      <FileText className={cn('size-4 shrink-0', fresh ? 'text-emerald-700' : 'text-slate-400')} aria-hidden="true" />
      <span className={cn('min-w-0 flex-1 truncate text-xs', fresh ? 'text-emerald-800' : 'text-slate-700')} title={name}>
        {name}
      </span>
      {warning ? <span className="shrink-0 text-[11px] text-red-600">{warning}</span> : null}
      <span className="shrink-0 text-[11px] text-slate-400">{meta}</span>
      <button
        type="button"
        className="shrink-0 text-slate-400 transition-colors hover:text-red-600"
        aria-label={`移除 ${name}`}
        onClick={onRemove}
      >
        <X className="size-3.5" aria-hidden="true" />
      </button>
    </div>
  )
}
