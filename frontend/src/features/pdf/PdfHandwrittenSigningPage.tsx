import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  BadgeCheck,
  CheckCircle2,
  ChevronDown,
  FileKey2,
  Grip,
  Loader2,
  LockKeyhole,
  PenTool,
  RefreshCw,
  Send,
  ShieldCheck,
  Upload,
  Users,
  XCircle,
} from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Button, ErrorNotice, PageShell } from '../system/shared'
import { inputClass } from '../system/utils'
import { reportNumberFromFileName } from './api'
import {
  cancelSigningWorkflow,
  confirmAndFinalizeSigningSource,
  createPreparedSigningWorkflow,
  downloadRevision,
  fetchAssignedSigningRequests,
  fetchPlanningOptions,
  fetchSigningOperation,
  fetchSigningRequest,
  inspectSigningSource,
  rejectSigningRequest,
  submitSignatureAppearance,
  type Placement,
  type SignatureRole,
} from './handwrittenApi'
import { PdfPlacementWorkspace } from './PdfPlacementWorkspace'
import { resumeDocumentUuid, resumePlanning } from './resumePlanning'
import { canFreeze, workflowIdempotencyKey as buildWorkflowIdempotencyKey } from './workflowAttempt'
import { SignaturePad } from './SignaturePad'

const roles: SignatureRole[] = ['inspector', 'reviewer', 'issuer']
const roleLabels: Record<SignatureRole, string> = {
  inspector: '主检',
  reviewer: '审核',
  issuer: '签发',
}

const roleSwatches: Record<SignatureRole, string> = {
  inspector: 'bg-sky-500',
  reviewer: 'bg-violet-500',
  issuer: 'bg-emerald-600',
}

const defaultPlacements: Placement[] = [
  { semantic_role: 'inspector', page_index: 0, normalized_rect: rect('0.12', '0.72') },
  { semantic_role: 'reviewer', page_index: 0, normalized_rect: rect('0.40', '0.72') },
  { semantic_role: 'issuer', page_index: 0, normalized_rect: rect('0.68', '0.72') },
]

type WorkspaceMode = 'plan' | 'sign'

export function PdfHandwrittenSigningPage() {
  const [mode, setMode] = useState<WorkspaceMode>(modeFromHash)

  useEffect(() => {
    const syncMode = () => setMode(modeFromHash())
    window.addEventListener('hashchange', syncMode)
    return () => window.removeEventListener('hashchange', syncMode)
  }, [])

  return (
    <PageShell
      title="手写数字签名工作台"
      description="先把全部签名字段冻结到首个签名前，再由主检、审核、签发依次手写并使用单位证书生成增量 PAdES-B-T 数字签名。"
      actions={
        <div className="inline-flex rounded-lg border border-emerald-900/15 bg-white p-1 shadow-sm">
          <ModeButton active={mode === 'sign'} href="/pdf/handwritten-signing#sign" icon={PenTool}>
            我的签名任务
          </ModeButton>
          <ModeButton active={mode === 'plan'} href="/pdf/handwritten-signing#plan" icon={Grip}>
            规划签名位置
          </ModeButton>
        </div>
      }
    >
      {mode === 'plan' ? <PlanningWorkspace /> : <SigningWorkspace />}
    </PageShell>
  )
}

