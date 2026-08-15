import { useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, CheckCircle2, Download, FileText, Loader2, Trash2, Upload, XCircle } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api } from '../../lib/api'
import { cn } from '../../lib/utils'
import { Button, ErrorNotice, LoadingState, PageShell, Panel } from '../system/shared'
import { errorMessage, formatBytes, inputClass } from '../system/utils'
import {
  assetFileUrl,
  decodeHeaderValue,
  digestLabels,
  reportNumberFromFileName,
  useAuthedObjectUrl,
  useSigningOptions,
  type CertificateTemplate,
  type FunctionStampOption,
  type SealOption,
} from './api'
import { mergeCertificateTemplate } from './certificateMerge'

/** How many files are signed at once; matches the zs-lims signing desk. */
const MAX_CONCURRENT_TASKS = 3

/** Seconds the results stay on screen before the desk clears itself. */
const AUTO_RESET_SECONDS = 5

const languageNames: Record<string, string> = { zh: '中文', en: 'English' }

type QueuedFile = {
  key: string
  file: File
  removePhotometric: boolean
  /** Pre-filled from the file name, confirmed or corrected by the operator. */
  reportNumber: string
}

type SignResult = {
  key: string
  originalName: string
  downloadName?: string
  blobUrl?: string
  sha256?: string | null
  fileSize?: number | null
  reportNumber?: string | null
  error?: string
}

type TaskStage = 'merge' | 'upload' | 'signing' | 'done' | 'error'

type TaskState = {
  key: string
  index: number
  fileName: string
  progress: number
  status: string
  stage: TaskStage
  /** Wall-clock start, used to show that a silent stage is still alive. */
  startedAt: number
  /** Upload size, used to state what a normal duration looks like. */
  bytes: number
  /** Set once the request is with the server and no progress events arrive. */
  signingSince?: number
}

/**
 * Rough server-side signing time, measured on production: a 13-page, 6 MB
 * report signs in about 5s, and the cost tracks pages times bytes. Used only
 * to set expectations and to decide when a run is worth flagging as unusual —
 * never to fake progress.
 */
function expectedSigningSeconds(bytes: number) {
  return Math.max(3, Math.round((bytes / 1048576) * 0.8))
}

type SigningConfig = {
  certificateId: number | null
  digitalSignatureId: number | null
  perforationStampId: number | null
  functionStampIds: number[]
  enableSignature: boolean
  enablePerforation: boolean
}

