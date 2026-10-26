// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, type ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, expect, it, vi } from 'vitest'
import { queryClient as applicationClient } from '../lib/query-client'
import { emptySampleFlowRecordFilters } from '../features/samples/sampleFlowRecordPageState'

const state = vi.hoisted(() => ({ pathname: '', get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), navigate: vi.fn() }))
vi.mock('../lib/api', () => ({ api: state }))
vi.mock('@tanstack/react-router', () => ({
  Link: ({ children }: { children: ReactNode }) => <span>{children}</span>,
  useRouterState: () => state.pathname,
  useNavigate: () => state.navigate,
}))
vi.mock('../components/app/PermissionGate', () => ({ PermissionGate: ({ children }: { children: ReactNode }) => children }))
vi.mock('../components/app/QrScannerPanel', () => ({
  QrScannerPanel: ({ onDetected }: { onDetected: (value: string) => void }) => <button onClick={() => onDetected('S-1')}>Detect</button>,
}))
vi.mock('../features/auth/useCurrentUser', () => ({
  useCurrentUser: () => ({ data: { id: 2, name: 'Operator' } }),
  useEffectivePermissions: () => ({ data: { resources: {} } }),
}))
vi.mock('../features/system/users/UserForm', () => ({
  UserForm: ({ user }: { user: unknown }) => <output>{JSON.stringify(user)}</output>,
}))
vi.mock('../features/customers/CustomerForm', () => ({
  CustomerForm: ({ onSubmit }: { onSubmit: (value: unknown) => void }) => <button onClick={() => onSubmit({ name: 'Updated' })}>Save</button>,
}))
vi.mock('../features/standards/StandardForm', () => ({
  StandardForm: ({ onSubmit }: { onSubmit: (value: unknown) => void }) => <button onClick={() => onSubmit({ chinese_name: 'Updated' })}>Save</button>,
}))
vi.mock('../features/test-orders/TestOrderForm', () => ({
  TestOrderForm: ({ customers, standards }: { customers: unknown; standards: unknown }) => <output>{JSON.stringify({ customers, standards })}</output>,
}))

afterEach(() => vi.unstubAllGlobals())

async function fixture() {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  vi.clearAllMocks()
  const client = new QueryClient({ defaultOptions: applicationClient.getDefaultOptions() })
  const container = document.createElement('div')
  document.body.append(container)
  const root = createRoot(container)
  const settle = async (action: () => void) => act(async () => { action(); await new Promise(resolve => setTimeout(resolve, 50)) })
  return {
    client, container, settle,
    render: async (page: ReactNode) => {
      await settle(() => root.render(<QueryClientProvider client={client}>{page}</QueryClientProvider>))
      await settle(() => {})
    },
    click: async (label: string) => {
      const button = [...container.querySelectorAll('button')].find(button => button.textContent?.trim() === label)
      expect(button, `Missing action: ${label}`).toBeDefined()
      await settle(() => button!.click())
      await settle(() => {})
    },
    close: async () => { await settle(() => root.unmount()); client.clear(); container.remove() },
  }
}

it.each(['lock', 'unlock', 'reset-password'])('reopens fresh user security state after %s from the list', async operation => {
  const f = await fixture()
  let user = { id: 1, name: 'Operator', email: 'operator@example.test', groups: [], status: operation === 'unlock' ? 'locked' : 'active', must_change_password: false }
  f.client.setQueryData(['system-user', 1], { ...user })
  f.client.setQueryData(['system-groups'], [])
  f.client.setQueryData(['system-departments'], [])
  state.get.mockImplementation(async (url: string) => {
    // A slow detail request must not expose the security flags from before the mutation.
    if (url === '/api/system/users/1') return new Promise(() => {})
    return { data: { data: url === '/api/system/users' ? [{ ...user }] : { ...user } } }
  })
  state.post.mockImplementation(async () => {
    user = { ...user, status: operation === 'lock' ? 'locked' : 'active', must_change_password: operation === 'reset-password' }
    return { data: { data: user } }
  })
  try {
    const { UserListPage } = await import('../features/system/users/UserListPage')
    const { UserFormPage } = await import('../features/system/users/UserFormPage')
    await f.render(<UserListPage />)
    await f.click(operation === 'lock' ? '锁定' : operation === 'unlock' ? '解锁' : '重置密码')
    expect(state.post).toHaveBeenCalledTimes(1)
    state.pathname = '/system/users/1/edit'
    await f.render(<UserFormPage />)
    expect(JSON.parse(f.container.querySelector('output')!.textContent!)).toMatchObject({ status: user.status, must_change_password: user.must_change_password })
  } finally { await f.close() }
})