function PlanningWorkspace() {
  const options = useQuery({ queryKey: ['pdf', 'handwritten', 'options'], queryFn: fetchPlanningOptions })
  const [file, setFile] = useState<File | null>(null)
  const [inspected, setInspected] = useState<Awaited<ReturnType<typeof inspectSigningSource>> | null>(null)
  const [uploadedFinalized, setFinalized] = useState<Awaited<ReturnType<typeof confirmAndFinalizeSigningSource>> | null>(null)
  const [uploadedIdempotencyKey, setWorkflowIdempotencyKey] = useState('')
  const [editedReportNumber, setReportNumber] = useState<string | null>(null)
  const [policyVersionUuid, setPolicyVersionUuid] = useState('')
  const [editedAssignments, setAssignments] = useState<Record<SignatureRole, number> | null>(null)
  const [editedPlacements, setPlacements] = useState<Placement[] | null>(null)
  const [selectedRole, setSelectedRole] = useState<SignatureRole>('inspector')
  const [showCoordinates, setShowCoordinates] = useState(false)
  const [attemptKey, setAttemptKey] = useState<string | null>(null)
  const [workflowResult, setResult] = useState<{ workflow_uuid: string; status: string } | null>(null)
  const [resumedDocument] = useState(resumeDocumentUuid)
  const effectivePolicyVersionUuid = policyVersionUuid || options.data?.policies[0]?.version_uuid || ''

  // Continuing an existing document is a read, not a mutation: upload, confirm
  // and finalize already happened. A query also keys the work to the document,
  // so StrictMode's remount cannot fire it — or re-download the PDF — twice.
  const resume = useQuery({
    queryKey: ['pdf', 'documents', resumedDocument, 'resume'],
    queryFn: () => resumePlanning(resumedDocument as string),
    enabled: resumedDocument !== null,
    staleTime: Infinity,
    gcTime: Infinity,
    retry: false,
  })

  // Local edits win; otherwise fall back to what was resumed, then to defaults.
  const finalized = uploadedFinalized
    ?? (resume.data ? { revision: resume.data.revision, file: resume.data.file } : null)
  const reportNumber = editedReportNumber ?? resume.data?.reportNumber ?? ''
  const placements = editedPlacements ?? resume.data?.placements ?? defaultPlacements
  const assignments = editedAssignments
    ?? resume.data?.assignments
    ?? { inspector: 0, reviewer: 0, issuer: 0 }
  const result = workflowResult ?? resume.data?.activeWorkflow ?? null
  const workflowIdempotencyKey = buildWorkflowIdempotencyKey({
    uploaded: uploadedIdempotencyKey,
    attempt: attemptKey,
    documentUuid: resumedDocument,
    revisionUuid: resume.data?.revision.revision_uuid ?? null,
  })

  const inspectSource = useMutation({
    mutationFn: async () => {
      if (!file) throw new Error('请先选择未签名 PDF')
      return inspectSigningSource(file)
    },
    onSuccess: (source) => {
      setInspected(source)
      setFinalized(null)
      setResult(null)
    },
  })
  const finalizeSource = useMutation({
    mutationFn: async () => {
      if (!inspected) throw new Error('请先完成 PDF 结构检查')
      return confirmAndFinalizeSigningSource({
        sourceUuid: inspected.source_uuid,
        reportNumber: reportNumber.trim(),
      })
    },
    onSuccess: (revision) => {
      setFinalized(revision)
      setWorkflowIdempotencyKey(`workflow-${crypto.randomUUID()}`)
      setResult(null)
    },
  })
  const create = useMutation({
    mutationFn: createPreparedSigningWorkflow,
    onSuccess: setResult,
  })
  const cancel = useMutation({
    mutationFn: ({ workflowUuid }: { workflowUuid: string }) =>
      cancelSigningWorkflow(workflowUuid, 'PLANNER_CANCELLED'),
    onSuccess: (cancelled) => {
      setResult(cancelled)
      // The next freeze must be a new generation. Reusing the key would replay
      // the cancelled workflow instead of creating one.
      setAttemptKey(`workflow-${crypto.randomUUID()}`)
    },
  })
  const selected = placements.find((placement) => placement.semantic_role === selectedRole)!
  const ready = Boolean(
    finalized && workflowIdempotencyKey && effectivePolicyVersionUuid
      && assignments.inspector && assignments.reviewer && assignments.issuer,
  )

  // Edits start from the effective plan, which may still be the resumed one.
  function updateRole(role: SignatureRole, patch: Partial<Placement>) {
    setPlacements(
      placements.map((placement) => (placement.semantic_role === role ? { ...placement, ...patch } : placement)),
    )
  }

  function updateSelected(patch: Partial<Placement>) {
    updateRole(selectedRole, patch)
  }

  return (
    <div className="grid min-h-0 gap-4 xl:h-[calc(100vh-11rem)] xl:grid-cols-[minmax(0,1fr)_21rem]">
      <PdfPlacementWorkspace
        file={finalized?.file ?? null}
        placements={placements}
        editable={Boolean(finalized) && !result}
        selectedRole={selectedRole}
        onSelectRole={setSelectedRole}
        onChange={setPlacements}
        emptyMessage={inspected ? '确认报告编号并完成定稿后，将加载定稿 PDF 供位置规划' : '上传并检查 PDF 后，再对定稿版本规划签名位置'}
      />
      <aside className="space-y-4 xl:min-h-0 xl:overflow-y-auto xl:pr-1">
        {resumedDocument ? (
          <div className="rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs leading-5 text-sky-900">
            {resume.isPending ? (
              <span className="flex items-center gap-2"><Loader2 className="size-4 animate-spin" />正在载入已有文档…</span>
            ) : resume.isError ? (
              <ErrorNotice error={resume.error} fallback="已有文档载入失败" />
            ) : (
              <>
                <p className="font-medium">正在编辑已有文档 · {reportNumber}</p>
                <p className="mt-1 text-sky-800">
                  {result
                    ? '该文档已有工作流。修改签名位置或签署人前，需要先取消当前这一代。'
                    : '定稿版本已载入，直接调整签名位置和签署人即可。'}
                </p>
                <a className="mt-1 inline-block underline" href="/pdf/documents">返回签署文档列表</a>
              </>
            )}
          </div>
        ) : null}
        {/* Continuing a document means upload, inspection and finalization are
            already done, so their cards would only be dead weight beside the PDF. */}
        {resumedDocument ? null : (
        <>
        <WorkspaceCard title="1. 上传并检查原始 PDF" icon={Upload}>
          <label className={`block cursor-pointer rounded-lg border border-dashed border-emerald-400 bg-emerald-50/60 p-4 text-center transition hover:bg-emerald-50 ${finalized ? 'cursor-not-allowed opacity-70' : ''}`}>
            <Upload className="mx-auto size-5 text-emerald-700" />
            <span className="mt-2 block text-sm font-medium text-emerald-900">上传未签名 PDF</span>
            <span className="mt-1 block truncate text-xs text-slate-500">{file?.name ?? '最大 20 MB，不支持加密或已有签名的文件'}</span>
            <input
              className="sr-only"
              type="file"
              accept="application/pdf,.pdf"
              disabled={Boolean(finalized)}
              onChange={(event) => {
                const next = event.target.files?.[0] ?? null
                setFile(next)
                setReportNumber(next ? reportNumberFromFileName(next.name) : '')
                setInspected(null)
                setFinalized(null)
                setWorkflowIdempotencyKey('')
                setResult(null)
                setPlacements(defaultPlacements)
              }}
            />
          </label>
          {inspectSource.isError ? <div className="mt-3"><ErrorNotice error={inspectSource.error} fallback="PDF 结构检查失败" /></div> : null}
          {inspected ? (
            <div className="mt-3 rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-800">
              <div className="font-semibold">结构检查通过 · {inspected.page_count} 页</div>
              <div className="mt-1 break-all font-mono text-[10px]">SHA-256 {inspected.sha256}</div>
            </div>
          ) : null}
          <Button
            variant="secondary"
            className="mt-3 w-full"
            disabled={!file || inspectSource.isPending || Boolean(inspected) || Boolean(finalized)}
            onClick={() => inspectSource.mutate()}
          >
            {inspectSource.isPending ? <Loader2 className="size-4 animate-spin" /> : <ShieldCheck className="size-4" />}
            {inspected ? 'PDF 已检查' : '检查 PDF 结构与签名状态'}
          </Button>
        </WorkspaceCard>

        <WorkspaceCard title="2. 确认报告并生成定稿" icon={FileKey2}>
          <label className="mt-3 block text-xs font-medium text-slate-600">
            报告编号
            <input
              className={`${inputClass} mt-1`}
              value={reportNumber}
              maxLength={128}
              disabled={!inspected || Boolean(finalized)}
              placeholder="例如 XDP2025120133"
              onChange={(event) => setReportNumber(event.target.value)}
            />
          </label>
          <p className="mt-2 text-xs leading-5 text-slate-500">确认后报告身份不可修改；系统会重新下载精确的 finalized revision。</p>
          {finalizeSource.isError ? <div className="mt-3"><ErrorNotice error={finalizeSource.error} fallback="PDF 定稿失败" /></div> : null}
          {finalized ? (
            <div className="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800">
              <div className="font-semibold">定稿字节已锁定并载入预览</div>
              <div className="mt-1 break-all font-mono text-[10px]">SHA-256 {finalized.revision.sha256}</div>
            </div>
          ) : null}
          <Button
            variant="secondary"
            className="mt-3 w-full"
            disabled={!inspected || !reportNumber.trim() || finalizeSource.isPending || Boolean(finalized)}
            onClick={() => finalizeSource.mutate()}
          >
            {finalizeSource.isPending ? <Loader2 className="size-4 animate-spin" /> : <FileKey2 className="size-4" />}
            {finalized ? '报告与定稿已冻结' : '确认报告编号并生成定稿'}
          </Button>
        </WorkspaceCard>
        </>
        )}

        <WorkspaceCard title={resumedDocument ? '调整签名框' : '3. 在定稿上调整签名框'} icon={Grip}>
          <fieldset disabled={!finalized || Boolean(result)} className="disabled:opacity-50">
            {/* Each row owns its own page, so setting one no longer means
                selecting it first. Selecting only decides which box the canvas
                highlights while dragging. */}
            <div className="space-y-1.5">
              {roles.map((role) => {
                const placement = placements.find((candidate) => candidate.semantic_role === role)!
                const active = selectedRole === role

                return (
                  <div
                    key={role}
                    role="button"
                    tabIndex={0}
                    className={`flex items-center gap-2 rounded-md border px-2 py-1.5 transition ${
                      active ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200 bg-white hover:border-emerald-300'
                    }`}
                    onClick={() => setSelectedRole(role)}
                    onKeyDown={(event) => event.key === 'Enter' && setSelectedRole(role)}
                  >
                    <span className={`size-2.5 shrink-0 rounded-sm ${roleSwatches[role]}`} />
                    <span className={`flex-1 text-xs font-medium ${active ? 'text-emerald-800' : 'text-slate-600'}`}>
                      {roleLabels[role]}
                    </span>
                    <label className="flex items-center gap-1 text-[11px] text-slate-500">
                      第
                      <input
                        className="h-7 w-12 rounded border border-slate-200 px-1 text-center text-xs text-slate-700"
                        type="number"
                        min={1}
                        value={placement.page_index + 1}
                        onClick={(event) => event.stopPropagation()}
                        onChange={(event) => updateRole(role, { page_index: Math.max(0, Number(event.target.value) - 1) })}
                      />
                      页
                    </label>
                  </div>
                )
              })}
            </div>
            <p className="mt-2.5 text-xs leading-5 text-slate-500">在页面上拖动移动，拖右下角把手调整大小。</p>
            <button
              type="button"
              className="mt-2 flex w-full items-center justify-between rounded-md border border-slate-200 px-2 py-1.5 text-xs text-slate-500 hover:border-emerald-300"
              onClick={() => setShowCoordinates((current) => !current)}
            >
              高级：精确坐标
              <ChevronDown className={`size-3.5 transition ${showCoordinates ? 'rotate-180' : ''}`} />
            </button>
            {showCoordinates ? (
              <>
                <div className="mt-2 grid grid-cols-4 gap-2 text-[10px] text-slate-500">
                  {(['x', 'y', 'width', 'height'] as const).map((key) => (
                    <label key={key}>
                      {key.toUpperCase()}
                      <input
                        className="mt-1 h-8 w-full rounded border border-slate-200 px-1 text-xs text-slate-700"
                        value={selected.normalized_rect[key]}
                        onChange={(event) =>
                          updateSelected({ normalized_rect: { ...selected.normalized_rect, [key]: event.target.value } })
                        }
                      />
                    </label>
                  ))}
                </div>
                <p className="mt-2 text-xs leading-5 text-slate-500">
                  作用于选中的「{roleLabels[selectedRole]}」，坐标以页面左上角为原点。
                </p>
              </>
            ) : null}
          </fieldset>
        </WorkspaceCard>

        <WorkspaceCard title={resumedDocument ? '指定三位签署人' : '4. 指定三位签署人'} icon={Users}>
          {options.isError ? <ErrorNotice error={options.error} fallback="签署选项加载失败" /> : null}
          <div className="space-y-3">
            {(['inspector', 'reviewer', 'issuer'] as const).map((role) => (
              <label key={role} className="block text-xs font-medium text-slate-600">
                {roleLabels[role]}
                <select
                  className={`${inputClass} mt-1`}
                  value={assignments[role] || ''}
                  onChange={(event) => setAssignments({ ...assignments, [role]: Number(event.target.value) })}
                >
                  <option value="">请选择</option>
                  {options.data?.assignees.map((user) => (
                    <option key={user.id} value={user.id}>
                      {user.name}
                    </option>
                  ))}
                </select>
              </label>
            ))}
            <label className="block text-xs font-medium text-slate-600">
              签名策略
              <select
                className={`${inputClass} mt-1`}
                value={effectivePolicyVersionUuid}
                onChange={(event) => setPolicyVersionUuid(event.target.value)}
              >
                {options.data?.policies.map((policy) => (
                  <option key={policy.version_uuid} value={policy.version_uuid}>
                    {policy.signing_material_version} · {policy.policy_hash.slice(0, 10)}…
                  </option>
                ))}
              </select>
            </label>
          </div>
        </WorkspaceCard>

        {create.isError ? <ErrorNotice error={create.error} fallback="工作流创建失败" /> : null}
        {result ? (
          <div className={`rounded-xl border p-4 text-sm ${result.status === 'cancelled' ? 'border-slate-300 bg-slate-50 text-slate-700' : 'border-emerald-200 bg-emerald-50 text-emerald-800'}`}>
            <div className="flex items-center gap-2 font-semibold"><CheckCircle2 className="size-4" />{result.status === 'cancelled' ? '工作流已取消' : '签名任务已准备'}</div>
            <div className="mt-2 break-all text-xs">{result.workflow_uuid}</div>
            {result.status !== 'cancelled' ? (
              <Button
                variant="secondary"
                className="mt-3 w-full border-red-200 text-red-700 hover:bg-red-50"
                disabled={cancel.isPending}
                onClick={() => cancel.mutate({ workflowUuid: result.workflow_uuid })}
              >
                {cancel.isPending ? <Loader2 className="size-4 animate-spin" /> : <XCircle className="size-4" />}
                取消本代工作流
              </Button>
            ) : null}
          </div>
        ) : null}
        {cancel.isError ? <ErrorNotice error={cancel.error} fallback="工作流取消失败" /> : null}
        {/* Already frozen: the fields are committed and there is nothing left to
            freeze. It comes back once the workflow is cancelled, since that
            leaves the document free for a new generation. */}
        {canFreeze(result?.status) ? (
        <Button
          variant="primary"
          className="w-full"
          disabled={!ready || create.isPending}
          onClick={() =>
            finalized &&
            create.mutate({
              planningRevisionUuid: finalized.revision.revision_uuid,
              idempotencyKey: workflowIdempotencyKey,
              policyVersionUuid: effectivePolicyVersionUuid,
              assignments,
              placements,
            })
          }
        >
          {create.isPending ? <Loader2 className="size-4 animate-spin" /> : <LockKeyhole className="size-4" />}
          {result?.status === 'cancelled' ? '重新冻结字段并创建任务' : '冻结字段并创建任务'}
        </Button>
        ) : null}

      </aside>
    </div>
  )
}

