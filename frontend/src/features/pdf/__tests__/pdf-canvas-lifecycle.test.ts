import { describe, expect, it, vi } from 'vitest'
import { releasePdfCanvas } from '../pdfCanvasLifecycle'

describe('PDF canvas bitmap lifetime', () => {
  it('releases the old bitmap on unmount after cancelling its render', () => {
    const canvas = { width: 803, height: 1136 } as HTMLCanvasElement
    const cancel = vi.fn(() => { expect(canvas.width).toBe(803) })
    const cleanup = releasePdfCanvas({ cancel }, canvas)
    expect(canvas.width * canvas.height * 4).toBe(3648832)
    cleanup()
    expect(cancel).toHaveBeenCalledTimes(1)
    expect(canvas.width * canvas.height).toBe(0)
  })
  it('never erases a later document canvas', () => {
    const oldCanvas = { width: 803, height: 1136 } as HTMLCanvasElement
    const nextCanvas = { width: 803, height: 1136 } as HTMLCanvasElement
    releasePdfCanvas({ cancel: vi.fn() }, oldCanvas)()
    expect(oldCanvas.width).toBe(0)
    expect(nextCanvas.width).toBe(803)
    expect(nextCanvas.height).toBe(1136)
  })
  it('releases the bitmap even when cancellation throws', () => {
    const canvas = { width: 803, height: 1136 } as HTMLCanvasElement
    expect(releasePdfCanvas({ cancel: () => { throw new Error('cancel failed') } }, canvas)).toThrow('cancel failed')
    expect(canvas.width * canvas.height).toBe(0)
  })
})
