import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const ledgerSource = readFileSync(fileURLToPath(new URL('../PdfFileListPage.tsx', import.meta.url)), 'utf8')
const verificationLogSource = readFileSync(fileURLToPath(new URL('../PdfVerificationLogPage.tsx', import.meta.url)), 'utf8')

describe('PDF ledger actions', () => {
  it.each([
    ['signature ledger', ledgerSource],
    ['verification log', verificationLogSource],
  ])('keeps detail inspection without exposing download actions in the %s', (_label, source) => {
    expect(source).toContain('详情')
    expect(source).not.toContain('下载')
    expect(source).not.toContain('action="download"')
  })
})