function SigningWorkspace() {
  const queryClient = useQueryClient()
  const requests = useQuery({ queryKey: ['pdf', 'handwritten', 'requests'], queryFn: fetchAssignedSigningRequests })
  const [selectedRequestUuid, setSelectedRequestUuid] = useState('')
  const effectiveRequestUuid = selectedRequestUuid || requests.data?.[0]?.request_uuid || ''
  const detail = useQuery({
    queryKey: ['pdf', 'handwritten', 'request', effectiveRequestUuid],
    queryFn: () => fetchSigningRequest(effectiveRequestUuid),
    enabled: Boolean(effectiveRequestUuid),
  })
  const revision = useQuery({
    queryKey: ['pdf', 'handwritten', 'revision', detail.data?.revision.revision_uuid],
    queryFn: () => downloadRevision(detail.data!.revision.revision_uuid),
    enabled: Boolean(detail.data?.revision.revision_uuid),
  })
  const [previewUrl, setPreviewUrl] = useState<string | null>(null)
  const [signatureReady, setSignatureReady] = useState(false)
  const [currentPassword, setCurrentPassword] = useState('')
  const [rejectReason, setRejectReason] = useState('CONTENT_REVIEW_REJECTED')
  const [operationUuid, setOperationUuid] = useState('')
  const padRef = useRef<{ toBlob: () => Promise<Blob>; clear: () => void } | null>(null)

  const operation = useQuery({
    queryKey: ['pdf', 'handwritten', 'operation', operationUuid],
    queryFn: () => fetchSigningOperation(operationUuid),
    enabled: Boolean(operationUuid),
    refetchInterval: (query) => {
      const state = query.state.data?.state
      return state && ['completed', 'failed', 'irreversible_failed', 'manual_review', 'cancelled'].includes(state) ? false : 2000
    },
  })
  const submit = useMutation({
    mutationFn: async () => {
      if (!effectiveRequestUuid) throw new Error('请先选择签名任务')
      const appearance = await padRef.current?.toBlob()
      if (!appearance) throw new Error('请先完成手写签名')
      return submitSignatureAppearance({
        requestUuid: effectiveRequestUuid,
        appearance,
        fileName: 'handwritten-signature.png',
        currentPassword,
      })
    },
    onSuccess: (result) => {
      setOperationUuid(result.operation_uuid)
      setCurrentPassword('')
    },
  })
  const reject = useMutation({
    mutationFn: () => rejectSigningRequest(effectiveRequestUuid, rejectReason),
    onSuccess: async () => {
      setSelectedRequestUuid('')
      setOperationUuid('')
      await queryClient.invalidateQueries({ queryKey: ['pdf', 'handwritten', 'requests'] })
    },
  })

  useEffect(() => {
    if (operation.data?.state === 'completed') {
      void queryClient.invalidateQueries({ queryKey: ['pdf', 'handwritten', 'requests'] })
    }
  }, [operation.data?.state, queryClient])

  const placements = useMemo<Placement[]>(() => {
    if (!detail.data?.field) return []
    return detail.data.field.slots.map((slot) => ({
      semantic_role: detail.data!.semantic_role,
      page_index: slot.page_index,
      normalized_rect: slot.normalized_rect,
    }))
  }, [detail.data])
  const terminalState = operation.data?.state
  const operationPending = Boolean(
    operationUuid
      && (!terminalState || !['completed', 'failed', 'irreversible_failed', 'manual_review', 'cancelled'].includes(terminalState)),
  )

  return (
    <div className="grid min-h-0 gap-4 xl:grid-cols-[17rem_minmax(0,1fr)_23rem]">
      <aside className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="border-b border-slate-200 px-4 py-3">
          <h2 className="text-sm font-semibold text-slate-900">待我签名</h2>
          <p className="mt-1 text-xs text-slate-500">任务严格按主检 → 审核 → 签发开放</p>
        </div>
        <div className="max-h-[72vh] space-y-2 overflow-y-auto p-2">
          {requests.isLoading ? <div className="p-4 text-center text-xs text-slate-500">正在加载…</div> : null}
          {requests.isError ? <ErrorNotice error={requests.error} fallback="签名任务加载失败" /> : null}
          {requests.data?.length === 0 ? <div className="p-6 text-center text-xs text-slate-500">当前没有轮到你的任务</div> : null}
          {requests.data?.map((request) => (
            <button
              key={request.request_uuid}
              type="button"
              className={`w-full rounded-lg border p-3 text-left transition ${
                effectiveRequestUuid === request.request_uuid
                  ? 'border-emerald-500 bg-emerald-50 shadow-sm'
                  : 'border-slate-200 bg-white hover:border-emerald-300'
              }`}
              onClick={() => {
                setSelectedRequestUuid(request.request_uuid)
                setOperationUuid('')
                setPreviewUrl(null)
                setSignatureReady(false)
                padRef.current?.clear()
              }}
            >
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-semibold text-emerald-800">{roleLabels[request.semantic_role]}</span>
                <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">第 {request.sequence} 步</span>
              </div>
              <div className="mt-2 truncate text-sm font-medium text-slate-900">{request.report_number}</div>
              <div className="mt-1 truncate text-[10px] text-slate-400">{request.request_uuid}</div>
            </button>
          ))}
        </div>
      </aside>

      <PdfPlacementWorkspace
        file={revision.data ?? null}
        placements={placements}
        editable={false}
        signaturePreview={previewUrl}
      />

      <aside className="space-y-4 xl:min-h-0 xl:overflow-y-auto xl:pr-1">
        <WorkspaceCard title="手写签名" icon={PenTool}>
          {detail.data ? (
            <div className="mb-3 grid grid-cols-2 gap-2 rounded-lg bg-slate-50 p-3 text-xs">
              <div><span className="text-slate-400">角色</span><div className="mt-1 font-medium">{roleLabels[detail.data.semantic_role]}</div></div>
              <div><span className="text-slate-400">字段</span><div className="mt-1 truncate font-medium">{detail.data.field.field_name}</div></div>
            </div>
          ) : null}
          <SignaturePad onPreviewChange={setPreviewUrl} onReadyChange={setSignatureReady} padRef={padRef} />
        </WorkspaceCard>

        <WorkspaceCard title="身份确认与数字签名" icon={LockKeyhole}>
          <label className="block text-xs font-medium text-slate-600">
            当前登录密码
            <input
              className={`${inputClass} mt-1`}
              type="password"
              autoComplete="current-password"
              value={currentPassword}
              disabled={submit.isPending || operationPending}
              onChange={(event) => setCurrentPassword(event.target.value)}
            />
          </label>
          <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-800">
            点击后会消费一次性 challenge，并锁定当前 PDF 摘要、签名字段、笔迹外观、策略和单位证书指纹。
          </div>
          <Button
            variant="primary"
            className="mt-3 w-full"
            disabled={!detail.data || !signatureReady || !currentPassword || submit.isPending || operationPending}
            onClick={() => submit.mutate()}
          >
            {submit.isPending || operationPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
            {operationPending ? '正在等待 Java 签名' : '确认并提交数字签名'}
          </Button>
        </WorkspaceCard>

        {submit.isError ? <ErrorNotice error={submit.error} fallback="签名提交失败" /> : null}
        <WorkspaceCard title="拒绝当前任务" icon={XCircle}>
          <label className="block text-xs font-medium text-slate-600">
            拒绝原因
            <select className={`${inputClass} mt-1`} value={rejectReason} onChange={(event) => setRejectReason(event.target.value)}>
              <option value="CONTENT_REVIEW_REJECTED">内容审核不通过</option>
              <option value="SIGNATURE_POSITION_INCORRECT">签名位置不正确</option>
              <option value="REPORT_DATA_INCORRECT">报告数据不正确</option>
            </select>
          </label>
          <Button
            variant="secondary"
            className="mt-3 w-full border-red-200 text-red-700 hover:bg-red-50"
            disabled={!detail.data || reject.isPending || operationPending}
            onClick={() => reject.mutate()}
          >
            {reject.isPending ? <Loader2 className="size-4 animate-spin" /> : <XCircle className="size-4" />}
            拒绝并终止本代工作流
          </Button>
          {reject.isError ? <div className="mt-3"><ErrorNotice error={reject.error} fallback="拒绝任务失败" /></div> : null}
        </WorkspaceCard>
        {operation.data ? <OperationState operation={operation.data} /> : null}
        {operation.isError ? <ErrorNotice error={operation.error} fallback="签名状态查询失败" /> : null}
      </aside>
    </div>
  )
}

function OperationState({ operation }: { operation: Awaited<ReturnType<typeof fetchSigningOperation>> }) {
  const completed = operation.state === 'completed'
  const failed = ['failed', 'irreversible_failed', 'manual_review', 'cancelled'].includes(operation.state)
  return (
    <div className={`rounded-xl border p-4 ${completed ? 'border-emerald-200 bg-emerald-50' : failed ? 'border-red-200 bg-red-50' : 'border-sky-200 bg-sky-50'}`}>
      <div className="flex items-center gap-2 text-sm font-semibold">
        {completed ? <BadgeCheck className="size-5 text-emerald-700" /> : failed ? <RefreshCw className="size-5 text-red-700" /> : <Loader2 className="size-5 animate-spin text-sky-700" />}
        {completed ? '数字签名已完成' : failed ? '签名未完成' : 'Java 正在增量签名'}
      </div>
      <dl className="mt-3 grid grid-cols-[5rem_1fr] gap-y-1 text-xs">
        <dt className="text-slate-500">状态</dt><dd className="font-medium">{operation.state} / {operation.stage}</dd>
        <dt className="text-slate-500">执行账本</dt><dd className="font-medium">{operation.java_execution_state ?? '等待注册'}</dd>
        {operation.error_code ? <><dt className="text-slate-500">错误码</dt><dd className="break-all font-medium text-red-700">{operation.error_code}</dd></> : null}
      </dl>
    </div>
  )
}

function WorkspaceCard({ title, icon: Icon, children }: { title: string; icon: typeof Upload; children: React.ReactNode }) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white shadow-sm">
      <div className="flex items-center gap-2 border-b border-slate-100 px-4 py-3">
        <Icon className="size-4 text-emerald-700" />
        <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
      </div>
      <div className="p-4">{children}</div>
    </section>
  )
}

function ModeButton({ active, href, icon: Icon, children }: { active: boolean; href: string; icon: typeof Upload; children: React.ReactNode }) {
  return (
    <a
      href={href}
      aria-current={active ? 'page' : undefined}
      className={`inline-flex h-8 items-center gap-2 rounded-md px-3 text-xs font-medium transition ${active ? 'bg-emerald-700 text-white shadow-sm' : 'text-slate-600 hover:bg-emerald-50'}`}
    >
      <Icon className="size-3.5" />{children}
    </a>
  )
}

function modeFromHash(): WorkspaceMode {
  // Arriving with a document to continue always means planning, whatever the hash.
  return window.location.hash === '#plan' || resumeDocumentUuid() !== null ? 'plan' : 'sign'
}

function rect(x: string, y: string, width = '0.16', height = '0.055') {
  return {
    x: Number(x).toFixed(6),
    y: Number(y).toFixed(6),
    width: Number(width).toFixed(6),
    height: Number(height).toFixed(6),
  }
}