export function PdfSigningPage() {
  const queryClient = useQueryClient()
  const optionsQuery = useSigningOptions()
  const options = optionsQuery.data

  const [queue, setQueue] = useState<QueuedFile[]>([])
  const [results, setResults] = useState<SignResult[]>([])
  const [tasks, setTasks] = useState<TaskState[]>([])
  const [processedCount, setProcessedCount] = useState(0)
  const [totalCount, setTotalCount] = useState(0)
  const [signing, setSigning] = useState(false)
  const [autoStart, setAutoStart] = useState(false)
  const [countdown, setCountdown] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [config, setConfig] = useState<SigningConfig | null>(null)

  const normalInputRef = useRef<HTMLInputElement>(null)
  const photometricInputRef = useRef<HTMLInputElement>(null)
  // Read by addFiles so "上传后马上开始" sees the files it just queued.
  const pendingAutoStart = useRef(false)

  // Seed the panel from the configured defaults once the options land.
  const effectiveConfig = useMemo<SigningConfig>(() => {
    if (config) {
      return config
    }

    const data = options?.data

    return {
      certificateId: null,
      digitalSignatureId: data?.digital_signatures.find((item) => item.is_default)?.id ?? null,
      perforationStampId: data?.perforation_stamps.find((item) => item.is_default)?.id ?? null,
      functionStampIds: data?.function_stamps.filter((item) => item.is_default).map((item) => item.id) ?? [],
      enableSignature: true,
      enablePerforation: true,
    }
  }, [config, options])

  const update = useCallback(
    (patch: Partial<SigningConfig>) => setConfig((current) => ({ ...(current ?? effectiveConfig), ...patch })),
    [effectiveConfig],
  )

  const maxUploadBytes = (options?.meta.max_upload_kb ?? 20480) * 1024
  const signingEnabled = options?.meta.signing_enabled ?? false
  const photometricEnabled = options?.meta.photometric_removal_enabled ?? false

  const configReady =
    signingEnabled &&
    (!effectiveConfig.enableSignature || effectiveConfig.digitalSignatureId !== null) &&
    (!effectiveConfig.enablePerforation || effectiveConfig.perforationStampId !== null)
  const canSign = !signing && configReady && queue.length > 0

  const overallProgress = totalCount === 0 ? 0 : (processedCount / totalCount) * 100

  function addFiles(fileList: FileList | null, removePhotometric: boolean) {
    if (!fileList) {
      return
    }

    const rejected: string[] = []
    const accepted: QueuedFile[] = []

    Array.from(fileList).forEach((file) => {
      if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
        rejected.push(`${file.name}（不是 PDF）`)
        return
      }

      if (file.size > maxUploadBytes) {
        rejected.push(`${file.name}（超过 ${formatBytes(maxUploadBytes)}）`)
        return
      }

      accepted.push({
        key: `${file.name}-${file.size}-${Date.now()}-${Math.random()}`,
        file,
        removePhotometric,
        reportNumber: reportNumberFromFileName(file.name),
      })
    })

    setError(rejected.length > 0 ? `已跳过 ${rejected.length} 个文件：${rejected.join('，')}` : null)

    if (accepted.length === 0) {
      return
    }

    setQueue((current) => [...current, ...accepted])

    if (autoStart && configReady && !signing) {
      pendingAutoStart.current = true
    }
  }

  // Auto-start runs after the queue state has landed, so the batch includes
  // every file that was just dropped.
  useEffect(() => {
    if (!pendingAutoStart.current || queue.length === 0 || signing) {
      return
    }

    pendingAutoStart.current = false
    void startSigning()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [queue, signing])

  async function signOne(item: QueuedFile, index: number) {
    const setTask = (patch: Partial<TaskState>) =>
      setTasks((current) => current.map((task) => (task.key === item.key ? { ...task, ...patch } : task)))

    setTasks((current) => [
      ...current,
      {
        key: item.key,
        index,
        fileName: item.file.name,
        progress: 0,
        status: '准备中',
        stage: 'merge',
        startedAt: Date.now(),
        bytes: item.file.size,
      },
    ])

    try {
      let payload: Blob = item.file

      if (effectiveConfig.certificateId) {
        setTask({ progress: 10, status: '合并声明页…', stage: 'merge' })
        payload = await mergeCertificateTemplate(item.file, effectiveConfig.certificateId)
        setTask({ progress: 50, status: '声明页合并完成', stage: 'merge' })
      } else {
        setTask({ progress: 50, status: '无需合并声明页', stage: 'upload' })
      }

      setTask({ progress: 50, status: '上传并签章…', stage: 'upload' })

      const form = new FormData()
      form.append('pdf_file', payload, item.file.name)
      form.append('original_name', item.file.name)

      if (item.reportNumber.trim()) {
        form.append('report_number', item.reportNumber.trim())
      }

      if (effectiveConfig.certificateId) {
        form.append('certificate_id', String(effectiveConfig.certificateId))
      }

      if (effectiveConfig.enableSignature && effectiveConfig.digitalSignatureId) {
        form.append('digital_signature_id', String(effectiveConfig.digitalSignatureId))
      }

      if (effectiveConfig.enablePerforation && effectiveConfig.perforationStampId) {
        form.append('perforation_stamp_id', String(effectiveConfig.perforationStampId))
      }

      effectiveConfig.functionStampIds.forEach((id, position) => {
        form.append(`function_stamp_ids[${position}]`, String(id))
      })

      if (item.removePhotometric) {
        form.append('remove_photometric_content', '1')
      }

      const response = await api.post<Blob>('/api/pdf/signing/process', form, {
        responseType: 'blob',
        onUploadProgress: (event) => {
          if (!event.total) {
            return
          }

          const uploaded = (event.loaded / event.total) * 100

          // Once the bytes are all sent, no further events arrive until the
          // signed file comes back. Switch to a stage that reports elapsed
          // time instead of leaving a bar frozen near the end, which is what
          // makes a working job look hung.
          if (uploaded >= 100) {
            setTask({ progress: 95, status: '服务端签章中', stage: 'signing', signingSince: Date.now() })

            return
          }

          setTask({ progress: 50 + uploaded * 0.45, status: `上传中 ${Math.round(uploaded)}%`, stage: 'upload' })
        },
      })

      const disposition = response.headers['content-disposition'] as string | undefined
      const downloadName = parseFileName(disposition) ?? `${stripExtension(item.file.name)}-正本.pdf`
      const blobUrl = URL.createObjectURL(response.data)

      setTask({ progress: 100, status: '完成', stage: 'done' })

      // Mirror the zs-lims desk: a finished file downloads straight away so a
      // large batch does not need one click per report.
      triggerDownload(blobUrl, downloadName)

      return {
        key: item.key,
        originalName: item.file.name,
        downloadName,
        blobUrl,
        sha256: (response.headers['x-final-file-hash'] as string | undefined) ?? null,
        fileSize: Number(response.headers['x-final-file-size'] ?? 0) || null,
        reportNumber: decodeHeaderValue(response.headers['x-cover-report-number'] as string | undefined),
      } satisfies SignResult
    } catch (caught) {
      // A blob responseType turns error bodies into blobs, so read the JSON back.
      const message = await readBlobError(caught)
      setTask({ progress: 100, status: message, stage: 'error' })

      return { key: item.key, originalName: item.file.name, error: message } satisfies SignResult
    }
  }

  async function startSigning() {
    if (signing || !configReady || queue.length === 0) {
      return
    }

    setSigning(true)
    setError(null)
    setResults([])
    setTasks([])
    setCountdown(null)

    const pending = queue.map((item, index) => ({ item, index: index + 1 }))
    setTotalCount(pending.length)
    setProcessedCount(0)

    const collected: SignResult[] = []

    // Bounded concurrency: workers pull from a shared queue so a slow file
    // never stalls the rest of the batch.
    const worker = async () => {
      for (;;) {
        const next = pending.shift()

        if (!next) {
          return
        }

        const result = await signOne(next.item, next.index)
        collected.push(result)
        setResults([...collected])
        setProcessedCount(collected.length)

        // Drop the finished card after a beat so its final state is visible.
        setTimeout(() => setTasks((current) => current.filter((task) => task.key !== next.item.key)), 900)
      }
    }

    await Promise.all(Array.from({ length: Math.min(MAX_CONCURRENT_TASKS, pending.length) }, worker))

    setSigning(false)
    setQueue([])
    setCountdown(AUTO_RESET_SECONDS)
    await queryClient.invalidateQueries({ queryKey: ['pdf', 'files'] })
  }

  // Auto-reset countdown after a finished batch.
  useEffect(() => {
    if (countdown === null) {
      return
    }

    if (countdown <= 0) {
      reset()
      return
    }

    const timer = setTimeout(() => setCountdown((current) => (current === null ? null : current - 1)), 1000)

    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [countdown])

  function reset() {
    results.forEach((result) => {
      if (result.blobUrl) {
        URL.revokeObjectURL(result.blobUrl)
      }
    })
    setResults([])
    setQueue([])
    setTasks([])
    setProcessedCount(0)
    setTotalCount(0)
    setCountdown(null)
    setError(null)
  }

  if (optionsQuery.isPending) {
    return <LoadingState label="正在加载签章配置" />
  }

  if (optionsQuery.isError) {
    return <ErrorNotice error={optionsQuery.error} fallback="无法加载签章配置" />
  }

  const successCount = results.filter((result) => !result.error).length
  const failureCount = results.length - successCount

  return (
    <PageShell
      title="PDF 签章"
      description="上传检测报告，套用声明页与印章并写入数字签名，同时把文件摘要登记到防篡改台账。"
      actions={
        results.length > 0 && !signing ? (
          <Button variant="secondary" onClick={reset}>
            处理新文件
          </Button>
        ) : (
          <Button variant="primary" disabled={!canSign} onClick={() => void startSigning()}>
            {signing ? '处理中…' : `开始处理${queue.length > 0 ? `（${queue.length}）` : ''}`}
          </Button>
        )
      }
    >
      {signing ? (
        <ProcessingOverlay
          processedCount={processedCount}
          totalCount={totalCount}
          overallProgress={overallProgress}
          tasks={tasks}
        />
      ) : null}

      {!signingEnabled ? (
        <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
          签章服务当前不可用：请确认 Java PDF 服务已启动，且 <code>PDF_SERVICE_ENABLED</code> 与{' '}
          <code>PDF_SIGNING_ENABLED</code> 均已开启。
        </div>
      ) : null}

      {error ? <ErrorNotice error={new Error(error)} /> : null}

      {results.length > 0 ? (
        <Panel
          title="处理结果"
          description={
            failureCount > 0
              ? `共 ${results.length} 个文件，成功 ${successCount} 个，失败 ${failureCount} 个。文件已自动下载，也可在此重新下载。`
              : `共 ${results.length} 个文件全部处理成功，已自动下载，也可在此重新下载。`
          }
          actions={
            countdown !== null ? (
              <div className="flex items-center gap-2 text-xs text-slate-500">
                <span>{countdown} 秒后自动重置</span>
                <Button variant="ghost" onClick={() => setCountdown(null)}>
                  取消
                </Button>
              </div>
            ) : undefined
          }
        >
          <ul className="space-y-2">
            {results.map((result) => (
              <SignResultCard key={result.key} result={result} />
            ))}
          </ul>
        </Panel>
      ) : (
        <Panel
          title="选择文件"
          description="支持一次拖入多个 PDF，系统会并发处理。"
          actions={
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="size-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"
                checked={autoStart}
                onChange={(event) => setAutoStart(event.target.checked)}
              />
              上传后马上开始
            </label>
          }
        >
          <div className={cn('grid gap-3', photometricEnabled ? 'sm:grid-cols-2' : '')}>
            <UploadArea
              title="正常 PDF 签章"
              hint="点击选择或拖拽 PDF 文件"
              inputRef={normalInputRef}
              onFiles={(files) => addFiles(files, false)}
            />
            {/*
              处理光度数据后签名：ported from zs-lims but gated by
              pdf_service.signing.photometric_removal_enabled, which ships false.
            */}
            {photometricEnabled ? (
              <UploadArea
                title="处理光度数据后签名"
                hint="先遮盖光度参数区域再签章"
                accent
                inputRef={photometricInputRef}
                onFiles={(files) => addFiles(files, true)}
              />
            ) : null}
          </div>

          {queue.length > 0 ? (
            <>
              <div className="mt-4 flex items-center justify-between gap-3">
                <h3 className="text-sm font-medium text-slate-900">待处理文件（{queue.length}）</h3>
                <Button variant="ghost" disabled={signing} onClick={() => setQueue([])}>
                  清空
                </Button>
              </div>
              <ul className="mt-2 space-y-2">
                {queue.map((item, index) => (
                  <li className="rounded-md border border-emerald-900/10 bg-white p-3 text-sm" key={item.key}>
                    <div className="flex items-center gap-3">
                      <FileText className="size-4 shrink-0 text-slate-400" aria-hidden="true" />
                      <span className="min-w-0 flex-1 truncate text-slate-900">{item.file.name}</span>
                      <span className="shrink-0 text-xs text-slate-500">{formatBytes(item.file.size)}</span>
                      <span
                        className={cn(
                          'shrink-0 rounded-md border px-2 py-0.5 text-xs',
                          item.removePhotometric
                            ? 'border-amber-200 bg-amber-50 text-amber-700'
                            : 'border-slate-200 bg-slate-50 text-slate-600',
                        )}
                      >
                        {item.removePhotometric ? '删除光度数据' : '正常处理'}
                      </span>
                      <Button
                        variant="ghost"
                        aria-label={`移除 ${item.file.name}`}
                        disabled={signing}
                        onClick={() => setQueue((current) => current.filter((_, position) => position !== index))}
                      >
                        <Trash2 className="size-4" aria-hidden="true" />
                      </Button>
                    </div>

                    {/*
                      Pre-filled from the file name and editable: the cover-page
                      extractor has returned a whole labelled line as the report
                      number, and a wrong number is worse than none — the ledger
                      is searched by it and recipients are shown it.
                    */}
                    <label className="mt-2 flex flex-wrap items-center gap-2 pl-7">
                      <span className="text-xs text-slate-600">报告编号</span>
                      <input
                        className={cn(inputClass, 'h-8 max-w-56 font-mono text-xs')}
                        value={item.reportNumber}
                        placeholder="如 XDP2025120133"
                        disabled={signing}
                        onChange={(event) =>
                          setQueue((current) =>
                            current.map((row) => (row.key === item.key ? { ...row, reportNumber: event.target.value } : row)),
                          )
                        }
                      />
                      {item.reportNumber.trim() ? null : (
                        <span className="text-xs text-amber-700">未能从文件名识别，留空则该报告不登记编号</span>
                      )}
                    </label>
                  </li>
                ))}
              </ul>
            </>
          ) : null}
        </Panel>
      )}

      <Panel title="签章配置" description="配置在本次批量处理中对所有文件生效。">
        <div className="space-y-5">
          <CertificatePicker
            templates={options?.data.certificate_templates ?? []}
            selectedId={effectiveConfig.certificateId}
            disabled={signing}
            onSelect={(id) => update({ certificateId: id })}
          />

          <div className="grid gap-5 md:grid-cols-2">
            <SealPicker
              label="首页盖章"
              path="digital-signatures"
              enabled={effectiveConfig.enableSignature}
              options={options?.data.digital_signatures ?? []}
              selectedId={effectiveConfig.digitalSignatureId}
              disabled={signing}
              onToggle={(enabled) => update({ enableSignature: enabled })}
              onSelect={(id) => update({ digitalSignatureId: id })}
            />
            <SealPicker
              label="骑缝章"
              path="perforation-stamps"
              enabled={effectiveConfig.enablePerforation}
              options={options?.data.perforation_stamps ?? []}
              selectedId={effectiveConfig.perforationStampId}
              disabled={signing}
              onToggle={(enabled) => update({ enablePerforation: enabled })}
              onSelect={(id) => update({ perforationStampId: id })}
            />
          </div>

          <FunctionStampPicker
            options={options?.data.function_stamps ?? []}
            selectedIds={effectiveConfig.functionStampIds}
            disabled={signing}
            onChange={(ids) => update({ functionStampIds: ids })}
          />

          {options?.meta.operator_name ? (
            <p className="text-xs text-slate-500">签发人：{options.meta.operator_name}（取当前登录账号，写入签章台账）</p>
          ) : null}
        </div>
      </Panel>
    </PageShell>
  )
}

/**
 * Batch progress while signing runs: overall bar plus one card per in-flight
 * file, so a stuck report is visible instead of hiding behind a single spinner.
 */
function ProcessingOverlay({
  processedCount,
  totalCount,
  overallProgress,
  tasks,
}: {
  processedCount: number
  totalCount: number
  overallProgress: number
  tasks: TaskState[]
}) {
  // A ticking clock is the cheapest proof that a silent stage is still running:
  // the server sends nothing between "upload finished" and "here is your file".
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 500)

    return () => clearInterval(timer)
  }, [])

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/40 px-4 py-10">
      <section className="w-full max-w-xl rounded-lg border border-slate-200 bg-white p-6 shadow-xl">
        <div className="flex flex-col items-center text-center">
          <Loader2 className="size-10 animate-spin text-emerald-600" aria-hidden="true" />
          <h2 className="mt-3 text-base font-semibold text-slate-900">正在批量处理 PDF 文件</h2>
          <p className="mt-1 text-sm text-slate-500">请稍候，系统正在合并声明页、加盖印章并写入数字签名…</p>
        </div>

        <div className="mt-5">
          <div className="h-2 overflow-hidden rounded-full bg-slate-100">
            <div className="h-full bg-emerald-600 transition-[width]" style={{ width: `${overallProgress}%` }} />
          </div>
          <p className="mt-2 text-center text-xs text-slate-500">
            总进度：{processedCount} / {totalCount}（{Math.round(overallProgress)}%）
          </p>
        </div>

        {tasks.length > 0 ? (
          <div className="mt-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="text-xs font-medium text-slate-600">
                并发处理任务（{tasks.length} / {MAX_CONCURRENT_TASKS}）
              </span>
              <span className="flex items-center gap-1 text-xs text-slate-400">
                <span className="rounded bg-sky-50 px-1.5 py-0.5 text-sky-700">合并声明页</span>
                <ArrowRight className="size-3" aria-hidden="true" />
                <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-700">后端处理</span>
              </span>
            </div>

            <ul className="mt-2 space-y-2">
              {tasks.map((task) => (
                <TaskCard key={task.key} task={task} now={now} />
              ))}
            </ul>
          </div>
        ) : null}
      </section>
    </div>
  )
}

