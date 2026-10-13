// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { PublicTestOrderSubmissionReviewPage, type PublicSubmission } from '../PublicTestOrderSubmissionReviewPage'

const server = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }))
vi.mock('../../../lib/api', () => ({ api: server }))
vi.mock('../../auth/useCurrentUser', () => ({ useEffectivePermissions: () => ({
  data: { resources: { test_orders: { actions: { create: true } } } },
}) }))
let root: Root
let container: HTMLDivElement
let client: QueryClient
async function settle(action?: () => void) {
  await act(async () => { action?.(); await new Promise((resolve) => setTimeout(resolve, 20)) })
}
function submission(id: number): PublicSubmission {
  return { id, submission_no: `SUB-${id}`, client_company: `Client ${id}`, client_phone: `1380000000${id}`,
    client_address: `New address ${id}`, samples_count: 1, status: 'pending',
    matched_customer: { name: `Client ${id}`, address: `Old address ${id}` } }
}
beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  const data = { data: [submission(1), submission(2)], meta: { current_page: 1, per_page: 15, total: 2 } }
  server.get.mockReset().mockResolvedValue({ data })
  server.post.mockReset().mockResolvedValue({ data: { data: { ...submission(2), status: 'accepted' } } })
  client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
  client.setQueryData(['public-test-order-submissions', { page: 1, per_page: 15 }], data)
  container = document.createElement('div')
  document.body.append(container)
  root = createRoot(container)
})
afterEach(async () => {
  await settle(() => root.unmount())
  client.clear()
  container.remove()
  vi.unstubAllGlobals()
})

describe('submission review identity', () => {
  it('does not carry customer profile sync consent from one submission to another', async () => {
    await settle(() => root.render(<QueryClientProvider client={client}><PublicTestOrderSubmissionReviewPage /></QueryClientProvider>))
    await settle(() => container.querySelectorAll<HTMLTableRowElement>('tbody tr')[0].querySelector('button')!.click())
    await settle(() => container.querySelector<HTMLInputElement>('input[type="checkbox"]')!.click())
    expect(container.querySelector<HTMLInputElement>('input[type="checkbox"]')!.checked).toBe(true)
    await settle(() => container.querySelector<HTMLButtonElement>('button[aria-label="关闭"]')!.click())
    await settle(() => container.querySelectorAll<HTMLTableRowElement>('tbody tr')[1].querySelector('button')!.click())
    await settle(() => [...container.querySelectorAll('button')]
      .find((button) => button.textContent?.includes('通过并生成委托单'))!.click())
    expect(server.post).toHaveBeenCalledWith('/api/public-test-order-submissions/2/accept', { sync_customer_profile: false })
  })
})
