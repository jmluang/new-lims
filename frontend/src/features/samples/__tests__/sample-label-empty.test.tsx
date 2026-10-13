// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { expect, it, vi } from 'vitest'
import { PageHeaderContext } from '../../../components/app/PageHeaderContext'
import { SampleLabelPrintPage } from '../SampleLabelPrintPage'

const server = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('../../../lib/api', () => ({ api: server }))
vi.mock('../sampleLabelPrintState', () => ({ loadSampleLabelIds: () => [] }))
vi.mock('@tanstack/react-router', () => ({ Link: ({ children }: { children: React.ReactNode }) => <span>{children}</span> }))

it('explains how to select samples without fetching or printing when there are no label IDs', async () => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  const client = new QueryClient()
  const setHeader = vi.fn()
  const print = vi.spyOn(window, 'print').mockImplementation(() => {})
  const container = document.createElement('div')
  document.body.append(container)
  const root = createRoot(container)
  try {
    await act(async () => root.render(<QueryClientProvider client={client}><PageHeaderContext.Provider value={setHeader}><SampleLabelPrintPage /></PageHeaderContext.Provider></QueryClientProvider>))
    expect(container.textContent).toContain('暂无待打印样品')
    expect(setHeader).toHaveBeenLastCalledWith({ title: '样品标签打印', description: '请先接收样品，或从样品信息中选择需要打印的样品。' })
    expect(container.querySelector('button')?.disabled).toBe(true)
    expect(server.post).not.toHaveBeenCalled()
    expect(print).not.toHaveBeenCalled()
  } finally {
    await act(async () => root.unmount())
    client.clear()
    container.remove()
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  }
})
