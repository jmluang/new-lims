import { AlertCircle, CheckCircle2, ChevronDown, ChevronRight, FileText, Loader2, RotateCcw, Trash2, Upload, XCircle } from 'lucide-react'
import { useRef, useState } from 'react'
import { api } from '../../lib/api'
import { cn } from '../../lib/utils'
import { Button, ErrorNotice, PageShell, Panel } from '../system/shared'
import { formatBytes } from '../system/utils'
import { securityLevelLabel, type VerificationResultData } from './api'
import { calculateFileDigests } from './digest'
import { VerificationResultCard } from './VerificationResultCard'
import { statusLabels, useVerificationQueue, type QueuedVerification } from './verificationQueue'

const coverFieldLabels: Record<string, string> = {
  report_number: '报告编号',
  product_name: '产品名称',
  model_specification: '规格型号',
  entrust_company: '委托单位',
}

const extractionStatusLabels: Record<string, string> = {
  success: '提取完整',
  partial: '部分提取',
  failed: '未能提取',
  not_available: '无封面信息',
}

/**
 * 文件验证 — recomputes each report's digests in the browser and asks the API
 * whether they match a signed record.
 *
 * The files never leave the operator's machine on this screen; only the digests
 * are posted, which is what makes checking a stack of large reports practical.
 */
export function PdfVerifyPage() {
  const inputRef = useRef<HTMLInputElement>(null)
  const [dragging, setDragging] = useState(false)

  const queue = useVerificationQueue({
    verifyFile: async (file, onStep) => {
      onStep('计算摘要')
      const digests = await calculateFileDigests(file)

      onStep('比对签发台账')
      const response = await api.post<{ data: VerificationResultData }>('/api/pdf/verification/verify', {
        file_name: file.name,
        file_size: file.size,
        current_digests: digests,
      })

      return response.data.data
    },
  })

  const { items, processing, notice, stats } = queue

  return (
    <PageShell
      title="文件验证"
      description="上传 PDF 报告，系统会在浏览器内计算摘要并与签发台账比对，判断文件是否被修改。支持一次验证多个文件。"
      actions={
        items.length > 0 ? (
          <div className="flex gap-2">
            {stats.hasPending ? (
              <Button variant="primary" disabled={processing} onClick={() => void queue.start()}>
                {processing ? '验证中…' : '开始验证'}
              </Button>
            ) : null}
            <Button variant="secondary" disabled={processing} onClick={queue.clear}>
              清空所有
            </Button>
          </div>
        ) : undefined
      }
    >
      {notice ? <ErrorNotice error={new Error(notice)} /> : null}

      <Panel title="选择待验证文件" description="文件不会上传，只有摘要会发送到服务器。">
        <div
          className={cn(
            'cursor-pointer rounded-lg border-2 border-dashed border-emerald-300 bg-emerald-50/40 p-8 text-center transition-colors hover:bg-emerald-50',
            dragging && 'border-emerald-600 bg-emerald-50',
            processing && 'pointer-events-none opacity-60',
          )}
          role="button"
          tabIndex={0}
          onClick={() => inputRef.current?.click()}
          onKeyDown={(event) => {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault()
              inputRef.current?.click()
            }
          }}
          onDragOver={(event) => {
            event.preventDefault()
            setDragging(true)
          }}
          onDragLeave={() => setDragging(false)}
          onDrop={(event) => {
            event.preventDefault()
            setDragging(false)
            queue.addFiles(event.dataTransfer.files)
          }}
        >
          <Upload className="mx-auto size-7 text-slate-400" aria-hidden="true" />
          <p className="mt-2 text-sm font-medium text-slate-900">
            {items.length > 0 ? '继续添加 PDF 文件' : '点击选择或拖拽 PDF 文件'}
          </p>
          <p className="mt-1 text-xs text-slate-500">支持一次选择多个文件，大文件的摘要计算需要几秒钟</p>
          <input
            className="hidden"
            ref={inputRef}
            type="file"
            accept="application/pdf,.pdf"
            multiple
            onChange={(event) => {
              queue.addFiles(event.target.files)
              event.target.value = ''
            }}
          />
        </div>

        {processing ? (
          <div className="mt-4">
            <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
              <div className="h-full bg-emerald-600 transition-[width]" style={{ width: `${stats.progress}%` }} />
            </div>
            <p className="mt-2 text-center text-xs text-slate-500">
              整体进度：{stats.processed} / {stats.total}
            </p>
          </div>
        ) : null}
      </Panel>

      {items.length > 0 ? (
        <Panel
          title={`文件列表（${items.length}）`}
          description={
            stats.processed === 0
              ? '点击右上角开始验证。'
              : `已验证 ${stats.processed} 个：通过 ${stats.valid} 个，未通过 ${stats.invalid} 个${stats.failed > 0 ? `，出错 ${stats.failed} 个` : ''}。`
          }
        >
          <ul className="space-y-2">
            {items.map((item) => (
              <VerificationRow
                key={item.key}
                item={item}
                processing={processing}
                onRemove={() => queue.remove(item.key)}
                onRetry={() => void queue.retry(item)}
                onToggle={() => queue.toggleExpanded(item.key)}
              />
            ))}
          </ul>
        </Panel>
      ) : null}
    </PageShell>
  )
}

