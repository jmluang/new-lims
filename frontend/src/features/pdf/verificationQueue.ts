import { useCallback, useMemo, useState } from 'react'
import { errorMessage } from '../system/utils'
import type { VerificationResultData } from './api'

/**
 * Shared queue for the two verification screens.
 *
 * Both check a batch of PDFs one at a time and need the same bookkeeping —
 * per-file status, retry, overall progress — while differing only in how a
 * single file is checked (digests posted from the browser vs. the file
 * uploaded), which the caller supplies as `verifyFile`.
 */
export type VerificationStatus = 'pending' | 'processing' | 'completed' | 'error'

export type QueuedVerification = {
  key: string
  file: File
  status: VerificationStatus
  step: string
  result?: VerificationResultData
  error?: string
  expanded: boolean
}

export const statusLabels: Record<VerificationStatus, string> = {
  pending: '等待验证',
  processing: '验证中',
  completed: '已完成',
  error: '验证出错',
}

export function useVerificationQueue({
  verifyFile,
  maxFiles,
  maxBytes,
}: {
  verifyFile: (file: File, onStep: (step: string) => void) => Promise<VerificationResultData>
  maxFiles?: number
  maxBytes?: number
}) {
  const [items, setItems] = useState<QueuedVerification[]>([])
  const [processing, setProcessing] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)

  const patch = useCallback((key: string, changes: Partial<QueuedVerification>) => {
    setItems((current) => current.map((item) => (item.key === key ? { ...item, ...changes } : item)))
  }, [])

  const addFiles = useCallback(
    (fileList: FileList | null) => {
      if (!fileList) {
        return
      }

      const skipped: string[] = []

      setItems((current) => {
        const accepted: QueuedVerification[] = []

        Array.from(fileList).forEach((file) => {
          if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
            skipped.push(`${file.name}（不是 PDF）`)
            return
          }

          if (maxBytes && file.size > maxBytes) {
            skipped.push(`${file.name}（超过大小限制）`)
            return
          }

          // Same name and size twice is a re-drop, not a second report.
          const duplicate = [...current, ...accepted].some(
            (item) => item.file.name === file.name && item.file.size === file.size,
          )

          if (duplicate) {
            skipped.push(`${file.name}（已在列表中）`)
            return
          }

          if (maxFiles && current.length + accepted.length >= maxFiles) {
            skipped.push(`${file.name}（最多 ${maxFiles} 个）`)
            return
          }

          accepted.push({
            key: `${file.name}-${file.size}-${Date.now()}-${Math.random()}`,
            file,
            status: 'pending',
            step: '',
            expanded: false,
          })
        })

        return accepted.length > 0 ? [...current, ...accepted] : current
      })

      setNotice(skipped.length > 0 ? `已跳过 ${skipped.length} 个文件：${skipped.join('，')}` : null)
    },
    [maxBytes, maxFiles],
  )

  const verifyOne = useCallback(
    async (item: QueuedVerification) => {
      patch(item.key, { status: 'processing', step: '读取文件', error: undefined, result: undefined })

      try {
        const result = await verifyFile(item.file, (step) => patch(item.key, { step }))
        patch(item.key, { status: 'completed', step: '', result, expanded: !result.overall_valid })
      } catch (caught) {
        patch(item.key, { status: 'error', step: '', error: errorMessage(caught, '验证失败') })
      }
    },
    [patch, verifyFile],
  )

  const start = useCallback(async () => {
    if (processing) {
      return
    }

    setProcessing(true)
    setNotice(null)

    // Snapshot the pending set: verifying sequentially keeps digest work off
    // the main thread in bursts the browser can handle on large reports.
    const pending = items.filter((item) => item.status === 'pending' || item.status === 'error')

    for (const item of pending) {
      await verifyOne(item)
    }

    setProcessing(false)
  }, [items, processing, verifyOne])

  const retry = useCallback(
    async (item: QueuedVerification) => {
      if (processing) {
        return
      }

      setProcessing(true)
      await verifyOne(item)
      setProcessing(false)
    },
    [processing, verifyOne],
  )

  const remove = useCallback((key: string) => setItems((current) => current.filter((item) => item.key !== key)), [])

  const clear = useCallback(() => {
    setItems([])
    setNotice(null)
  }, [])

  const toggleExpanded = useCallback(
    (key: string) => setItems((current) => current.map((item) => (item.key === key ? { ...item, expanded: !item.expanded } : item))),
    [],
  )

  const stats = useMemo(() => {
    const processed = items.filter((item) => item.status === 'completed' || item.status === 'error').length
    const valid = items.filter((item) => item.result?.overall_valid).length
    const invalid = items.filter((item) => item.status === 'completed' && !item.result?.overall_valid).length

    return {
      total: items.length,
      processed,
      valid,
      invalid,
      failed: items.filter((item) => item.status === 'error').length,
      hasPending: items.some((item) => item.status === 'pending' || item.status === 'error'),
      progress: items.length === 0 ? 0 : (processed / items.length) * 100,
    }
  }, [items])

  return { items, processing, notice, stats, addFiles, start, retry, remove, clear, toggleExpanded, setNotice }
}
