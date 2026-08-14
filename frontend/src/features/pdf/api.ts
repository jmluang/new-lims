import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { api } from '../../lib/api'
import type { ApiCollection } from '../system/utils'

export type CertificateTemplate = {
  id: number
  name: string
  language: string
  description?: string | null
  file_name?: string | null
  file_size?: number | null
  is_default: boolean
  is_active?: boolean
}

export type SealOption = {
  id: number
  name: string
  description?: string | null
  is_default: boolean
  updated_at?: string | null
}

export type FunctionStampOption = {
  id: number
  name: string
  sort_order: number
  is_default: boolean
  updated_at?: string | null
}

export type SigningOptions = {
  data: {
    certificate_templates: CertificateTemplate[]
    digital_signatures: SealOption[]
    perforation_stamps: SealOption[]
    function_stamps: FunctionStampOption[]
  }
  meta: {
    signing_enabled: boolean
    photometric_removal_enabled: boolean
    max_upload_kb: number
    operator_name?: string | null
  }
}

export type VerificationRecord = {
  id?: number
  file_id?: string
  file_name?: string | null
  signed_at?: string | null
  created_by?: string | null
  file_size?: number | null
  cover_report_number?: string | null
  cover_fields?: Record<string, string | null> | null
}

export type VerificationResultData = {
  file_name: string
  file_size: number
  current_digests: Record<string, unknown>
  verified_at: string
  overall_valid: boolean
  security_level: string
  verification_message: string
  cover_report_number?: string | null
  cover_fields?: Record<string, string | null> | null
  verification_details: {
    current_digests?: { valid: boolean; details: Record<string, boolean> } | null
    database_verification?: {
      found: boolean
      hash_match?: boolean
      md5_match?: boolean
      size_match?: boolean
      record?: VerificationRecord | null
      message?: string
    } | null
    warnings: string[]
  }
}

export const signingOptionsQueryKey = ['pdf', 'signing-options'] as const

export function useSigningOptions() {
  return useQuery({
    queryKey: signingOptionsQueryKey,
    queryFn: async () => {
      const response = await api.get<SigningOptions>('/api/pdf/signing/options')
      return response.data
    },
  })
}

/**
 * URL for an asset's stored file, versioned by its last update.
 *
 * Replacing a seal image keeps the same id, so an unversioned URL is both
 * cached by the browser and treated as unchanged by the loader hook — the
 * operator saves a new image and still sees the old one, which reads as the
 * edit having failed. The version makes the URL change exactly when the file
 * does.
 */
export function assetFileUrl(path: string, id: number, updatedAt?: string | null) {
  const url = `/api/pdf/${path}/${id}/file`

  return updatedAt ? `${url}?v=${encodeURIComponent(updatedAt)}` : url
}

export type PdfAsset = {
  id: number
  name: string
  description?: string | null
  is_default: boolean
  is_active: boolean
  updated_at?: string | null
  signature_contact?: string | null
  signature_location?: string | null
  signature_reason?: string | null
  sort_order?: number
  language?: string
  file_name?: string | null
  file_size?: number | null
  created_by?: string | null
}

export function usePdfAssets(path: string, key: string) {
  return useQuery({
    queryKey: ['pdf', key],
    queryFn: async () => {
      const response = await api.get<ApiCollection<PdfAsset>>(`/api/pdf/${path}`)
      return response.data.data
    },
  })
}

/**
 * Seal images live on a private disk behind an authenticated endpoint, so they
 * cannot be used as a plain `<img src>`. Fetch the bytes with the API client and
 * hand back a blob URL, revoking it when the component unmounts.
 */
export function useAuthedObjectUrl(url: string | null) {
  // Keyed by the url it was loaded for, so switching sources renders nothing
  // until the new blob arrives without an extra synchronous state write.
  const [loaded, setLoaded] = useState<{ url: string; objectUrl: string } | null>(null)

  useEffect(() => {
    if (!url) {
      return
    }

    let cancelled = false
    let created: string | null = null

    api
      .get<Blob>(url, { responseType: 'blob' })
      .then((response) => {
        if (cancelled) {
          return
        }

        created = URL.createObjectURL(response.data)
        setLoaded({ url, objectUrl: created })
      })
      .catch(() => {
        if (!cancelled) {
          setLoaded(null)
        }
      })

    return () => {
      cancelled = true

      if (created) {
        URL.revokeObjectURL(created)
      }
    }
  }, [url])

  return loaded?.url === url ? loaded.objectUrl : null
}

/**
 * Reads a percent-encoded header value.
 *
 * HTTP header values are ISO-8859-1, so the server percent-encodes anything
 * that can hold Chinese — a raw value arrives as mojibake. Values that are not
 * encoded are returned unchanged, which keeps older responses readable.
 */
export function decodeHeaderValue(value?: string | null) {
  if (!value) {
    return null
  }

  try {
    return decodeURIComponent(value) || null
  } catch {
    return value
  }
}

export function securityLevelLabel(level?: string | null) {
  switch (level) {
    case 'very_high':
      return '极高'
    case 'high':
      return '高'
    case 'medium':
      return '中'
    case 'low':
      return '低'
    case 'compromised':
      return '已失效'
    default:
      return '未知'
  }
}

export const digestLabels: Record<string, string> = {
  primary_hash: 'SHA-256',
  secondary_hash: 'SHA3-256',
  md5_hash: 'MD5',
  crc32_hash: 'CRC32',
  file_size: '文件大小',
}