function VerificationRow({
  item,
  processing,
  onRemove,
  onRetry,
  onToggle,
}: {
  item: QueuedVerification
  processing: boolean
  onRemove: () => void
  onRetry: () => void
  onToggle: () => void
}) {
  const valid = item.result?.overall_valid
  const coverFields = item.result?.cover_fields ?? null
  const extractionStatus = coverFields?.extraction_status ?? null

  return (
    <li
      className={cn(
        'rounded-md border p-3',
        item.status === 'error' && 'border-amber-200 bg-amber-50',
        item.status === 'completed' && valid && 'border-emerald-200 bg-emerald-50',
        item.status === 'completed' && !valid && 'border-red-200 bg-red-50',
        (item.status === 'pending' || item.status === 'processing') && 'border-emerald-900/10 bg-white',
      )}
    >
      <div className="flex flex-wrap items-center gap-3 text-sm">
        <StatusIcon item={item} />

        <div className="min-w-0 flex-1">
          <p className="truncate font-medium text-slate-900">{item.file.name}</p>
          <p className="text-xs text-slate-500">
            {formatBytes(item.file.size)} · {item.status === 'processing' ? item.step || statusLabels.processing : statusLabels[item.status]}
          </p>
        </div>

        {item.status === 'completed' && item.result ? (
          <span
            className={cn(
              'shrink-0 rounded-md border px-2 py-0.5 text-xs font-medium',
              valid ? 'border-emerald-200 bg-white text-emerald-700' : 'border-red-200 bg-white text-red-700',
            )}
          >
            {valid ? '验证通过' : '验证失败'} · 安全等级 {securityLevelLabel(item.result.security_level)}
          </span>
        ) : null}

        <div className="flex shrink-0 gap-1">
          {item.status === 'error' ? (
            <Button variant="ghost" disabled={processing} onClick={onRetry}>
              <RotateCcw className="size-4" aria-hidden="true" />
              重试
            </Button>
          ) : null}
          {item.status !== 'processing' ? (
            <Button variant="ghost" aria-label={`移除 ${item.file.name}`} disabled={processing} onClick={onRemove}>
              <Trash2 className="size-4" aria-hidden="true" />
            </Button>
          ) : null}
        </div>
      </div>

      {item.status === 'error' && item.error ? <p className="mt-2 text-sm text-amber-800">{item.error}</p> : null}

      {item.status === 'completed' && item.result ? (
        <>
          {coverFields ? (
            <div className="mt-3 rounded-md border border-emerald-900/10 bg-white p-3">
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-medium text-slate-600">封面信息</span>
                {extractionStatus ? (
                  <span
                    className={cn(
                      'rounded px-1.5 py-0.5 text-xs',
                      extractionStatus === 'success' && 'bg-emerald-50 text-emerald-700',
                      extractionStatus === 'partial' && 'bg-amber-50 text-amber-700',
                      (extractionStatus === 'failed' || extractionStatus === 'not_available') && 'bg-slate-100 text-slate-500',
                    )}
                  >
                    {extractionStatusLabels[extractionStatus] ?? extractionStatus}
                  </span>
                ) : null}
              </div>
              <dl className="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                {Object.entries(coverFieldLabels).map(([key, label]) => (
                  <div className="flex gap-2 text-xs" key={key}>
                    <dt className="w-16 shrink-0 text-slate-500">{label}</dt>
                    <dd className="min-w-0 break-words text-slate-800">{coverFields[key] || '—'}</dd>
                  </div>
                ))}
              </dl>
            </div>
          ) : null}

          <button
            type="button"
            className="mt-2 flex items-center gap-1 text-xs font-medium text-slate-600 hover:text-emerald-700"
            onClick={onToggle}
            aria-expanded={item.expanded}
          >
            {item.expanded ? <ChevronDown className="size-4" aria-hidden="true" /> : <ChevronRight className="size-4" aria-hidden="true" />}
            验证结果详情
          </button>

          {item.expanded ? (
            <div className="mt-2 rounded-md border border-emerald-900/10 bg-white p-3">
              <VerificationResultCard result={item.result} />
            </div>
          ) : null}
        </>
      ) : null}
    </li>
  )
}

function StatusIcon({ item }: { item: QueuedVerification }) {
  if (item.status === 'processing') {
    return <Loader2 className="size-5 shrink-0 animate-spin text-emerald-600" aria-hidden="true" />
  }

  if (item.status === 'error') {
    return <AlertCircle className="size-5 shrink-0 text-amber-600" aria-hidden="true" />
  }

  if (item.status === 'completed') {
    return item.result?.overall_valid ? (
      <CheckCircle2 className="size-5 shrink-0 text-emerald-600" aria-hidden="true" />
    ) : (
      <XCircle className="size-5 shrink-0 text-red-600" aria-hidden="true" />
    )
  }

  return <FileText className="size-5 shrink-0 text-slate-400" aria-hidden="true" />
}
