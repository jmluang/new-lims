import { Download, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { api } from '../../lib/api'
import { Button, Panel } from '../system/shared'
import { formatBytes, inputClass } from '../system/utils'

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
 * Loads one photo through the authenticated endpoint and shows it from an object
 * URL. The URL is revoked when the thumbnail goes away, so switching records does
 * not leak a blob per photo for the lifetime of the tab.
 */
export function MediaThumbnail({ baseUrl, recordId, media }: { baseUrl: string; recordId: number; media: InspectionMedia }) {
  const [source, setSource] = useState<string | null>(null)

  useEffect(() => {
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

/**
 * One attachment collection of the editor: the media the record already carries,
 * which stay unless the operator removes them, plus the files picked in this session.
 */
export function AttachmentPicker<T extends MediaFormState>({
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
  form: T
  error?: string
  onChange: (patch: Partial<T>) => void
}) {
  const limits = inspectionMediaLimits[collection]
  const retained = form.retained_media.filter((media) => media.collection === collection)
  const picked = collection === 'photos' ? form.new_photos : form.new_files

  function setPicked(files: File[]) {
    onChange((collection === 'photos' ? { new_photos: files } : { new_files: files }) as Partial<T>)
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
                  onChange({ retained_media: form.retained_media.filter((entry) => entry.id !== media.id) } as Partial<T>)
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
