import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import type { VerificationResultData } from '../api'
import { VerificationResultCard } from '../VerificationResultCard'

function result(overrides: Partial<VerificationResultData> = {}): VerificationResultData {
  return {
    file_name: 'report.pdf',
    file_size: 204800,
    current_digests: {
      primary_hash: 'a'.repeat(64),
      md5_hash: 'b'.repeat(32),
      file_size: 204800,
    },
    verified_at: '2026-08-13T10:00:00+08:00',
    overall_valid: true,
    security_level: 'high',
    verification_message: '验证通过',
    cover_report_number: 'ZS-2026-0001',
    cover_fields: { report_number: 'ZS-2026-0001', product_name: 'LED 灯具' },
    verification_details: {
      current_digests: { valid: true, details: { primary_hash: true, md5_hash: true, file_size: true } },
      database_verification: {
        found: true,
        record: { file_name: 'report.pdf', signed_at: '2026-08-12T09:00:00+08:00', created_by: '张三' },
      },
      warnings: [],
    },
    ...overrides,
  }
}

describe('verification result card', () => {
  it('states plainly that a matching file is authentic', () => {
    const markup = renderToStaticMarkup(<VerificationResultCard result={result()} />)

    expect(markup).toContain('验证通过：文件与签发记录一致')
    expect(markup).toContain('ZS-2026-0001')
    expect(markup).toContain('张三')
  })

  it('states plainly that a mismatched file failed, and lists why', () => {
    const markup = renderToStaticMarkup(
      <VerificationResultCard
        result={result({
          overall_valid: false,
          security_level: 'compromised',
          verification_message: '验证失败: SHA256摘要不匹配',
          cover_fields: null,
          verification_details: {
            current_digests: { valid: false, details: { primary_hash: false, file_size: true } },
            database_verification: { found: false, record: null },
            warnings: ['SHA256摘要不匹配'],
          },
        })}
      />,
    )

    expect(markup).toContain('验证失败：文件与签发记录不一致')
    expect(markup).toContain('SHA256摘要不匹配')
    expect(markup).toContain('已失效')
  })

  it('hides the digest breakdown in compact mode used by the public page', () => {
    const markup = renderToStaticMarkup(<VerificationResultCard result={result()} compact />)

    expect(markup).toContain('验证通过：文件与签发记录一致')
    expect(markup).not.toContain('摘要比对')
  })
})
