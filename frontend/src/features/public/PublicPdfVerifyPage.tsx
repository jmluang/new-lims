import { AlertCircle, CheckCircle2, ChevronDown, ChevronRight, FileText, Loader2, RotateCcw, ShieldCheck, Trash2, Upload, XCircle } from 'lucide-react'
import { useRef, useState } from 'react'
import { api } from '../../lib/api'
import { cn } from '../../lib/utils'
import { securityLevelLabel, type VerificationResultData } from '../pdf/api'
import { VerificationResultCard } from '../pdf/VerificationResultCard'
import { statusLabels, useVerificationQueue, type QueuedVerification } from '../pdf/verificationQueue'
import { Button, ErrorNotice } from '../system/shared'
import { formatBytes, formatDateTime } from '../system/utils'

/** Kept in step with the zs-lims page so a recipient cannot batch-probe the ledger. */
const MAX_FILES = 10
const MAX_BYTES = 20 * 1024 * 1024

const features = [
  ['多重摘要', 'SHA-256、SHA3-256、MD5、CRC32 同时比对，任何一处改动都会暴露'],
  ['数字签名', '报告内嵌 PKCS#7 数字签名与骑缝章，缺页、换页同样验不过'],
  ['签发台账', '与出具机构的签发记录交叉核对，不只是看文件本身'],
  ['即时判定', '上传即出结果，无需注册或登录'],
]

/**
 * 报告真伪核验 — the unauthenticated counterpart to the internal verification
 * screen, for whoever received the report.
 *
 * Files are uploaded here rather than digested in the browser: an outside
 * caller has no reason to be trusted with computing the digests it is then
 * checked against.
 */
export function PublicPdfVerifyPage() {
  const inputRef = useRef<HTMLInputElement>(null)
  const [dragging, setDragging] = useState(false)

  const queue = useVerificationQueue({
    maxFiles: MAX_FILES,
    maxBytes: MAX_BYTES,
    verifyFile: async (file, onStep) => {
      onStep('上传并核验')
      const form = new FormData()
      form.append('pdf_file', file)

      const response = await api.post<{ data: VerificationResultData }>('/api/public/pdf/verify', form)

      return response.data.data
    },
  })

  const { items, processing, notice, stats } = queue

  return (
    <main className="min-h-screen bg-gradient-to-b from-emerald-50 via-white to-white px-4 py-8 text-slate-900 sm:px-6 lg:px-8">
      <div className="mx-auto max-w-3xl">
        <header className="text-center">
          <div className="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
            <ShieldCheck className="size-7" aria-hidden="true" />
          </div>
          <h1 className="mt-4 text-2xl font-semibold">检测报告真伪核验</h1>
          <p className="mt-2 text-sm leading-6 text-slate-600">
            上传收到的 PDF 报告，系统会比对报告指纹与出具机构的签发记录，判断文件是否为本机构签发且未被修改。
          </p>
        </header>

        <section className="mt-6 rounded-xl border border-emerald-900/10 bg-white p-5 shadow-sm">
          <h2 className="text-sm font-semibold text-slate-900">核验依据</h2>
          <dl className="mt-3 grid gap-3 sm:grid-cols-2">
            {features.map(([title, detail]) => (
              <div className="rounded-md bg-slate-50 p-3" key={title}>
                <dt className="text-sm font-medium text-slate-800">{title}</dt>
                <dd className="mt-0.5 text-xs leading-5 text-slate-600">{detail}</dd>
              </div>
            ))}
          </dl>
        </section>

        <section className="mt-4 rounded-xl border border-emerald-900/10 bg-white p-5 shadow-sm">
          {notice ? <ErrorNotice error={new Error(notice)} /> : null}

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
              {items.length > 0 ? '继续添加 PDF 报告' : '点击选择或拖拽 PDF 报告'}
            </p>
            <p className="mt-1 text-xs text-slate-500">
              支持 PDF 格式，单个最大 {formatBytes(MAX_BYTES)}，一次最多 {MAX_FILES} 个
            </p>
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

          {items.length > 0 ? (
            <div className="mt-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold text-slate-900">
                  文件列表（{items.length} / {MAX_FILES}）
                </h2>
                <div className="flex gap-2">
                  {stats.hasPending ? (
                    <Button variant="primary" disabled={processing} onClick={() => void queue.start()}>
                      {processing ? '核验中…' : '开始核验'}
                    </Button>
                  ) : null}
                  <Button variant="secondary" disabled={processing} onClick={queue.clear}>
                    清空所有
                  </Button>
                </div>
              </div>

              {processing ? (
                <div className="mt-3">
                  <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
                    <div className="h-full bg-emerald-600 transition-[width]" style={{ width: `${stats.progress}%` }} />
                  </div>
                  <p className="mt-1.5 text-center text-xs text-slate-500">
                    整体进度：{stats.processed} / {stats.total}
                  </p>
                </div>
              ) : null}

              <ul className="mt-3 space-y-2">
                {items.map((item) => (
                  <PublicVerificationRow
                    key={item.key}
                    item={item}
                    processing={processing}
                    onRemove={() => queue.remove(item.key)}
                    onRetry={() => void queue.retry(item)}
                    onToggle={() => queue.toggleExpanded(item.key)}
                  />
                ))}
              </ul>
            </div>
          ) : null}
        </section>

        <p className="mt-4 text-center text-xs text-slate-500">
          核验结果仅说明文件与签发记录是否一致，不代表对报告内容的解释。如有疑问请联系出具报告的实验室。
        </p>
      </div>
    </main>
  )
}

