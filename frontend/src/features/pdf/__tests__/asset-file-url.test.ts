import { describe, expect, it } from 'vitest'
import { assetFileUrl } from '../api'

/**
 * Replacing a seal image keeps the same record id, so the image URL is the only
 * thing that can tell the browser — and the loader hook, which keys off the URL
 * — that the bytes changed. When it does not, the operator saves a new image,
 * still sees the old one, and reasonably concludes the edit failed.
 */
describe('asset file url', () => {
  it('changes when the record is updated', () => {
    const before = assetFileUrl('function-stamps', 1, '2026-08-14T10:00:00.000000Z')
    const after = assetFileUrl('function-stamps', 1, '2026-08-14T11:30:00.000000Z')

    expect(before).not.toBe(after)
  })

  it('is stable while the record is unchanged, so the cached image is reused', () => {
    const first = assetFileUrl('digital-signatures', 7, '2026-08-14T10:00:00.000000Z')
    const second = assetFileUrl('digital-signatures', 7, '2026-08-14T10:00:00.000000Z')

    expect(first).toBe(second)
  })

  it('points at the record it was asked for', () => {
    expect(assetFileUrl('perforation-stamps', 3, '2026-08-14T10:00:00.000000Z')).toContain(
      '/api/pdf/perforation-stamps/3/file',
    )
  })

  it('escapes the version so a timestamp cannot break the query string', () => {
    expect(assetFileUrl('function-stamps', 1, '2026-08-14 10:00:00+08:00')).not.toContain(' ')
  })

  it('falls back to an unversioned url when the record has no timestamp', () => {
    expect(assetFileUrl('function-stamps', 1)).toBe('/api/pdf/function-stamps/1/file')
  })
})