/**
 * One in-flight file.
 *
 * While the server signs, there is no progress to report — so this shows the
 * seconds elapsed instead. A number that keeps moving is what distinguishes
 * "working" from "hung", which a bar parked at 95% cannot do.
 */
function TaskCard({ task, now }: { task: TaskState; now: number }) {
  const signing = task.stage === 'signing'
  const elapsedSeconds = Math.max(0, Math.round((now - task.startedAt) / 1000))
  const signingSeconds = task.signingSince ? Math.max(0, Math.round((now - task.signingSince) / 1000)) : 0
  const expected = expectedSigningSeconds(task.bytes)
  // Several times the measured norm, so the warning keeps its meaning.
  const overdue = signing && signingSeconds >= Math.max(45, expected * 5)

  return (
    <li className="rounded-md border border-emerald-900/10 bg-slate-50 p-3">
      <div className="flex items-center justify-between gap-3 text-xs">
        <span className="font-medium text-slate-500">任务 {task.index}</span>
        <span className={task.stage === 'error' ? 'text-red-700' : 'text-slate-500'}>
          {signing ? `已用 ${elapsedSeconds} 秒 / 通常约 ${expected} 秒` : `${Math.round(task.progress)}%`}
        </span>
      </div>
      <p className="mt-1 truncate text-sm text-slate-900">{task.fileName}</p>
      <p className={cn('text-xs', task.stage === 'error' ? 'text-red-700' : overdue ? 'text-amber-700' : 'text-slate-500')}>
        {task.status}
        {signing ? '…' : ''}
        {overdue ? ' · 比平常久，仍在进行' : ''}
      </p>

      <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
        {signing ? (
          // Indeterminate: the server reports nothing until it is done, so an
          // animated stripe is honest where a percentage would be invented.
          <div
            className={cn('h-full w-1/3 animate-[pdfsign-sweep_1.4s_ease-in-out_infinite] rounded-full', overdue ? 'bg-amber-500' : 'bg-emerald-600')}
          />
        ) : (
          <div
            className={cn(
              'h-full transition-[width]',
              task.stage === 'error' ? 'bg-red-500' : task.stage === 'merge' ? 'bg-sky-500' : 'bg-emerald-600',
            )}
            style={{ width: `${task.progress}%` }}
          />
        )}
      </div>
    </li>
  )
}

