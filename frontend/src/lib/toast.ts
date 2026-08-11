import { useSyncExternalStore } from 'react'

export type ToastVariant = 'loading' | 'success' | 'error'

export type Toast = {
  id: string
  variant: ToastVariant
  title: string
  description?: string
}

export type ToastOptions = {
  variant: ToastVariant
  title: string
  description?: string
  /** Milliseconds before the toast closes itself; `null` keeps it until it is updated or dismissed. */
  duration?: number | null
}

const DEFAULT_DURATION: Record<ToastVariant, number | null> = {
  loading: null,
  success: 2600,
  error: 6000,
}

let toasts: Toast[] = []
let toastSeq = 0
const listeners = new Set<() => void>()
const timers = new Map<string, ReturnType<typeof setTimeout>>()

export function showToast(options: ToastOptions) {
  const id = `toast-${(toastSeq += 1)}`

  toasts = [...toasts, toastFrom(id, options)]
  scheduleDismiss(id, options)
  emit()

  return id
}

/** Replaces a toast in place, so a loading toast can become its own success or error state. */
export function updateToast(id: string, options: ToastOptions) {
  if (!toasts.some((toast) => toast.id === id)) {
    return
  }

  toasts = toasts.map((toast) => (toast.id === id ? toastFrom(id, options) : toast))
  scheduleDismiss(id, options)
  emit()
}

export function dismissToast(id: string) {
  clearTimer(id)

  if (!toasts.some((toast) => toast.id === id)) {
    return
  }

  toasts = toasts.filter((toast) => toast.id !== id)
  emit()
}

export function clearToasts() {
  timers.forEach((timer) => clearTimeout(timer))
  timers.clear()
  toasts = []
  emit()
}

export function getToasts() {
  return toasts
}

export function subscribeToasts(listener: () => void) {
  listeners.add(listener)

  return () => {
    listeners.delete(listener)
  }
}

export function useToasts() {
  return useSyncExternalStore(subscribeToasts, getToasts, getToasts)
}

function toastFrom(id: string, { variant, title, description }: ToastOptions): Toast {
  return { id, variant, title, description }
}

function scheduleDismiss(id: string, { variant, duration }: ToastOptions) {
  clearTimer(id)

  const delay = duration === undefined ? DEFAULT_DURATION[variant] : duration

  if (delay === null) {
    return
  }

  timers.set(
    id,
    setTimeout(() => {
      timers.delete(id)
      dismissToast(id)
    }, delay),
  )
}

function clearTimer(id: string) {
  const timer = timers.get(id)

  if (timer === undefined) {
    return
  }

  clearTimeout(timer)
  timers.delete(id)
}

function emit() {
  listeners.forEach((listener) => listener())
}
