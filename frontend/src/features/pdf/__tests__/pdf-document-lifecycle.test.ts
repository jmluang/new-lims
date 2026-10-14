import { afterEach, describe, expect, it, vi } from 'vitest'
import type { PDFDocumentProxy } from 'pdfjs-dist'
import { loadPdfDocument } from '../pdfDocumentLifecycle'

const mocks = vi.hoisted(() => ({ getDocument: vi.fn() }))
vi.mock('pdfjs-dist', () => ({ getDocument: mocks.getDocument }))

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: Error) => void
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
async function settle() { for (let i = 0; i < 6; i++) await Promise.resolve() }
function fixture() {
  const bytes = deferred<ArrayBuffer>()
  const document = deferred<PDFDocumentProxy>()
  const destroy = vi.fn(async () => {})
  const pdf = { numPages: 7, destroy: vi.fn(() => destroy()) } as unknown as PDFDocumentProxy
  const task = { promise: document.promise, destroy }
  const file = { arrayBuffer: () => bytes.promise } as Blob
  const loaded = vi.fn(), error = vi.fn()
  mocks.getDocument.mockReturnValueOnce(task)
  return { bytes, document, destroy, pdf, file, loaded, error }
}
afterEach(() => vi.resetAllMocks())

describe('PDF preview resources across real component unmount timing', () => {
  it('never starts a worker if the file read resolves after unmount', async () => {
    const f = fixture(); const cleanup = loadPdfDocument(f.file, f.loaded, f.error)
    cleanup?.(); f.bytes.resolve(new ArrayBuffer(8)); await settle()
    expect(mocks.getDocument).not.toHaveBeenCalled()
  })
  it('destroys a worker still loading when the component leaves the page', async () => {
    const f = fixture(); const cleanup = loadPdfDocument(f.file, f.loaded, f.error)
    f.bytes.resolve(new ArrayBuffer(8)); await settle(); cleanup?.()
    expect(f.destroy).toHaveBeenCalledTimes(1)
    f.document.resolve(f.pdf); await settle()
    expect(f.loaded).not.toHaveBeenCalled(); expect(f.error).not.toHaveBeenCalled()
  })
  it('loads the current document and releases it on ordinary unmount', async () => {
    const f = fixture(); const cleanup = loadPdfDocument(f.file, f.loaded, f.error)
    f.bytes.resolve(new ArrayBuffer(8)); await settle(); f.document.resolve(f.pdf); await settle()
    expect(f.loaded).toHaveBeenCalledWith(f.pdf); cleanup?.()
    expect(f.destroy).toHaveBeenCalledTimes(1)
  })
  it('reports a live read failure without starting a worker', async () => {
    const f = fixture(); loadPdfDocument(f.file, f.loaded, f.error)
    f.bytes.reject(new Error('read failed')); await settle()
    expect(f.error).toHaveBeenCalledTimes(1); expect(mocks.getDocument).not.toHaveBeenCalled()
  })
  it('ignores a failure after unmount', async () => {
    const f = fixture(); const cleanup = loadPdfDocument(f.file, f.loaded, f.error)
    cleanup?.(); f.bytes.reject(new Error('read failed')); await settle()
    expect(f.error).not.toHaveBeenCalled(); expect(f.loaded).not.toHaveBeenCalled()
  })
})