function SignResultCard({ result }: { result: SignResult }) {
  return (
    <li
      className={cn(
        'rounded-md border p-3 text-sm',
        result.error ? 'border-red-200 bg-red-50' : 'border-emerald-200 bg-emerald-50',
      )}
    >
      <div className="flex flex-wrap items-center gap-3">
        {result.error ? (
          <XCircle className="size-4 shrink-0 text-red-600" aria-hidden="true" />
        ) : (
          <CheckCircle2 className="size-4 shrink-0 text-emerald-600" aria-hidden="true" />
        )}
        <p className="min-w-0 flex-1 truncate font-medium text-slate-900">{result.originalName}</p>
        <span
          className={cn(
            'shrink-0 rounded-md border px-2 py-0.5 text-xs font-medium',
            result.error ? 'border-red-200 bg-white text-red-700' : 'border-emerald-200 bg-white text-emerald-700',
          )}
        >
          {result.error ? '处理失败' : '处理成功'}
        </span>
        {result.blobUrl ? (
          <a
            className="inline-flex h-9 shrink-0 items-center gap-2 rounded-md bg-emerald-700 px-3 text-sm font-medium text-white hover:bg-emerald-800"
            href={result.blobUrl}
            download={result.downloadName}
          >
            <Download className="size-4" aria-hidden="true" />
            下载
          </a>
        ) : null}
      </div>

      {result.error ? (
        <p className="mt-2 text-red-700">{result.error}</p>
      ) : (
        <dl className="mt-2 space-y-1 text-xs">
          {result.reportNumber ? (
            <div className="flex gap-2">
              <dt className="w-20 shrink-0 text-slate-500">报告编号</dt>
              <dd className="text-slate-800">{result.reportNumber}</dd>
            </div>
          ) : null}
          <div className="flex gap-2">
            <dt className="w-20 shrink-0 text-slate-500">文件大小</dt>
            <dd className="text-slate-800">{formatBytes(result.fileSize)}</dd>
          </div>
          <div className="flex gap-2">
            <dt className="w-20 shrink-0 text-slate-500">{digestLabels.primary_hash}</dt>
            {/* Full digest, selectable: this is the value a recipient checks against. */}
            <dd className="min-w-0 break-all font-mono text-slate-700 select-all">{result.sha256 ?? '-'}</dd>
          </div>
        </dl>
      )}
    </li>
  )
}