it.each([
  { kind: 'customer', operation: 'edit' }, { kind: 'standard', operation: 'edit' },
  { kind: 'customer', operation: 'create' }, { kind: 'standard', operation: 'create' },
  { kind: 'customer', operation: 'disable' }, { kind: 'standard', operation: 'disable' },
])('refreshes order form options after $operation of a $kind', async ({ kind, operation }) => {
  const f = await fixture()
  const original = { customers: [{ id: 1, name: 'Original' }], standards: [{ id: 1, chinese_name: 'Original' }] }
  let options = original
  f.client.setQueryData(['test-order-form-options'], original)
  f.client.setQueryData([kind, 1], { data: kind === 'customer' ? original.customers[0] : original.standards[0] })
  state.get.mockImplementation(async (url: string) => ({ data: { data: url.endsWith('/form-options') ? options
    : url === '/api/customers' ? options.customers : url === '/api/standards' ? options.standards
      : kind === 'customer' ? options.customers[0] : options.standards[0], meta: { fields: {} } } }))
  const save = async () => {
    options = kind === 'customer' ? { ...original, customers: operation === 'disable' ? [] : [{ id: 1, name: 'Updated' }] }
      : { ...original, standards: operation === 'disable' ? [] : [{ id: 1, chinese_name: 'Updated' }] }
    return { data: {} }
  }
  state.put.mockImplementation(save)
  state.post.mockImplementation(save)
  state.delete.mockImplementation(save)
  const path = kind === 'customer' ? '/customers' : '/standards'
  state.pathname = operation === 'create' ? `${path}/new` : `${path}/1/edit`
  try {
    const Page = operation === 'disable' ? kind === 'customer' ? (await import('../features/customers/CustomerListPage')).CustomerListPage
      : (await import('../features/standards/StandardListPage')).StandardListPage
      : kind === 'customer' ? (await import('../features/customers/CustomerFormPage')).CustomerFormPage
      : (await import('../features/standards/StandardFormPage')).StandardFormPage
    await f.render(<Page />)
    await f.click(operation === 'disable' ? kind === 'customer' ? '禁用' : '删除' : 'Save')
    expect(operation === 'disable' ? state.delete : operation === 'create' ? state.post : state.put).toHaveBeenCalledTimes(1)
    state.pathname = '/test-orders/new'
    const { TestOrderFormPage } = await import('../features/test-orders/TestOrderFormPage')
    await f.render(<TestOrderFormPage />)
    expect(JSON.parse(f.container.querySelector('output')!.textContent!)).toEqual(options)
  } finally { await f.close() }
})

it.each(['list-claim', 'list-return', 'scan', 'detail'])('shows fresh sample details and flow history after %s', async operation => {
  const f = await fixture()
  let sample = { id: 1, sample_no: 'S-1', sample_name: 'Lamp', status: 'pending', current_holder: '样品室', current_location: 'Room', quantity: 1 }
  let flows: Array<{ id: number; action_type: string }> = []
  f.client.setQueryData(['sample', 1], { ...sample })
  f.client.setQueryData(['sample-flows', 1], [])
  f.client.setQueryData(['sample-flow-card', 1], { sample: { ...sample }, flows: [] })
  f.client.setQueryData(['sample-flow-records', emptySampleFlowRecordFilters, 1, 15], { data: [] })
  state.get.mockImplementation(async (url: string) => ({ data: { data: url === '/api/samples' ? [{ ...sample }]
    : url === '/api/samples/scan-lookup' ? { sample, available_actions: ['lend'] }
      : url === '/api/equipment-locations' ? [{ id: 1, name: 'Room', code: 'ROOM', status: 'active' }]
        : url === '/api/sample-flows' ? flows.map(flow => ({ ...flow, sample_id: 1, sample }))
          : url.endsWith('/flow-card') ? { sample, flows } : url.endsWith('/flows') ? flows : sample } }))
  state.post.mockImplementation(async () => {
    sample = { ...sample, status: operation === 'list-return' ? 'returned' : 'testing', current_holder: operation === 'list-return' ? '客户' : 'Operator' }
    flows = [{ id: 1, action_type: operation === 'list-return' ? 'return_client' : 'lend' }]
    return { data: {} }
  })
  try {
    const { SampleListPage } = await import('../features/samples/SampleListPage')
    const { SampleDetailPage } = await import('../features/samples/SampleDetailPage')
    state.pathname = '/samples/1'
    if (operation === 'scan') {
      const { SampleScanPage } = await import('../features/samples/SampleScanPage')
      await f.render(<SampleScanPage />)
      await f.click('Detect')
      await f.click('领用')
      await f.click('确认')
    } else if (operation === 'detail') {
      await f.render(<SampleDetailPage />)
      await f.click('新增流转')
    } else {
      await f.render(<SampleListPage />)
      await f.click(operation === 'list-claim' ? '领样' : '退客户')
      if (operation === 'list-return') await f.click('确认退客户')
    }
    expect(state.post).toHaveBeenCalledTimes(1)
    state.pathname = '/samples/1'
    await f.render(<SampleDetailPage />)
    expect.soft(f.client.getQueryData(['sample', 1])).toMatchObject(sample)
    expect.soft(f.client.getQueryData(['sample-flows', 1])).toEqual(flows)
    expect.soft(f.client.getQueryData(['sample-flow-card', 1])).toMatchObject({ sample, flows })
    const { SampleFlowRecordsPage } = await import('../features/samples/SampleFlowRecordsPage')
    await f.render(<SampleFlowRecordsPage />)
    expect(f.container.textContent).toContain('S-1')
  } finally { await f.close() }
})
