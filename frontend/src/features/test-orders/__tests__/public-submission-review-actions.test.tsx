import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import type { PublicSubmission } from '../PublicTestOrderSubmissionReviewPage'
import { PublicTestOrderSubmissionReviewPage, SubmissionActions, SubmissionDetailModal } from '../PublicTestOrderSubmissionReviewPage'

const permissionState = vi.hoisted(() => ({
  data: {
    resources: {
      test_orders: {
        actions: {
          create: true,
        },
        fields: {},
      },
    },
  },
}))

vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => permissionState,
}))

const pendingSubmission: PublicSubmission = {
  id: 1,
  submission_no: 'PUB-20260630-001',
  client_company: 'Guangzhou Client',
  client_address: 'Guangzhou',
  client_contact: 'Alice',
  client_phone: '13800000000',
  samples_count: 1,
  samples: [
    {
      sample_name: 'Lamp',
      specification: 'A1',
      model: 'M1',
      input_voltage: '220V',
      power: '10W',
    },
  ],
  status: 'pending',
  submitted_at: '2026-06-30 10:00:00',
}

const acceptedSubmission: PublicSubmission = {
  ...pendingSubmission,
  id: 2,
  submission_no: 'PUB-20260630-002',
  status: 'accepted',
}

const rejectedSubmission: PublicSubmission = {
  ...pendingSubmission,
  id: 3,
  submission_no: 'PUB-20260630-003',
  status: 'rejected',
}

describe('PublicTestOrderSubmissionReviewPage review actions', () => {
  it('loads all review statuses by default and labels accepted or rejected submissions', () => {
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
      },
    })
    queryClient.setQueryData(['public-test-order-submissions', { page: 1, per_page: 15 }], {
      data: [acceptedSubmission, rejectedSubmission],
      meta: { current_page: 1, per_page: 15, total: 2 },
    })

    const html = renderToStaticMarkup(
      <QueryClientProvider client={queryClient}>
        <PublicTestOrderSubmissionReviewPage />
      </QueryClientProvider>,
    )

    expect(html).toContain('PUB-20260630-002')
    expect(html).toContain('PUB-20260630-003')
    expect(html).toContain('已同意')
    expect(html).toContain('已拒绝')
  })

  it('keeps accept and reject actions out of list rows', () => {
    const html = renderToStaticMarkup(
      <SubmissionActions
        onView={() => {
          // noop
        }}
        submission={pendingSubmission}
      />,
    )

    expect(html).toContain('查看')
    expect(html).not.toContain('通过')
    expect(html).not.toContain('拒绝')
  })

  it('renders the pending review actions only once in the detail modal footer', () => {
    const html = renderToStaticMarkup(
      <SubmissionDetailModal
        isAccepting={false}
        onAccept={() => {
          // noop
        }}
        onClose={() => {
          // noop
        }}
        onReject={() => {
          // noop
        }}
        submission={pendingSubmission}
      />,
    )

    expect(html.match(/拒绝/g)).toHaveLength(1)
    expect(html.match(/通过并生成委托单/g)).toHaveLength(1)
  })
})
