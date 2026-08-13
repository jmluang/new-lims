import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit3, FileText, Image as ImageIcon, Plus, Trash2, Upload, X } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { cn } from '../../lib/utils'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, StatusBadge } from '../system/shared'
import { formatBytes, inputClass, textareaClass, type ApiCollection } from '../system/utils'
import { useAuthedObjectUrl, type PdfAsset } from './api'

type AssetKind = 'seal' | 'function_stamp' | 'certificate_template'

type FormState = {
  name: string
  description: string
  language: string
  signature_contact: string
  signature_location: string
  signature_reason: string
  sort_order: string
  is_default: boolean
  is_active: boolean
  file: File | null
}

const emptyForm: FormState = {
  name: '',
  description: '',
  language: 'zh',
  signature_contact: '',
  signature_location: '',
  signature_reason: '',
  sort_order: '0',
  is_default: false,
  is_active: true,
  file: null,
}

/**
 * Shared settings screen for the file-backed signing assets.
 *
 * Seals, perforation stamps, function stamps and declaration pages differ only
 * in which fields they carry, so one screen drives all four rather than four
 * near-identical copies drifting apart.
 */
export function PdfAssetSettingsPage({
  title,
  description,
  path,
  resource,
  kind,
}: {
  title: string
  description: string
  path: string
  resource: string
  kind: AssetKind
}) {
  const queryClient = useQueryClient()
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<PdfAsset | null>(null)
  const [deleting, setDeleting] = useState<PdfAsset | null>(null)
  const [form, setForm] = useState<FormState>(emptyForm)

  const uploadField = kind === 'certificate_template' ? 'template' : 'image'
  const queryKey = ['pdf', 'assets', path] as const

  const assetsQuery = useQuery({
    queryKey,
    queryFn: async () => {
      const response = await api.get<ApiCollection<PdfAsset>>(`/api/pdf/${path}`)
      return response.data.data
    },
  })

  const save = useMutation({
    mutationFn: async () => {
      const payload = new FormData()
      payload.append('name', form.name)
      payload.append('is_default', form.is_default ? '1' : '0')
      payload.append('is_active', form.is_active ? '1' : '0')

      if (kind === 'seal') {
        payload.append('description', form.description)
        payload.append('signature_contact', form.signature_contact)
        payload.append('signature_location', form.signature_location)
        payload.append('signature_reason', form.signature_reason)
      }

      if (kind === 'function_stamp') {
        payload.append('sort_order', form.sort_order || '0')
      }

      if (kind === 'certificate_template') {
        payload.append('language', form.language)
        payload.append('description', form.description)
      }

      if (form.file) {
        payload.append(uploadField, form.file)
      }

      // POST for updates too: PHP does not parse multipart bodies on PUT.
      await api.post(editing ? `/api/pdf/${path}/${editing.id}` : `/api/pdf/${path}`, payload)
    },
    onSuccess: async () => {
      setFormOpen(false)
      setEditing(null)
      setForm(emptyForm)
      await queryClient.invalidateQueries({ queryKey })
      await queryClient.invalidateQueries({ queryKey: ['pdf', 'signing-options'] })
    },
  })

  const remove = useMutation({
    mutationFn: async (asset: PdfAsset) => {
      await api.delete(`/api/pdf/${path}/${asset.id}`)
    },
    onSuccess: async () => {
      setDeleting(null)
      await queryClient.invalidateQueries({ queryKey })
      await queryClient.invalidateQueries({ queryKey: ['pdf', 'signing-options'] })
    },
  })

  function openCreate() {
    setEditing(null)
    setForm(emptyForm)
    save.reset()
    setFormOpen(true)
  }

  function openEdit(asset: PdfAsset) {
    setEditing(asset)
    setForm({
      name: asset.name ?? '',
      description: asset.description ?? '',
      language: asset.language ?? 'zh',
      signature_contact: asset.signature_contact ?? '',
      signature_location: asset.signature_location ?? '',
      signature_reason: asset.signature_reason ?? '',
      sort_order: String(asset.sort_order ?? 0),
      is_default: asset.is_default,
      is_active: asset.is_active,
      file: null,
    })
    save.reset()
    setFormOpen(true)
  }

  const assets = assetsQuery.data ?? []

  return (
    <PageShell
      title={title}
      description={description}
      actions={
        <PermissionGate resource={resource} action="create">
          <Button variant="primary" onClick={openCreate}>
            <Plus className="size-4" aria-hidden="true" />
            新增
          </Button>
        </PermissionGate>
      }
    >
      {assetsQuery.isError ? <ErrorNotice error={assetsQuery.error} fallback="无法加载配置" /> : null}

      {assetsQuery.isPending ? (
        <LoadingState label="正在加载配置" />
      ) : assets.length === 0 ? (
        <EmptyState title="暂无配置" description="点击右上角新增，上传对应的图片或 PDF 模板。" />
      ) : (
        <DataTable>
          <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-3 py-2">{kind === 'certificate_template' ? '模板' : '图样'}</th>
              <th className="px-3 py-2">名称</th>
              {kind === 'seal' ? <th className="px-3 py-2">签名信息</th> : null}
              {kind === 'function_stamp' ? <th className="px-3 py-2">排序</th> : null}
              {kind === 'certificate_template' ? <th className="px-3 py-2">语言</th> : null}
              <th className="px-3 py-2">默认</th>
              <th className="px-3 py-2">状态</th>
              <th className="px-3 py-2 text-right">操作</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {assets.map((asset) => (
              <tr key={asset.id}>
                <td className="px-3 py-2">
                  {kind === 'certificate_template' ? (
                    <span className="text-xs text-slate-500">
                      {asset.file_name ?? '-'}
                      {asset.file_size ? ` · ${formatBytes(asset.file_size)}` : ''}
                    </span>
                  ) : (
                    <AssetThumbnail path={path} id={asset.id} name={asset.name} />
                  )}
                </td>
                <td className="px-3 py-2">
                  <p className="text-slate-900">{asset.name}</p>
                  {asset.description ? <p className="text-xs text-slate-500">{asset.description}</p> : null}
                </td>
                {kind === 'seal' ? (
                  <td className="px-3 py-2 text-xs text-slate-600">
                    {[asset.signature_reason, asset.signature_location, asset.signature_contact].filter(Boolean).join(' · ') || '-'}
                  </td>
                ) : null}
                {kind === 'function_stamp' ? <td className="px-3 py-2 text-slate-700">{asset.sort_order ?? 0}</td> : null}
                {kind === 'certificate_template' ? <td className="px-3 py-2 text-slate-700">{asset.language}</td> : null}
                <td className="px-3 py-2">{asset.is_default ? <StatusBadge status="success" /> : <span className="text-slate-400">-</span>}</td>
                <td className="px-3 py-2">
                  <StatusBadge status={asset.is_active ? 'active' : 'disabled'} />
                </td>
                <td className="px-3 py-2 text-right">
                  <div className="flex justify-end gap-1">
                    <PermissionGate resource={resource} action="update">
                      <Button variant="ghost" onClick={() => openEdit(asset)}>
                        <Edit3 className="size-4" aria-hidden="true" />
                        编辑
                      </Button>
                    </PermissionGate>
                    <PermissionGate resource={resource} action="delete">
                      <Button
                        variant="ghost"
                        className="text-red-600 hover:bg-red-50 hover:text-red-700"
                        onClick={() => {
                          remove.reset()
                          setDeleting(asset)
                        }}
                      >
                        <Trash2 className="size-4" aria-hidden="true" />
                        删除
                      </Button>
                    </PermissionGate>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </DataTable>
      )}

      <Modal
        title={editing ? `编辑${title}` : `新增${title}`}
        description={editing ? '不重新选择文件时，沿用原有文件。' : undefined}
        open={formOpen}
        onClose={() => setFormOpen(false)}
        actions={
          <Button variant="primary" disabled={save.isPending || !form.name || (!editing && !form.file)} onClick={() => save.mutate()}>
            {save.isPending ? '保存中…' : '保存'}
          </Button>
        }
      >
        <div className="space-y-3">
          {save.isError ? <ErrorNotice error={save.error} fallback="保存失败" /> : null}

          <Field label="名称">
            <input className={inputClass} value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} />
          </Field>

          <AssetFileField
            kind={kind}
            file={form.file}
            existing={editing ? { path, id: editing.id, name: editing.name, fileName: editing.file_name } : null}
            onChange={(file) => setForm((current) => ({ ...current, file }))}
          />

          {kind === 'certificate_template' ? (
            <Field label="语言">
              <select className={inputClass} value={form.language} onChange={(event) => setForm((current) => ({ ...current, language: event.target.value }))}>
                <option value="zh">中文</option>
                <option value="en">English</option>
              </select>
            </Field>
          ) : null}

          {kind === 'function_stamp' ? (
            <Field label="排序（数字越小越靠前）">
              <input
                className={inputClass}
                type="number"
                min={0}
                value={form.sort_order}
                onChange={(event) => setForm((current) => ({ ...current, sort_order: event.target.value }))}
              />
            </Field>
          ) : null}

          {kind === 'seal' ? (
            <>
              <div className="grid gap-3 sm:grid-cols-3">
                <Field label="签名原因">
                  <input
                    className={inputClass}
                    value={form.signature_reason}
                    onChange={(event) => setForm((current) => ({ ...current, signature_reason: event.target.value }))}
                  />
                </Field>
                <Field label="签名地点">
                  <input
                    className={inputClass}
                    value={form.signature_location}
                    onChange={(event) => setForm((current) => ({ ...current, signature_location: event.target.value }))}
                  />
                </Field>
                <Field label="签名联系人">
                  <input
                    className={inputClass}
                    value={form.signature_contact}
                    onChange={(event) => setForm((current) => ({ ...current, signature_contact: event.target.value }))}
                  />
                </Field>
              </div>
            </>
          ) : null}

          {kind !== 'function_stamp' ? (
            <Field label="说明">
              <textarea
                className={textareaClass}
                value={form.description}
                onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))}
              />
            </Field>
          ) : null}

          <div className="flex flex-wrap gap-4">
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="size-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"
                checked={form.is_default}
                onChange={(event) => setForm((current) => ({ ...current, is_default: event.target.checked }))}
              />
              设为默认
            </label>
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="size-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"
                checked={form.is_active}
                onChange={(event) => setForm((current) => ({ ...current, is_active: event.target.checked }))}
              />
              启用
            </label>
          </div>
        </div>
      </Modal>

      {/*
        The confirm action lives at the bottom of the body, not in the header
        `actions` slot: a destructive button must sit after the text explaining
        it, and it needs an explicit 取消 next to it.
      */}
      <Modal title={`删除${title}`} open={deleting !== null} onClose={() => setDeleting(null)}>
        <div className="space-y-4">
          {remove.isError ? <ErrorNotice error={remove.error} fallback="删除失败" /> : null}

          <p className="text-sm leading-6 text-slate-700">
            确定要删除「{deleting?.name}」吗？配置与已上传的文件都会被永久移除，此操作不可撤销。
          </p>

          <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm leading-6 text-amber-900">
            已签发的文件不受影响，审计日志会保留这次删除的记录。若只是想暂时停用，请改用编辑里的「启用」开关。
          </div>

          <div className="flex justify-end gap-2">
            <Button variant="secondary" disabled={remove.isPending} onClick={() => setDeleting(null)}>
              取消
            </Button>
            <Button
              variant="danger"
              disabled={remove.isPending}
              onClick={() => {
                if (deleting) {
                  remove.mutate(deleting)
                }
              }}
            >
              {remove.isPending ? '删除中…' : '确认删除'}
            </Button>
          </div>
        </div>
      </Modal>
    </PageShell>
  )
}

