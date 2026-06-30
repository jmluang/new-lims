import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Eye, Search, XCircle } from 'lucide-react'
import { useMemo, useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { type ApiCollection, formatDateTime, inputClass, paginationParams, textareaClass } from '../system/utils'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, PaginationControls, Panel, StatusBadge } from '../system/shared'

type PublicSubmissionSample = {
  sample_name: string
  specification?: string | null
  model?: string | null
  input_voltage?: string | null
  power?: string | null
}

type PublicSubmissionTestOrder = {
  id: number
  order_no: string
  client_company: string
  sample_status: string
}

type PublicSubmission = {
  id: number
  submission_no: string
  client_company: string
  client_address?: string | null
  client_contact?: string | null
  client_phone: string
  samples_count: number
  samples?: PublicSubmissionSample[]
  status: 'pending' | 'accepted' | 'rejected'
  test_order_id?: number | null
  test_order?: PublicSubmissionTestOrder | null
  review_remark?: string | null
  submitted_at?: string | null
  accepted_at?: string | null
  rejected_at?: string | null
}

type Filters = {
  status: string
  search: string
}

const emptyFilters: Filters = {
  status: 'pending',
  search: '',
}

export function PublicTestOrderSubmissionReviewPage() {
  const queryClient = useQueryClient()
  const [filters, setFilters] = useState<Filters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [selectedSubmission, setSelectedSubmission] = useState<PublicSubmission | null>(null)
  const [rejectTarget, setRejectTarget] = useState<PublicSubmission | null>(null)
  const [reviewRemark, setReviewRemark] = useState('')

  const queryParams = useMemo(() => {
    const params: Record<string, string | number> = { ...paginationParams(page, perPage) }

    if (filters.status) {
      params.status = filters.status
    }

    return params
  }, [filters.status, page, perPage])

  const submissionsQuery = useQuery({
    queryKey: ['public-test-order-submissions', queryParams],
    queryFn: async () => {
      const response = await api.get<ApiCollection<PublicSubmission>>('/api/public-test-order-submissions', { params: queryParams })

      return response.data
    },
  })

  const acceptSubmission = useMutation({
    mutationFn: async (submission: PublicSubmission) => {
      const response = await api.post<{ data: PublicSubmission }>(`/api/public-test-order-submissions/${submission.id}/accept`)

      return response.data.data
    },
    onSuccess: async (submission) => {
      setSelectedSubmission(submission)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['public-test-order-submissions'] }),
        queryClient.invalidateQueries({ queryKey: ['test-orders'] }),
      ])
    },
  })

  const rejectSubmission = useMutation({
    mutationFn: async ({ submission, remark }: { submission: PublicSubmission; remark: string }) => {
      const response = await api.post<{ data: PublicSubmission }>(`/api/public-test-order-submissions/${submission.id}/reject`, {
        review_remark: remark,
      })

      return response.data.data
    },
    onSuccess: async () => {
      setRejectTarget(null)
      setReviewRemark('')
      await queryClient.invalidateQueries({ queryKey: ['public-test-order-submissions'] })
    },
  })

  const submissions = submissionsQuery.data?.data ?? []
  const visibleSubmissions = submissions.filter((submission) => {
    const search = filters.search.trim().toLowerCase()

    if (!search) {
      return true
    }

    return [submission.submission_no, submission.client_company, submission.client_contact, submission.client_phone]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(search))
  })

  return (
    <PageShell title="公开委托提交" description="审核客户公开页面提交的委托资料，通过后生成正式委托试验单。">
      <Panel title="筛选条件">
        <div className="grid gap-3 md:grid-cols-3">
          <Field label="状态">
            <select
              className={inputClass}
              value={filters.status}
              onChange={(event) => {
                setFilters({ ...filters, status: event.target.value })
                setPage(1)
              }}
            >
              <option value="pending">待审核</option>
              <option value="accepted">已通过</option>
              <option value="rejected">已拒绝</option>
              <option value="">全部</option>
            </select>
          </Field>
          <Field label="页面内搜索">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input
                className={`${inputClass} pl-9`}
                value={filters.search}
                onChange={(event) => setFilters({ ...filters, search: event.target.value })}
                placeholder="提交编号、单位、联系人、电话"
              />
            </div>
          </Field>
        </div>
      </Panel>

      {submissionsQuery.isError ? <ErrorNotice error={submissionsQuery.error} fallback="无法加载公开委托提交" /> : null}
      {acceptSubmission.error ? <ErrorNotice error={acceptSubmission.error} fallback="审核通过失败" /> : null}
      {rejectSubmission.error ? <ErrorNotice error={rejectSubmission.error} fallback="拒绝失败" /> : null}
      {submissionsQuery.isPending ? <LoadingState label="正在加载公开委托提交" /> : null}
      {!submissionsQuery.isPending && visibleSubmissions.length === 0 ? <EmptyState title="暂无公开委托提交" description="客户通过公开页面提交资料后，会先出现在这里等待审核。" /> : null}

      {visibleSubmissions.length > 0 ? (
        <>
          <DataTable>
            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">提交编号</th>
                <th className="px-3 py-2 font-medium">委托单位</th>
                <th className="px-3 py-2 font-medium">联系人</th>
                <th className="px-3 py-2 font-medium">样品数</th>
                <th className="px-3 py-2 font-medium">状态</th>
                <th className="px-3 py-2 font-medium">提交时间</th>
                <th className="px-3 py-2 font-medium">操作</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {visibleSubmissions.map((submission) => (
                <tr key={submission.id}>
                  <td className="px-3 py-3 text-sm font-medium text-slate-900">{submission.submission_no}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{submission.client_company}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">
                    {submission.client_contact || '-'}
                    <div className="text-xs text-slate-500">{submission.client_phone}</div>
                  </td>
                  <td className="px-3 py-3 text-sm text-slate-700">{submission.samples_count}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">
                    <StatusBadge status={submission.status} />
                  </td>
                  <td className="px-3 py-3 text-sm text-slate-700">{formatDateTime(submission.submitted_at)}</td>
                  <td className="px-3 py-3">
                    <SubmissionActions
                      isAccepting={acceptSubmission.isPending}
                      isRejecting={rejectSubmission.isPending}
                      onAccept={(target) => acceptSubmission.mutate(target)}
                      onReject={(target) => {
                        setRejectTarget(target)
                        setReviewRemark('')
                      }}
                      onView={setSelectedSubmission}
                      submission={submission}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          <div className="space-y-3 md:hidden">
            {visibleSubmissions.map((submission) => (
              <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" key={submission.id} data-mobile-public-submission-card>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h2 className="truncate text-sm font-semibold text-slate-950">{submission.client_company}</h2>
                    <p className="truncate text-xs text-slate-500">{submission.submission_no}</p>
                  </div>
                  <StatusBadge status={submission.status} />
                </div>
                <dl className="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-600">
                  <div>
                    <dt className="text-slate-400">联系人</dt>
                    <dd>{submission.client_contact || '-'} / {submission.client_phone}</dd>
                  </div>
                  <div>
                    <dt className="text-slate-400">样品数</dt>
                    <dd>{submission.samples_count}</dd>
                  </div>
                  <div className="col-span-2">
                    <dt className="text-slate-400">提交时间</dt>
                    <dd>{formatDateTime(submission.submitted_at)}</dd>
                  </div>
                </dl>
                <div className="mt-3">
                  <SubmissionActions
                    isAccepting={acceptSubmission.isPending}
                    isRejecting={rejectSubmission.isPending}
                    onAccept={(target) => acceptSubmission.mutate(target)}
                    onReject={(target) => {
                      setRejectTarget(target)
                      setReviewRemark('')
                    }}
                    onView={setSelectedSubmission}
                    submission={submission}
                  />
                </div>
              </article>
            ))}
          </div>
        </>
      ) : null}

      <PaginationControls
        meta={submissionsQuery.data?.meta}
        page={page}
        perPage={perPage}
        onPageChange={setPage}
        onPerPageChange={(nextPerPage) => {
          setPerPage(nextPerPage)
          setPage(1)
        }}
      />

      <SubmissionDetailModal
        isAccepting={acceptSubmission.isPending}
        onAccept={(submission) => acceptSubmission.mutate(submission)}
        onClose={() => setSelectedSubmission(null)}
        onReject={(submission) => {
          setRejectTarget(submission)
          setReviewRemark('')
        }}
        submission={selectedSubmission}
      />

      <Modal open={rejectTarget !== null} title="拒绝公开委托提交" description="拒绝后不会生成正式委托试验单。" onClose={() => setRejectTarget(null)}>
        <div className="space-y-4">
          <Field label="拒绝备注">
            <textarea className={textareaClass} value={reviewRemark} onChange={(event) => setReviewRemark(event.target.value)} placeholder="可填写拒绝原因，便于后续追踪" />
          </Field>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setRejectTarget(null)} disabled={rejectSubmission.isPending}>
              取消
            </Button>
            <Button
              variant="danger"
              onClick={() => {
                if (rejectTarget) {
                  rejectSubmission.mutate({ submission: rejectTarget, remark: reviewRemark })
                }
              }}
              disabled={rejectSubmission.isPending}
            >
              确认拒绝
            </Button>
          </div>
        </div>
      </Modal>
    </PageShell>
  )
}

function SubmissionActions({
  isAccepting,
  isRejecting,
  onAccept,
  onReject,
  onView,
  submission,
}: {
  isAccepting: boolean
  isRejecting: boolean
  onAccept: (submission: PublicSubmission) => void
  onReject: (submission: PublicSubmission) => void
  onView: (submission: PublicSubmission) => void
  submission: PublicSubmission
}) {
  const disabled = isAccepting || isRejecting
  const isPending = submission.status === 'pending'

  return (
    <div className="flex flex-wrap gap-2">
      <Button variant="secondary" onClick={() => onView(submission)}>
        <Eye className="size-4" aria-hidden="true" />
        查看
      </Button>
      {isPending ? (
        <PermissionGate resource="test_orders" action="create">
          <Button variant="primary" onClick={() => onAccept(submission)} disabled={disabled}>
            <CheckCircle2 className="size-4" aria-hidden="true" />
            通过
          </Button>
        </PermissionGate>
      ) : null}
      {isPending ? (
        <PermissionGate resource="test_orders" action="create">
          <Button variant="danger" onClick={() => onReject(submission)} disabled={disabled}>
            <XCircle className="size-4" aria-hidden="true" />
            拒绝
          </Button>
        </PermissionGate>
      ) : null}
    </div>
  )
}

function SubmissionDetailModal({
  isAccepting,
  onAccept,
  onClose,
  onReject,
  submission,
}: {
  isAccepting: boolean
  onAccept: (submission: PublicSubmission) => void
  onClose: () => void
  onReject: (submission: PublicSubmission) => void
  submission: PublicSubmission | null
}) {
  return (
    <Modal
      open={submission !== null}
      title={submission ? `公开委托提交 - ${submission.submission_no}` : '公开委托提交'}
      description="核对客户提交资料，通过后将生成正式委托试验单。"
      size="wide"
      onClose={onClose}
      actions={
        submission?.status === 'pending' ? (
          <PermissionGate resource="test_orders" action="create">
            <Button variant="primary" onClick={() => onAccept(submission)} disabled={isAccepting}>
              <CheckCircle2 className="size-4" aria-hidden="true" />
              通过并生成委托单
            </Button>
          </PermissionGate>
        ) : null
      }
    >
      {submission ? (
        <div className="space-y-4">
          <section className="grid gap-3 rounded-lg border border-emerald-900/10 bg-slate-50 p-4 text-sm md:grid-cols-3">
            <InfoItem label="委托单位" value={submission.client_company} />
            <InfoItem label="联系人" value={submission.client_contact || '-'} />
            <InfoItem label="联系电话" value={submission.client_phone} />
            <InfoItem label="公司地址" value={submission.client_address || '-'} />
            <InfoItem label="状态" value={submission.status} />
            <InfoItem label="提交时间" value={formatDateTime(submission.submitted_at)} />
            {submission.test_order ? <InfoItem label="生成委托单" value={submission.test_order.order_no} /> : null}
            {submission.review_remark ? <InfoItem label="拒绝备注" value={submission.review_remark} /> : null}
          </section>

          <section className="rounded-lg border border-emerald-900/10 bg-white">
            <div className="border-b border-emerald-900/10 px-4 py-3 text-sm font-semibold text-slate-900">样品信息</div>
            <div className="divide-y divide-slate-100">
              {(submission.samples ?? []).map((sample, index) => (
                <div className="grid gap-2 px-4 py-3 text-sm md:grid-cols-5" key={`${sample.sample_name}-${index}`}>
                  <InfoItem label={`样品 #${index + 1}`} value={sample.sample_name} />
                  <InfoItem label="规格" value={sample.specification || '-'} />
                  <InfoItem label="型号" value={sample.model || '-'} />
                  <InfoItem label="输入电压" value={sample.input_voltage || '-'} />
                  <InfoItem label="功率" value={sample.power || '-'} />
                </div>
              ))}
              {(submission.samples ?? []).length === 0 ? <div className="px-4 py-6 text-center text-sm text-slate-500">暂无样品信息</div> : null}
            </div>
          </section>

          {submission.status === 'pending' ? (
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => onReject(submission)}>
                拒绝
              </Button>
              <Button variant="primary" onClick={() => onAccept(submission)} disabled={isAccepting}>
                通过并生成委托单
              </Button>
            </div>
          ) : null}
        </div>
      ) : null}
    </Modal>
  )
}

function InfoItem({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className="mt-1 break-words font-medium text-slate-900">{value}</dd>
    </div>
  )
}
