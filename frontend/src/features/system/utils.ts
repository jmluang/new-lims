import { zhErrorText } from '../../lib/zh'

export type ApiCollection<T> = {
  data: T[]
  meta?: Record<string, unknown> & Partial<PaginationMeta>
}

export type PaginationMeta = {
  current_page: number
  per_page: number
  total: number
}

export type ApiResource<T> = {
  data: T
}

export type ApiError = {
  response?: {
    status?: number
    data?: {
      message?: string
      errors?: Record<string, string[]>
      permission?: string
      // PDF routes wrap their stable codes in an envelope instead of using the
      // top-level message; see the api/pdf/* renderer in bootstrap/app.php.
      error?: {
        code?: string
        message?: string
      }
    }
  }
}

export function errorMessage(error: unknown, fallback = 'Request failed') {
  // Axios rejections are Error instances too, so the response has to be read
  // before the generic Error branch. Otherwise every backend error code is
  // replaced by axios' own "Request failed with status code NNN".
  const response = (error as ApiError | null | undefined)?.response

  if (response) {
    const missingPermission = response.status === 403 ? response.data?.permission : undefined

    if (missingPermission) {
      return `没有权限执行该操作：缺少 ${missingPermission}`
    }

    const validationErrors = response.data?.errors
    const firstValidationError = validationErrors ? Object.values(validationErrors).flat()[0] : undefined
    const envelope = response.data?.error
    const serverMessage = firstValidationError
      ?? envelope?.code
      ?? envelope?.message
      ?? response.data?.message

    if (serverMessage) {
      return zhErrorText(serverMessage) ?? serverMessage
    }

    return zhErrorText(fallback) ?? fallback
  }

  if (error instanceof Error) {
    return zhErrorText(error.message) ?? error.message
  }

  return zhErrorText(fallback) ?? fallback
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

export function localDateInputValue(date = new Date()) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}

export function localDateTimeInputValue(date = new Date()) {
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')

  return `${localDateInputValue(date)}T${hours}:${minutes}`
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
  'h-9 min-w-0 w-full rounded-md border border-emerald-900/20 bg-white px-3 text-sm text-slate-900 outline-none transition-colors placeholder:text-slate-400 focus:border-emerald-700 focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-100'

export const textareaClass =
  'min-h-20 min-w-0 w-full rounded-md border border-emerald-900/20 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition-colors placeholder:text-slate-400 focus:border-emerald-700 focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-100'

export function paginationParams(page: number, perPage: number) {
  return { page, per_page: perPage }
}