function AssetThumbnail({ path, id, name }: { path: string; id: number; name: string }) {
  const url = useAuthedObjectUrl(`/api/pdf/${path}/${id}/file`)

  return url ? (
    <img className="size-12 object-contain" src={url} alt={name} />
  ) : (
    <div className="size-12 rounded bg-slate-100" aria-hidden="true" />
  )
}

/**
 * Upload control for a seal image or a declaration-page PDF.
 *
 * The native file input is avoided deliberately: it renders an untranslated
 * button and the stored UUID filename, and gives no preview — with seals, being
 * able to see the picked image is the whole point of the check.
 */
function AssetFileField({
  kind,
  file,
  existing,
  onChange,
}: {
  kind: AssetKind
  file: File | null
  existing: { path: string; id: number; name: string; fileName?: string | null } | null
  onChange: (file: File | null) => void
}) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [dragging, setDragging] = useState(false)
  const isImage = kind !== 'certificate_template'
  const previewUrl = useObjectUrl(isImage ? file : null)
  const existingUrl = useAuthedObjectUrl(isImage && existing && !file ? `/api/pdf/${existing.path}/${existing.id}/file` : null)
  const shownUrl = previewUrl ?? existingUrl

  const label = isImage ? '图片（PNG / JPG，建议透明底）' : '模板 PDF'
  const accept = isImage ? 'image/png,image/jpeg' : 'application/pdf,.pdf'

  return (
    <Field label={label}>
      <div
        className={cn(
          'flex items-center gap-3 rounded-md border border-dashed border-emerald-900/25 bg-emerald-50/30 p-3 transition-colors',
          dragging && 'border-emerald-600 bg-emerald-50',
        )}
        onDragOver={(event) => {
          event.preventDefault()
          setDragging(true)
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={(event) => {
          event.preventDefault()
          setDragging(false)
          onChange(event.dataTransfer.files?.[0] ?? null)
        }}
      >
        {isImage ? (
          shownUrl ? (
            <img className="size-16 shrink-0 rounded border border-emerald-900/10 bg-white object-contain p-1" src={shownUrl} alt="" />
          ) : (
            <div className="flex size-16 shrink-0 items-center justify-center rounded border border-emerald-900/10 bg-white">
              <ImageIcon className="size-5 text-slate-300" aria-hidden="true" />
            </div>
          )
        ) : (
          <div className="flex size-16 shrink-0 items-center justify-center rounded border border-emerald-900/10 bg-white">
            <FileText className="size-5 text-slate-300" aria-hidden="true" />
          </div>
        )}

        <div className="min-w-0 flex-1">
          <p className="truncate text-sm text-slate-900">
            {file ? file.name : existing ? (isImage ? `当前${existing.name}图样` : (existing.fileName ?? '当前模板')) : '尚未选择文件'}
          </p>
          <p className="mt-0.5 text-xs text-slate-500">
            {file ? formatBytes(file.size) : existing ? '不重新选择则沿用原文件' : '点击选择或将文件拖到此处'}
          </p>
        </div>

        <div className="flex shrink-0 gap-1">
          <Button variant="secondary" onClick={() => inputRef.current?.click()}>
            <Upload className="size-4" aria-hidden="true" />
            {file || existing ? '重新选择' : '选择文件'}
          </Button>
          {file ? (
            <Button
              variant="ghost"
              aria-label="清除已选文件"
              onClick={() => {
                onChange(null)

                if (inputRef.current) {
                  inputRef.current.value = ''
                }
              }}
            >
              <X className="size-4" aria-hidden="true" />
            </Button>
          ) : null}
        </div>

        <input
          className="hidden"
          ref={inputRef}
          type="file"
          accept={accept}
          onChange={(event) => onChange(event.target.files?.[0] ?? null)}
        />
      </div>
    </Field>
  )
}

/** Blob URL for a locally picked file, revoked when it changes. */
function useObjectUrl(file: File | null) {
  const url = useMemo(() => (file ? URL.createObjectURL(file) : null), [file])

  useEffect(() => {
    if (!url) {
      return
    }

    return () => URL.revokeObjectURL(url)
  }, [url])

  return url
}
