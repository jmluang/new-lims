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
      error?: string | {
        code?: string
        message?: string
      }
    }
  }
}

const genericError = '本次操作出现异常，请先确认操作结果；持续异常请联系管理员。'

// Only translated or readable business copy belongs in a user-facing notice.
const internalErrorPattern = /SQLSTATE|No query results for model|(?:Exception|Error):|\b[A-Za-z_]\w*(?:\\[A-Za-z_]\w*)+|stack trace|cURL error|ENOENT|EACCES|ECONNREFUSED|\b(?:unable|cannot|undefined|no such file|permission denied|connection refused|unexpected token)\b|\bat .+\.(?:php|tsx?|jsx?):\d+|(?:^|[\s("'：])\/(?:Users|home|var|private|tmp|www|etc|opt|usr|srv)\/|[A-Za-z]:\\|<\/?(?:html|body)|<!DOCTYPE|(?:password|secret|token)\s*[:=]/i

function readableError(value: unknown): string | undefined {
  if (typeof value !== 'string') return undefined
  const translated = zhErrorText(value.trim())
  if (!translated || internalErrorPattern.test(translated)) return undefined
  const readable = translated !== value.trim() || /^(?:[\u3400-\u9fff]|(?:PDF|IES|HAAS|GOS|CNAS|CMA|CRI|CCT|TM-30)\s)/.test(value.trim())
  return readable && translated && /[\u3400-\u9fff]/.test(translated) ? translated : undefined
}

export function errorMessage(error: unknown, fallback = 'Request failed'): string {
  const safeFallback = fallback === 'Request failed' ? undefined : readableError(fallback)
  const response = (error as ApiError | null | undefined)?.response
  if (response) {
    if (response.status === 401) return '登录已失效，请重新登录。'
    if (response.status === 403 && response.data?.permission) return '没有权限执行该操作，请联系管理员开通相应权限。'
    const validationErrors = response.data?.errors
    const validation = validationErrors && typeof validationErrors === 'object' && !Array.isArray(validationErrors) ? Object.values(validationErrors).flat().map(readableError).find(Boolean) : undefined
    const envelope = response.data?.error
    const candidates = typeof envelope === 'string' ? [envelope] : [envelope?.code, envelope?.message]
    const message = validation ?? [...candidates, response.data?.message].map(readableError).find(Boolean)
    if (message) return message
    if (safeFallback) return safeFallback
    switch (response.status) {
      case 403: return '没有权限执行该操作，请联系管理员开通相应权限。'
      case 404: return '未找到所需记录，请刷新后重试。'
      case 413: return '文件过大，请减小文件大小后重新上传。'
      case 422: return '填写的信息有误，请检查后重新提交。'
      case 429: return '操作过于频繁，请稍后重试。'
      default: return genericError
    }
  }
  const code = (error as { code?: string } | null | undefined)?.code
  if (code === 'ERR_NETWORK' || (error instanceof Error && error.message === 'Network Error')) return '无法连接服务器，请检查网络并确认操作结果。'
  if (code === 'ECONNABORTED' || code === 'ETIMEDOUT') return '请求超时，请先确认操作结果；状态不明时请联系管理员。'
  return readableError(error instanceof Error ? error.message : error) ?? safeFallback ?? genericError
}

export async function blobErrorMessage(error: unknown, fallback: string): Promise<string> {
  const response = (error as { response?: { status?: number; data?: unknown } } | null | undefined)?.response
  if (response?.data instanceof Blob) {
    try {
      const data: unknown = JSON.parse(await response.data.text())
      return errorMessage({ response: { status: response.status, data } }, fallback)
    } catch {
      return errorMessage({ response: { status: response.status } }, fallback)
    }
  }
  return errorMessage(error, fallback)
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
