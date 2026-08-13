import { crc32 } from 'js-crc'
import { md5 } from 'js-md5'
import { sha256 } from 'js-sha256'
import { sha3_256 } from 'js-sha3'

/**
 * Multi-digest fingerprint of a PDF, computed in the browser.
 *
 * Hashing client-side means a 50 MB report never crosses the network just to be
 * checked, and the operator can see the digests that will be compared. SHA-256
 * plus the byte length decide the verdict; MD5 is kept because the ledger stores
 * it, and SHA3-256/CRC32 are recorded for forensics.
 *
 * These use pure-JS implementations rather than `crypto.subtle` on purpose: the
 * Web Crypto API is unavailable outside a secure context, and this system is
 * routinely deployed on plain HTTP inside a lab network.
 */
export type PdfDigests = {
  file_size: number
  calculated_at: string
  digest_version: string
  primary_hash: string
  secondary_hash: string
  md5_hash: string
  crc32_hash: string
}

export const DIGEST_VERSION = '2.0'

export function calculateDigests(buffer: ArrayBuffer): PdfDigests {
  const bytes = new Uint8Array(buffer)

  return {
    file_size: buffer.byteLength,
    calculated_at: new Date().toISOString(),
    digest_version: DIGEST_VERSION,
    primary_hash: sha256(bytes),
    secondary_hash: sha3_256(bytes),
    md5_hash: md5(bytes),
    crc32_hash: crc32(bytes),
  }
}

export async function calculateFileDigests(file: Blob): Promise<PdfDigests> {
  return calculateDigests(await file.arrayBuffer())
}

export function shortHash(hash?: string | null, length = 16) {
  if (!hash) {
    return '-'
  }

  return hash.length <= length ? hash : `${hash.slice(0, length)}…`
}
