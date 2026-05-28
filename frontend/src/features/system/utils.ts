import { zhErrorText } from '../../lib/zh'

export type ApiCollection<T> = {
  data: T[]
  meta?: Record<string, unknown>
}

export type ApiResource<T> = {
  data: T
}

export type ApiError = {
  response?: {
    data?: {
      message?: string
      errors?: Record<string, string[]>
    }
  }
}

export function errorMessage(error: unknown, fallback = 'Request failed') {
  const apiError = error as ApiError
  const validationErrors = apiError.response?.data?.errors
  const firstValidationError = validationErrors ? Object.values(validationErrors).flat()[0] : undefined

  return zhErrorText(firstValidationError ?? apiError.response?.data?.message ?? fallback) ?? fallback
}

export function formatDateTime(value?: string | null) {
  if (!value) {
    return '-'
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat('zh-CN', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

export function formatBytes(value?: number | null) {
  if (value === null || value === undefined) {
    return '-'
  }

  if (value < 1024) {
    return `${value} B`
  }

  const units = ['KB', 'MB', 'GB', 'TB']
  let size = value / 1024
  let unitIndex = 0

  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024
    unitIndex += 1
  }

  return `${size.toFixed(size >= 10 ? 1 : 2)} ${units[unitIndex]}`
}

export const inputClass =
  'h-9 min-w-0 w-full rounded-md border border-slate-300 bg-white px-3 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-100'

export const textareaClass =
  'min-h-20 min-w-0 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-100'
