import { PDFDocument } from 'pdf-lib'
import { beforeEach, expect, it, vi } from 'vitest'
import { createCertificateMerger } from '../certificateMerge'

const get = vi.hoisted(() => vi.fn())
vi.mock('../../../lib/api', () => ({ api: { get } }))
beforeEach(() => get.mockReset())

it('fetches a shared declaration template once for a concurrent report batch', async () => {
  const document = await PDFDocument.create()
  document.addPage()
  const bytes = await document.save()
  get.mockResolvedValue({ data: bytes })
  const source = new Blob([new Uint8Array(bytes).buffer], { type: 'application/pdf' })
  const mergeCertificate = createCertificateMerger(7)
  const merged = await Promise.all(Array.from({ length: 3 }, () => mergeCertificate(source)))
  for (const result of merged) {
    expect((await PDFDocument.load(await result.arrayBuffer())).getPageCount()).toBe(2)
  }
  expect(get).toHaveBeenCalledTimes(1)
})

it('keeps the same template across worker waves but reloads it for the next batch', async () => {
  const document = await PDFDocument.create()
  document.addPage()
  const first = await document.save()
  document.addPage()
  const updated = await document.save()
  get.mockResolvedValueOnce({ data: first }).mockResolvedValueOnce({ data: updated })
  const source = new Blob([new Uint8Array(first).buffer], { type: 'application/pdf' })
  const merge = createCertificateMerger(7)
  await Promise.all(Array.from({ length: 3 }, () => merge(source)))
  const later = await merge(source)
  expect((await PDFDocument.load(await later.arrayBuffer())).getPageCount()).toBe(2)
  expect(get).toHaveBeenCalledTimes(1)

  const nextBatch = await createCertificateMerger(7)(source)
  expect((await PDFDocument.load(await nextBatch.arrayBuffer())).getPageCount()).toBe(3)
  expect(get).toHaveBeenCalledTimes(2)
})

it('shares a failed request within a batch and retries the template in a new batch', async () => {
  const document = await PDFDocument.create()
  document.addPage()
  const bytes = await document.save()
  const source = new Blob([new Uint8Array(bytes).buffer], { type: 'application/pdf' })
  get.mockRejectedValueOnce(new Error('Template unavailable')).mockResolvedValueOnce({ data: bytes })
  const failedBatch = createCertificateMerger(7)
  const results = await Promise.allSettled(Array.from({ length: 3 }, () => failedBatch(source)))
  expect(results.every(result => result.status === 'rejected')).toBe(true)
  expect(get).toHaveBeenCalledTimes(1)
  expect((await createCertificateMerger(7)(source)).size).toBeGreaterThan(0)
  expect(get).toHaveBeenCalledTimes(2)
})
