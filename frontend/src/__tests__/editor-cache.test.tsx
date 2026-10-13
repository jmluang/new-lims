// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, type ComponentType } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, expect, it, vi } from 'vitest'
import { queryClient as applicationClient } from '../lib/query-client'

const state = vi.hoisted(() => ({ pathname: '', record: {} as Record<string, unknown>, get: vi.fn(), put: vi.fn(), post: vi.fn(), navigate: vi.fn() }))
vi.mock('../lib/api', () => ({ api: { get: state.get, put: state.put, post: state.post } }))
vi.mock('@tanstack/react-router', () => ({
  Link: ({ children }: { children: React.ReactNode }) => <span>{children}</span>,
  useRouterState: () => state.pathname,
  useNavigate: () => state.navigate,
  useLoaderData: () => undefined,
}))

type EditorProps = { onSubmit: (value: Record<string, unknown>) => void; customer?: Record<string, unknown>; equipment?: Record<string, unknown>; standard?: Record<string, unknown>; order?: Record<string, unknown>; user?: Record<string, unknown> }
function Editor(props: EditorProps) {
  const record = props.customer ?? props.equipment ?? props.standard ?? props.order ?? props.user
  return <><output>{String(record?.name ?? '')}</output><button onClick={() => props.onSubmit({ name: 'Updated', group_ids: [] })}>Save</button></>
}
// Exercise the page mutations and query cache; field validation has separate interaction coverage.
vi.mock('../features/customers/CustomerForm', () => ({ CustomerForm: Editor }))
vi.mock('../features/equipment/EquipmentForm', () => ({ EquipmentForm: Editor }))
vi.mock('../features/standards/StandardForm', () => ({ StandardForm: Editor }))
vi.mock('../features/test-orders/TestOrderForm', () => ({ TestOrderForm: Editor }))
vi.mock('../features/system/users/UserForm', () => ({ UserForm: Editor }))
vi.mock('../features/test-orders/TestOrderEntrustForm', () => ({ TestOrderEntrustForm: Editor }))
vi.mock('../features/test-orders/TestOrderChangeHistory', () => ({ TestOrderChangeHistory: () => null }))
vi.mock('../features/auth/useCurrentUser', () => ({ useEffectivePermissions: () => ({ data: { resources: { lm79_reports: { actions: { update: true } } } } }) }))
vi.mock('../features/reports/ReportEquipmentFields', () => ({ ReportEquipmentFields: () => null }))

afterEach(() => vi.unstubAllGlobals())
it('shows the new change history after saving through the standalone order editor', async () => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  state.pathname = '/test-orders/1/edit'
  state.record = { id: 1, name: 'Original' }
  let history: unknown[] = []
  state.get.mockReset().mockImplementation(async (url: string) => {
    if (url.endsWith('/history')) return { data: { data: history } }
    return { data: { data: { ...state.record } } }
  })
  state.put.mockReset().mockImplementation(async () => {
    state.record = { id: 1, name: 'Updated' }
    history = [{ id: 1, occurred_at: '2026-10-13 09:00:00', actor_name: 'Editor', changes: [{ field: '名称', old_value: 'Original', new_value: 'Updated' }] }]
    return { data: { data: state.record } }
  })
  state.navigate.mockReset()
  const client = new QueryClient({ defaultOptions: applicationClient.getDefaultOptions() })
  client.setQueryData(['test-order', 1], { data: state.record })
  client.setQueryData(['test-order-form-options'], { customers: [], standards: [] })
  client.setQueryData(['test-order-history', 1], [])
  const container = document.createElement('div')
  document.body.append(container)
  const root = createRoot(container)
  const settle = async (action: () => void) => act(async () => { action(); await new Promise(resolve => setTimeout(resolve, 40)) })
  try {
    const { TestOrderFormPage } = await import('../features/test-orders/TestOrderFormPage')
    const { TestOrderChangeHistory } = await vi.importActual<typeof import('../features/test-orders/TestOrderChangeHistory')>('../features/test-orders/TestOrderChangeHistory')
    await settle(() => root.render(<QueryClientProvider client={client}><TestOrderFormPage /></QueryClientProvider>))
    await settle(() => container.querySelector('button')!.click())
    expect(state.put).toHaveBeenCalledTimes(1)
    await settle(() => root.render(<QueryClientProvider client={client}><TestOrderChangeHistory orderId={1} /></QueryClientProvider>))
    await settle(() => {})
    expect(container.textContent).toContain('Updated')
  } finally {
    await settle(() => root.unmount())
    client.clear()
    container.remove()
  }
})

