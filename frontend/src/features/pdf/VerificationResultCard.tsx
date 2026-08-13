import { AlertTriangle, CheckCircle2, XCircle } from 'lucide-react'
import { cn } from '../../lib/utils'
import { formatBytes, formatDateTime } from '../system/utils'
import { digestLabels, securityLevelLabel, type VerificationResultData } from './api'

const coverFieldLabels: Record<string, string> = {
  report_number: '报告编号',
  product_name: '产品名称',
  model_specification: '规格型号',
  entrust_company: '委托单位',
  test_items: '检测项目',
  report_date: '报告日期',
}

/**
 * Verdict panel shared by the admin verification tab and the public page.
 *
 * The headline answers the only question that matters — is this the file we
 * issued — and everything below it exists so a disputed report can be discussed
 * with concrete digests rather than impressions.
 */
export function VerificationResultCard({ result, compact = false }: { result: VerificationResultData; compact?: boolean }) {
  const valid = result.overall_valid
  const details = result.verification_details
  const record = details.database_verification?.record ?? null
  const digestDetails = details.current_digests?.details ?? {}
  const currentDigests = result.current_digests as Record<string, string | number | undefined>
  const coverFields = result.cover_fields ?? record?.cover_fields ?? null

  return (
    <div className="space-y-4">
      <div
        className={cn(
          'flex items-start gap-3 rounded-lg border p-4',
          valid ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50',
        )}
      >
        {valid ? (
          <CheckCircle2 className="mt-0.5 size-6 shrink-0 text-emerald-600" aria-hidden="true" />
        ) : (
          <XCircle className="mt-0.5 size-6 shrink-0 text-red-600" aria-hidden="true" />
        )}
        <div className="min-w-0">
          <p className={cn('text-base font-semibold', valid ? 'text-emerald-800' : 'text-red-800')}>
            {valid ? '验证通过：文件与签发记录一致' : '验证失败：文件与签发记录不一致'}
          </p>
          <p className={cn('mt-1 text-sm', valid ? 'text-emerald-700' : 'text-red-700')}>{result.verification_message}</p>
          <p className="mt-2 text-xs text-slate-600">
            安全等级：{securityLevelLabel(result.security_level)} · 验证时间：{formatDateTime(result.verified_at)}
          </p>
        </div>
      </div>

      {coverFields ? (
        <section className="rounded-lg border border-emerald-900/10 bg-white p-4">
          <h3 className="text-sm font-semibold text-slate-900">报告封面信息</h3>
          <dl className="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
            {Object.entries(coverFieldLabels).map(([key, label]) => {
              const value = coverFields[key]

              if (!value) {
                return null
              }

              return (
                <div className="flex gap-2 text-sm" key={key}>
                  <dt className="shrink-0 text-slate-500">{label}</dt>
                  <dd className="min-w-0 break-words text-slate-900">{value}</dd>
                </div>
              )
            })}
          </dl>
        </section>
      ) : null}

      <section className="rounded-lg border border-emerald-900/10 bg-white p-4">
        <h3 className="text-sm font-semibold text-slate-900">被验证文件</h3>
        <dl className="mt-3 space-y-2 text-sm">
          <div className="flex gap-2">
            <dt className="w-24 shrink-0 text-slate-500">文件名</dt>
            <dd className="min-w-0 break-all text-slate-900">{result.file_name}</dd>
          </div>
          <div className="flex gap-2">
            <dt className="w-24 shrink-0 text-slate-500">文件大小</dt>
            <dd className="text-slate-900">{formatBytes(result.file_size)}</dd>
          </div>
          {(['primary_hash', 'secondary_hash', 'md5_hash', 'crc32_hash'] as const).map((key) => {
            const value = currentDigests[key]

            if (!value) {
              return null
            }

            return (
              <div className="flex gap-2" key={key}>
                <dt className="w-24 shrink-0 text-slate-500">{digestLabels[key]}</dt>
                <dd className="min-w-0 break-all font-mono text-xs text-slate-700">{String(value)}</dd>
              </div>
            )
          })}
        </dl>
      </section>

      {!compact && Object.keys(digestDetails).length > 0 ? (
        <section className="rounded-lg border border-emerald-900/10 bg-white p-4">
          <h3 className="text-sm font-semibold text-slate-900">摘要比对</h3>
          <ul className="mt-3 space-y-2 text-sm">
            {Object.entries(digestDetails).map(([key, matched]) => (
              <li className="flex items-center gap-2" key={key}>
                {matched ? (
                  <CheckCircle2 className="size-4 text-emerald-600" aria-hidden="true" />
                ) : (
                  <XCircle className="size-4 text-red-600" aria-hidden="true" />
                )}
                <span className="text-slate-700">{digestLabels[key] ?? key}</span>
                <span className={cn('text-xs font-medium', matched ? 'text-emerald-700' : 'text-red-700')}>
                  {matched ? '一致' : '不一致'}
                </span>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {record ? (
        <section className="rounded-lg border border-emerald-900/10 bg-white p-4">
          <h3 className="text-sm font-semibold text-slate-900">签发记录</h3>
          <dl className="mt-3 space-y-2 text-sm">
            {record.file_name ? (
              <div className="flex gap-2">
                <dt className="w-24 shrink-0 text-slate-500">原始文件名</dt>
                <dd className="min-w-0 break-all text-slate-900">{record.file_name}</dd>
              </div>
            ) : null}
            {record.cover_report_number ? (
              <div className="flex gap-2">
                <dt className="w-24 shrink-0 text-slate-500">报告编号</dt>
                <dd className="text-slate-900">{record.cover_report_number}</dd>
              </div>
            ) : null}
            <div className="flex gap-2">
              <dt className="w-24 shrink-0 text-slate-500">签发时间</dt>
              <dd className="text-slate-900">{formatDateTime(record.signed_at)}</dd>
            </div>
            {record.created_by ? (
              <div className="flex gap-2">
                <dt className="w-24 shrink-0 text-slate-500">签发人</dt>
                <dd className="text-slate-900">{record.created_by}</dd>
              </div>
            ) : null}
          </dl>
        </section>
      ) : null}

      {details.warnings.length > 0 ? (
        <section className="rounded-lg border border-amber-200 bg-amber-50 p-4">
          <h3 className="flex items-center gap-2 text-sm font-semibold text-amber-800">
            <AlertTriangle className="size-4" aria-hidden="true" />
            注意事项
          </h3>
          <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-800">
            {details.warnings.map((warning) => (
              <li key={warning}>{warning}</li>
            ))}
          </ul>
        </section>
      ) : null}
    </div>
  )
}
