// @vitest-environment jsdom
import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, expect, it, vi } from 'vitest'
import { MediaDownloadButton } from '../InspectionMediaComponents'

const get = vi.hoisted(() => vi.fn())
vi.mock('../../../lib/api', () => ({ api: { get } }))
afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals() })

it('fetches an attachment PDF once and downloads the same local blob', async () => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  const pdf = new Blob(['%PDF'], { type: 'application/pdf' })
  get.mockResolvedValue({ data: pdf })
  const createObjectURL = vi.fn(() => 'blob:attachment-pdf')
  vi.stubGlobal('URL', Object.assign(class extends URL {}, { createObjectURL, revokeObjectURL: vi.fn() }))
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
    expect(this.href).toBe('blob:attachment-pdf')
    expect(this.download).toBe('report.pdf')
  })
  const container = document.createElement('div')
  document.body.append(container)
  const root = createRoot(container)
  try {
    await act(async () => root.render(<MediaDownloadButton baseUrl="/api/lm79-reports" recordId={7} media={{ id: 9, file_name: 'report.pdf' }} />))
    await act(async () => { container.querySelector('button')!.click(); await new Promise(resolve => setTimeout(resolve, 20)) })
    expect(get).toHaveBeenCalledTimes(1)
    expect(get).toHaveBeenCalledWith('/api/lm79-reports/7/media/9/download', { responseType: 'blob' })
    expect(createObjectURL).toHaveBeenCalledWith(pdf)
    expect(click).toHaveBeenCalledTimes(1)
  } finally {
    await act(async () => root.unmount())
    container.remove()
  }
})