it.each([
  { path: '/customers/1/edit', key: ['customer', 1], module: () => import('../features/customers/CustomerFormPage'), exported: 'CustomerFormPage' },
  { path: '/equipment/1/edit', key: ['equipment-record', 1], module: () => import('../features/equipment/EquipmentFormPage'), exported: 'EquipmentFormPage' },
  { path: '/standards/1/edit', key: ['standard', 1], module: () => import('../features/standards/StandardFormPage'), exported: 'StandardFormPage' },
  { path: '/test-orders/1/edit', key: ['test-order', 1], module: () => import('../features/test-orders/TestOrderFormPage'), exported: 'TestOrderFormPage' },
  { path: '/system/users/1/edit', key: ['system-user', 1], module: () => import('../features/system/users/UserFormPage'), exported: 'UserFormPage' },
])('reopens the saved record without stale values at $path', async ({ path, key, module, exported }) => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  state.pathname = path
  state.record = { id: 1, name: 'Original' }
  state.navigate.mockReset()
  state.get.mockReset().mockImplementation(async (url: string) => {
    if (url.endsWith('form-options')) return { data: { data: { customers: [], standards: [] } } }
    if (['/api/equipment-locations', '/api/equipment-systems', '/api/system/groups', '/api/system/departments'].includes(url)) return { data: { data: [] } }
    return { data: { data: { ...state.record } } }
  })
  state.put.mockReset().mockImplementation(async () => { state.record = { id: 1, name: 'Updated' }; return { data: {} } })
  const client = new QueryClient({ defaultOptions: applicationClient.getDefaultOptions() })
  client.setQueryData(key, key[0] === 'system-user' ? { ...state.record } : { data: { ...state.record } })
  for (const optionKey of ['equipment-locations', 'equipment-systems', 'system-groups', 'system-departments']) {
    client.setQueryData([optionKey], [])
  }
  client.setQueryData(['test-order-form-options'], { customers: [], standards: [] })
  const container = document.createElement('div')
  document.body.append(container)
  const root = createRoot(container)
  const settle = async (action: () => void) => act(async () => { action(); await new Promise(resolve => setTimeout(resolve, 40)) })
  try {
    const Page = (await module() as unknown as Record<string, ComponentType>)[exported]
    const render = () => root.render(<QueryClientProvider client={client}><Page /></QueryClientProvider>)
    await settle(render)
    expect(container.querySelector('output')?.textContent).toBe('Original')
    await settle(() => container.querySelector('button')!.click())
    expect(state.put).toHaveBeenCalledTimes(1)
    expect(state.navigate).toHaveBeenCalledTimes(1)
    await settle(() => root.render(null))
    await settle(render)
    expect(container.querySelector('output')?.textContent).toBe('Updated')
    expect(client.getQueryData(key)).toBeDefined()
  } finally {
    await settle(() => root.unmount())
    client.clear()
    container.remove()
  }
})

