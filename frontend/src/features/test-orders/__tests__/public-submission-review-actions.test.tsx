import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import type { PublicSubmission } from '../PublicTestOrderSubmissionReviewPage'
import { SubmissionActions, SubmissionDetailModal } from '../PublicTestOrderSubmissionReviewPage'

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

describe('PublicTestOrderSubmissionReviewPage review actions', () => {
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
