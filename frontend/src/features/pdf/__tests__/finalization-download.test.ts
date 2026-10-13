import { beforeEach, expect, it, vi } from 'vitest'
import { confirmAndFinalizeSigningSource, finalizeSigningSource } from '../handwrittenApi'

const api = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }))
vi.mock('../../../lib/api', () => ({ api }))
const input = { sourceUuid: 'source-1', reportNumber: 'RPT-1' }
beforeEach(() => {
  api.post.mockReset().mockResolvedValue({ data: { data: { operation_uuid: 'operation-1', state: 'completed',
    result_revision_uuid: 'revision-1', result_sha256: 'hash-1', result_size: 4 } } })
  api.get.mockReset().mockResolvedValue({ data: new Blob(['%PDF'], { type: 'application/pdf' }) })
})

it('finalizes a handoff without fetching its PDF bytes', async () => {
  expect(await finalizeSigningSource(input)).toMatchObject({ revision_uuid: 'revision-1', file_size: 4, sha256: 'hash-1' })
  expect(api.get).not.toHaveBeenCalled()
  expect(api.post).toHaveBeenCalledWith('/api/pdf/signing-sources/source-1/confirm', { report_number: 'RPT-1' })
  expect(api.post).toHaveBeenCalledWith('/api/pdf/signing-sources/source-1/finalize', undefined,
    { headers: { 'Idempotency-Key': 'finalize-source-1' } })
})

it('still downloads once when planning needs the finalized PDF', async () => {
  const result = await confirmAndFinalizeSigningSource(input)
  expect(result.file.size).toBe(4)
  expect(result.revision).toMatchObject({ revision_uuid: 'revision-1', file_size: 4, sha256: 'hash-1' })
  expect(api.get).toHaveBeenCalledTimes(1)
  expect(api.get).toHaveBeenCalledWith('/api/pdf/revisions/revision-1/download', { responseType: 'blob' })
})

it('does not fetch a file when finalization fails', async () => {
  api.post.mockResolvedValue({ data: { data: { state: 'failed', error_code: 'FINALIZATION_FAILED' } } })
  await expect(confirmAndFinalizeSigningSource(input)).rejects.toThrow('FINALIZATION_FAILED')
  expect(api.get).not.toHaveBeenCalled()
})