it.each(['calibration', 'report'])('reopens saved %s data without reverting local form state', async (kind) => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  const isReport = kind === 'report'
  state.pathname = isReport ? '/reports/lm79/7' : '/equipment/calibrations/1/edit'
  state.record = isReport ? {
    id: 7, sample_id: 1, report_number: 'Original', locked: false, document_uuid: null, media: [],
    data: { values: {}, standards: [], equipment: [] }, sample_snapshot: { sample_no: 'S-1', sample_name: 'Lamp' },
  } : {
    id: 1, calibration_name: 'Original', calibration_time: '2026-10-13 09:00:00', result: 'qualified', remark: '', attachment_files: [], photo_files: [],
    devices: [{ id: 1, equipment_id: 1, equipment_no: 'EQ-1', equipment_name: 'Instrument' }],
    standards: [{ id: 2, equipment_id: 2, standard_no: 'STD-1', standard_name: 'Standard' }],
  }
  state.get.mockReset().mockImplementation(async () => ({ data: { data: { ...state.record } } }))
  const save = async () => { state.record = { ...state.record, report_number: 'Updated', calibration_name: 'Updated' }; return { data: { data: { ...state.record } } } }
  state.put.mockReset().mockImplementation(save)
  state.post.mockReset().mockImplementation(save)
  state.navigate.mockReset()
  const client = new QueryClient({ defaultOptions: applicationClient.getDefaultOptions() })
  client.setQueryData(isReport ? ['lm79-report', '7'] : ['equipment-calibration', 1], { ...state.record })
  client.setQueryData(['lm79-options'], { groups: [], defaults: {} })
  client.setQueryData(['calibration-projects', 'options'], [])
  const container = document.createElement('div')
  document.body.append(container)
  const root = createRoot(container)
  const settle = async (action: () => void) => act(async () => { action(); await new Promise(resolve => setTimeout(resolve, 40)) })
  const input = () => isReport ? container.querySelector<HTMLInputElement>('input[aria-label="报告编号"]')!
    : [...container.querySelectorAll('label')].find(label => label.textContent?.includes('定标名称'))!.querySelector<HTMLInputElement>('input')!
  try {
    const Page = isReport ? (await import('../features/reports/Lm79ReportPage')).Lm79ReportPage
      : (await import('../features/equipment/EquipmentCalibrationFormPage')).EquipmentCalibrationFormPage
    const render = () => root.render(<QueryClientProvider client={client}><Page /></QueryClientProvider>)
    await settle(render)
    expect(input().value).toBe('Original')
    await settle(() => {
      Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input(), 'Updated')
      input().dispatchEvent(new Event('input', { bubbles: true }))
    })
    await settle(() => [...container.querySelectorAll('button')].find(button => button.textContent?.trim() === (isReport ? '保存草稿' : '保存'))!.click())
    expect(isReport ? state.post : state.put).toHaveBeenCalledTimes(1)
    await settle(() => root.render(null))
    await settle(render)
    expect(input().value).toBe('Updated')
  } finally {
    await settle(() => root.unmount())
    client.clear()
    container.remove()
  }
})

it.each([
  { path: '/standards/1', key: ['standard', 1], module: () => import('../features/standards/StandardDetailPage'), exported: 'StandardDetailPage' },
  { path: '/test-orders/1', key: ['test-order', 1], module: () => import('../features/test-orders/TestOrderDetailPage'), exported: 'TestOrderDetailPage' },
])('reads the same cached resource as the editor at $path', async ({ path, key, module, exported }) => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  state.pathname = path
  const client = new QueryClient({ defaultOptions: applicationClient.getDefaultOptions() })
  client.setQueryData(key, { data: { id: 1, name: 'Original', chinese_name: 'Original', std_no: 'FLOW-STD-1', order_no: 'FLOW-ORDER-1', catalogs: [], items: [] } })
  const container = document.createElement('div')
  document.body.append(container)
  const root = createRoot(container)
  try {
    const Page = (await module() as unknown as Record<string, ComponentType>)[exported]
    await act(async () => root.render(<QueryClientProvider client={client}><Page /></QueryClientProvider>))
    expect(container.textContent).toContain('Original')
  } finally {
    await act(async () => root.unmount())
    client.clear()
    container.remove()
  }
})
