import { describe, expect, it } from 'vitest'
import { calculateDigests } from '../digest'

/**
 * These digests are the whole tamper-proof guarantee: the signing desk records
 * them and verification re-derives them. If this drifts, previously signed
 * reports stop verifying, so pin the values against known vectors rather than
 * against the implementation.
 */
describe('pdf digests', () => {
  const encoder = new TextEncoder()

  function digestsOf(text: string) {
    // Slice to the exact byte range so the digests cover the string alone.
    const encoded = encoder.encode(text)

    return calculateDigests(encoded.buffer.slice(encoded.byteOffset, encoded.byteOffset + encoded.byteLength) as ArrayBuffer)
  }

  it('matches the published vectors for "abc"', () => {
    const digests = digestsOf('abc')

    expect(digests.primary_hash).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')
    expect(digests.secondary_hash).toBe('3a985da74fe225b2045c172d6bd390bd855f086e3e9d525b46bfe24511431532')
    expect(digests.md5_hash).toBe('900150983cd24fb0d6963f7d28e17f72')
    expect(digests.file_size).toBe(3)
  })

  it('matches the published vectors for the empty input', () => {
    const digests = calculateDigests(new ArrayBuffer(0))

    expect(digests.primary_hash).toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')
    expect(digests.md5_hash).toBe('d41d8cd98f00b204e9800998ecf8427e')
    expect(digests.file_size).toBe(0)
  })

  it('changes every digest when a single byte changes', () => {
    const original = digestsOf('Report 2026-0001: PASS')
    const edited = digestsOf('Report 2026-0001: FAIL')

    expect(edited.file_size).toBe(original.file_size)
    expect(edited.primary_hash).not.toBe(original.primary_hash)
    expect(edited.secondary_hash).not.toBe(original.secondary_hash)
    expect(edited.md5_hash).not.toBe(original.md5_hash)
    expect(edited.crc32_hash).not.toBe(original.crc32_hash)
  })

  it('reports the digest version the API validates against', () => {
    expect(digestsOf('abc').digest_version).toBe('2.0')
  })
})