function PublicVerificationRow({
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
        {item.status === 'processing' ? (
          <Loader2 className="size-5 shrink-0 animate-spin text-emerald-600" aria-hidden="true" />
        ) : item.status === 'error' ? (
          <AlertCircle className="size-5 shrink-0 text-amber-600" aria-hidden="true" />
        ) : item.status === 'completed' ? (
          valid ? (
            <CheckCircle2 className="size-5 shrink-0 text-emerald-600" aria-hidden="true" />
          ) : (
            <XCircle className="size-5 shrink-0 text-red-600" aria-hidden="true" />
          )
        ) : (
          <FileText className="size-5 shrink-0 text-slate-400" aria-hidden="true" />
        )}

        <div className="min-w-0 flex-1">
          <p className="truncate font-medium text-slate-900">{item.file.name}</p>
          <p className="text-xs text-slate-500">
            {formatBytes(item.file.size)} · {item.status === 'processing' ? item.step || statusLabels.processing : statusLabels[item.status]}
          </p>
        </div>

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
          <div className="mt-2 rounded-md border border-emerald-900/10 bg-white p-3">
            <p className={cn('text-sm font-semibold', valid ? 'text-emerald-800' : 'text-red-800')}>
              {valid ? '验证通过：文件与签发记录一致' : '验证失败：文件与签发记录不一致'}
            </p>
            <dl className="mt-2 space-y-1 text-xs">
              <div className="flex gap-2">
                <dt className="w-16 shrink-0 text-slate-500">核验时间</dt>
                <dd className="text-slate-800">{formatDateTime(item.result.verified_at)}</dd>
              </div>
              <div className="flex gap-2">
                <dt className="w-16 shrink-0 text-slate-500">安全等级</dt>
                <dd className="text-slate-800">{securityLevelLabel(item.result.security_level)}</dd>
              </div>
              {item.result.cover_report_number ? (
                <div className="flex gap-2">
                  <dt className="w-16 shrink-0 text-slate-500">报告编号</dt>
                  <dd className="text-slate-800">{item.result.cover_report_number}</dd>
                </div>
              ) : null}
            </dl>
          </div>

          <button
            type="button"
            className="mt-2 flex items-center gap-1 text-xs font-medium text-slate-600 hover:text-emerald-700"
            onClick={onToggle}
            aria-expanded={item.expanded}
          >
            {item.expanded ? <ChevronDown className="size-4" aria-hidden="true" /> : <ChevronRight className="size-4" aria-hidden="true" />}
            核验详情
          </button>

          {item.expanded ? (
            <div className="mt-2 rounded-md border border-emerald-900/10 bg-white p-3">
              <VerificationResultCard result={item.result} compact />
            </div>
          ) : null}
        </>
      ) : null}
    </li>
  )
}