function UploadArea({
  title,
  hint,
  accent = false,
  inputRef,
  onFiles,
}: {
  title: string
  hint: string
  accent?: boolean
  inputRef: React.RefObject<HTMLInputElement | null>
  onFiles: (files: FileList | null) => void
}) {
  const [dragging, setDragging] = useState(false)

  return (
    <div
      className={cn(
        'cursor-pointer rounded-lg border-2 border-dashed p-6 text-center transition-colors',
        accent ? 'border-amber-300 bg-amber-50/50 hover:bg-amber-50' : 'border-emerald-300 bg-emerald-50/40 hover:bg-emerald-50',
        dragging && 'border-emerald-600 bg-emerald-50',
      )}
      onClick={() => inputRef.current?.click()}
      onDragOver={(event) => {
        event.preventDefault()
        setDragging(true)
      }}
      onDragLeave={() => setDragging(false)}
      onDrop={(event) => {
        event.preventDefault()
        setDragging(false)
        onFiles(event.dataTransfer.files)
      }}
      role="button"
      tabIndex={0}
      onKeyDown={(event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault()
          inputRef.current?.click()
        }
      }}
    >
      <Upload className="mx-auto size-6 text-slate-400" aria-hidden="true" />
      <p className="mt-2 text-sm font-medium text-slate-900">{title}</p>
      <p className="mt-1 text-xs text-slate-500">{hint}</p>
      <input
        className="hidden"
        ref={inputRef}
        type="file"
        accept="application/pdf,.pdf"
        multiple
        onChange={(event) => {
          onFiles(event.target.files)
          event.target.value = ''
        }}
      />
    </div>
  )
}

