// @vitest-environment jsdom
import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, expect, it, vi } from 'vitest'
import { CustomerForm } from '../CustomerForm'

afterEach(() => vi.unstubAllGlobals())
it('shows required errors and submits a valid customer form', async () => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  const container = document.createElement('div')
  document.body.append(container)
  const root = createRoot(container)
  const submit = vi.fn(async () => {})
  const settle = async (action: () => void) => act(async () => { action(); await new Promise(resolve => setTimeout(resolve, 30)) })
  try {
    await settle(() => root.render(<CustomerForm submitting={false} error={null} onSubmit={submit} onCancel={() => {}} />))
    await settle(() => container.querySelector<HTMLButtonElement>('button[type="submit"]')!.click())
    expect(container.textContent).toContain('请填写名称')
    await settle(() => {
      const input = container.querySelector<HTMLInputElement>('input[name="name"]')!
      Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, 'Flow Client')
      input.dispatchEvent(new Event('input', { bubbles: true }))
    })
    await settle(() => container.querySelector<HTMLButtonElement>('button[type="submit"]')!.click())
    expect(submit).toHaveBeenCalledTimes(1)
    expect(submit).toHaveBeenCalledWith(expect.objectContaining({ name: 'Flow Client', status: 'active' }))
  } finally {
    await settle(() => root.unmount())
    container.remove()
  }
})