/**
 * Declaration pages grouped by language, as cards rather than a bare select:
 * the operator needs the description and the default marker to pick correctly.
 */
function CertificatePicker({
  templates,
  selectedId,
  disabled,
  onSelect,
}: {
  templates: CertificateTemplate[]
  selectedId: number | null
  disabled: boolean
  onSelect: (id: number | null) => void
}) {
  const grouped = templates.reduce<Record<string, CertificateTemplate[]>>((accumulator, template) => {
    const language = template.language || 'zh'
    accumulator[language] = [...(accumulator[language] ?? []), template]

    return accumulator
  }, {})

  return (
    <div>
      <span className="text-xs font-medium text-slate-600">声明页（证书模板，可选）</span>

      {templates.length === 0 ? (
        <p className="mt-2 rounded-md border border-dashed border-slate-300 p-3 text-xs text-slate-500">
          暂无可用声明页模板，可在「声明页模板」中上传。
        </p>
      ) : (
        <div className="mt-2 space-y-3">
          <CertificateOption
            title="不附加声明页"
            description="仅加盖印章并写入数字签名"
            selected={selectedId === null}
            disabled={disabled}
            onSelect={() => onSelect(null)}
          />

          {Object.entries(grouped).map(([language, items]) => (
            <div key={language}>
              <h4 className="mb-1.5 text-xs font-medium text-slate-500">{languageNames[language] ?? language}</h4>
              <div className="grid gap-2 sm:grid-cols-2">
                {items.map((template) => (
                  <CertificateOption
                    key={template.id}
                    title={template.name}
                    description={template.description}
                    fileName={template.file_name}
                    fileSize={template.file_size}
                    isDefault={template.is_default}
                    selected={selectedId === template.id}
                    disabled={disabled}
                    onSelect={() => onSelect(template.id)}
                  />
                ))}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

function CertificateOption({
  title,
  description,
  fileName,
  fileSize,
  isDefault = false,
  selected,
  disabled,
  onSelect,
}: {
  title: string
  description?: string | null
  fileName?: string | null
  fileSize?: number | null
  isDefault?: boolean
  selected: boolean
  disabled: boolean
  onSelect: () => void
}) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onSelect}
      aria-pressed={selected}
      className={cn(
        'flex w-full items-start gap-2 rounded-md border p-3 text-left transition-colors disabled:cursor-not-allowed',
        selected ? 'border-emerald-600 bg-emerald-50 ring-2 ring-emerald-100' : 'border-emerald-900/15 bg-white hover:bg-emerald-50/60',
      )}
    >
      <span
        className={cn(
          'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border',
          selected ? 'border-emerald-600' : 'border-slate-300',
        )}
        aria-hidden="true"
      >
        {selected ? <span className="size-2 rounded-full bg-emerald-600" /> : null}
      </span>
      <span className="min-w-0 flex-1">
        <span className="flex flex-wrap items-center gap-2">
          <span className="text-sm text-slate-900">{title}</span>
          {isDefault ? (
            <span className="rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-xs text-emerald-700">默认</span>
          ) : null}
        </span>
        {description ? <span className="mt-0.5 block text-xs text-slate-500">{description}</span> : null}
        {fileName ? (
          <span className="mt-0.5 block truncate text-xs text-slate-400">
            {fileName}
            {fileSize ? ` · ${formatBytes(fileSize)}` : ''}
          </span>
        ) : null}
      </span>
    </button>
  )
}

function SealPicker({
  label,
  path,
  enabled,
  options,
  selectedId,
  disabled,
  onToggle,
  onSelect,
}: {
  label: string
  path: string
  enabled: boolean
  options: SealOption[]
  selectedId: number | null
  disabled: boolean
  onToggle: (enabled: boolean) => void
  onSelect: (id: number | null) => void
}) {
  return (
    <div>
      <div className="flex items-center justify-between gap-3">
        <span className="text-xs font-medium text-slate-600">{label}</span>
        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            className="size-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"
            checked={enabled}
            disabled={disabled}
            onChange={(event) => onToggle(event.target.checked)}
          />
          启用
        </label>
      </div>

      {enabled ? (
        options.length === 0 ? (
          <p className="mt-2 rounded-md border border-dashed border-slate-300 p-3 text-xs text-slate-500">
            暂无可用配置，请先在设置中添加。
          </p>
        ) : (
          <>
            <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
              {options.map((option) => (
                <SealCard
                  key={option.id}
                  option={option}
                  path={path}
                  selected={selectedId === option.id}
                  disabled={disabled}
                  onSelect={() => onSelect(option.id)}
                />
              ))}
            </div>
            {selectedId === null ? (
              <p className="mt-2 text-xs text-amber-700">请选择一个{label}，或取消勾选「启用」。</p>
            ) : null}
          </>
        )
      ) : null}
    </div>
  )
}

function SealCard({
  option,
  path,
  selected,
  disabled,
  onSelect,
}: {
  option: SealOption
  path: string
  selected: boolean
  disabled: boolean
  onSelect: () => void
}) {
  const imageUrl = useAuthedObjectUrl(assetFileUrl(path, option.id, option.updated_at))

  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onSelect}
      aria-pressed={selected}
      title={option.description ?? option.name}
      className={cn(
        'flex flex-col items-center gap-1 rounded-md border p-2 transition-colors disabled:cursor-not-allowed',
        selected ? 'border-emerald-600 bg-emerald-50 ring-2 ring-emerald-100' : 'border-emerald-900/15 bg-white hover:bg-emerald-50/60',
      )}
    >
      {imageUrl ? (
        <img className="size-14 object-contain transition-transform duration-200 group-hover:scale-110" src={imageUrl} alt={option.name} />
      ) : (
        <div className="size-14 rounded bg-slate-100" aria-hidden="true" />
      )}
      <span className="line-clamp-2 text-center text-xs text-slate-700">{option.name}</span>
      {option.is_default ? <span className="text-[10px] text-emerald-700">默认</span> : null}
    </button>
  )
}

function FunctionStampPicker({
  options,
  selectedIds,
  disabled,
  onChange,
}: {
  options: FunctionStampOption[]
  selectedIds: number[]
  disabled: boolean
  onChange: (ids: number[]) => void
}) {
  const selected = selectedIds
    .map((id) => options.find((option) => option.id === id))
    .filter((option): option is FunctionStampOption => Boolean(option))

  function toggle(id: number) {
    onChange(selectedIds.includes(id) ? selectedIds.filter((value) => value !== id) : [...selectedIds, id])
  }

  function move(index: number, direction: -1 | 1) {
    const target = index + direction

    if (target < 0 || target >= selectedIds.length) {
      return
    }

    const next = [...selectedIds]
    const [moved] = next.splice(index, 1)
    next.splice(target, 0, moved)
    onChange(next)
  }

  return (
    <div>
      <div className="flex items-center justify-between gap-3">
        <span className="text-xs font-medium text-slate-600">首页功能章</span>
        {selected.length > 0 ? (
          <Button variant="ghost" disabled={disabled} onClick={() => onChange([])}>
            清空
          </Button>
        ) : null}
      </div>

      {options.length === 0 ? (
        <p className="mt-2 rounded-md border border-dashed border-slate-300 p-3 text-xs text-slate-500">暂无可用功能章。</p>
      ) : (
        <div className="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-6">
          {options.map((option) => (
            <FunctionStampCard
              key={option.id}
              option={option}
              order={selectedIds.indexOf(option.id) + 1}
              selected={selectedIds.includes(option.id)}
              disabled={disabled}
              onToggle={() => toggle(option.id)}
            />
          ))}
        </div>
      )}

      {selected.length > 0 ? (
        <div className="mt-3 rounded-md border border-emerald-900/10 bg-slate-50 p-3">
          <p className="text-xs text-slate-600">已选 {selected.length} 个，将按此顺序在首页从左到右排列。</p>
          <ol className="mt-2 flex flex-wrap gap-2">
            {selected.map((option, index) => (
              <li className="flex items-center gap-1 rounded-md border border-emerald-900/15 bg-white px-2 py-1 text-xs" key={option.id}>
                <span className="font-medium text-slate-500">{index + 1}</span>
                <span className="text-slate-900">{option.name}</span>
                <button
                  type="button"
                  className="text-slate-400 hover:text-emerald-700 disabled:opacity-30"
                  disabled={disabled || index === 0}
                  onClick={() => move(index, -1)}
                  aria-label={`${option.name} 前移`}
                >
                  <ArrowLeft className="size-3.5" aria-hidden="true" />
                </button>
                <button
                  type="button"
                  className="text-slate-400 hover:text-emerald-700 disabled:opacity-30"
                  disabled={disabled || index === selected.length - 1}
                  onClick={() => move(index, 1)}
                  aria-label={`${option.name} 后移`}
                >
                  <ArrowRight className="size-3.5" aria-hidden="true" />
                </button>
                <button
                  type="button"
                  className="ml-0.5 text-slate-400 hover:text-red-600"
                  disabled={disabled}
                  onClick={() => toggle(option.id)}
                  aria-label={`移除 ${option.name}`}
                >
                  <XCircle className="size-3.5" aria-hidden="true" />
                </button>
              </li>
            ))}
          </ol>
        </div>
      ) : null}
    </div>
  )
}

function FunctionStampCard({
  option,
  order,
  selected,
  disabled,
  onToggle,
}: {
  option: FunctionStampOption
  order: number
  selected: boolean
  disabled: boolean
  onToggle: () => void
}) {
  const imageUrl = useAuthedObjectUrl(assetFileUrl('function-stamps', option.id, option.updated_at))

  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onToggle}
      aria-pressed={selected}
      className={cn(
        'relative flex flex-col items-center gap-1 rounded-md border p-2 transition-colors disabled:cursor-not-allowed',
        selected ? 'border-emerald-600 bg-emerald-50 ring-2 ring-emerald-100' : 'border-emerald-900/15 bg-white hover:bg-emerald-50/60',
      )}
    >
      {selected ? (
        <span className="absolute left-1 top-1 flex size-4 items-center justify-center rounded-full bg-emerald-600 text-[10px] font-medium text-white">
          {order}
        </span>
      ) : null}
      {imageUrl ? (
        <img className="size-12 object-contain" src={imageUrl} alt={option.name} />
      ) : (
        <div className="size-12 rounded bg-slate-100" aria-hidden="true" />
      )}
      <span className="line-clamp-2 text-center text-xs text-slate-700">{option.name}</span>
    </button>
  )
}

function triggerDownload(url: string, fileName: string) {
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = fileName
  document.body.appendChild(anchor)
  anchor.click()
  document.body.removeChild(anchor)
}

function stripExtension(name: string) {
  return name.replace(/\.[^/.]+$/, '')
}

function parseFileName(disposition?: string) {
  if (!disposition) {
    return null
  }

  const utf8Match = /filename\*=UTF-8''([^;]+)/i.exec(disposition)

  if (utf8Match) {
    try {
      return decodeURIComponent(utf8Match[1])
    } catch {
      return null
    }
  }

  const match = /filename="?([^";]+)"?/i.exec(disposition)

  return match ? match[1] : null
}

async function readBlobError(caught: unknown) {
  const blob = (caught as { response?: { data?: unknown } }).response?.data

  if (blob instanceof Blob) {
    try {
      const parsed = JSON.parse(await blob.text()) as { message?: string; error?: string; errors?: Record<string, string[]> }
      const validation = parsed.errors ? Object.values(parsed.errors).flat()[0] : undefined

      return validation ?? parsed.error ?? parsed.message ?? '签章失败'
    } catch {
      return '签章失败'
    }
  }

  return errorMessage(caught, '签章失败')
}
