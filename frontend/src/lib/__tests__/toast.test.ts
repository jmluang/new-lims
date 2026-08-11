import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { clearToasts, dismissToast, getToasts, showToast, subscribeToasts, updateToast } from '../toast'

describe('toast store', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    clearToasts()
  })

  afterEach(() => {
    clearToasts()
    vi.useRealTimers()
  })

  it('keeps loading toasts open until they are updated', () => {
    const id = showToast({ variant: 'loading', title: '正在生成委托单', description: 'TO-1' })

    vi.advanceTimersByTime(60_000)

    expect(getToasts()).toEqual([{ id, variant: 'loading', title: '正在生成委托单', description: 'TO-1' }])
  })

  it('replaces a loading toast in place and closes it after the success delay', () => {
    const id = showToast({ variant: 'loading', title: '正在生成委托单' })

    updateToast(id, { variant: 'success', title: '委托单已下载', description: 'TO-1.pdf' })

    expect(getToasts()).toHaveLength(1)
    expect(getToasts()[0]).toMatchObject({ id, variant: 'success', title: '委托单已下载' })

    vi.advanceTimersByTime(2_600)

    expect(getToasts()).toEqual([])
  })

  it('leaves dismissed toasts closed when a later update arrives', () => {
    const id = showToast({ variant: 'loading', title: '正在生成委托单' })

    dismissToast(id)
    updateToast(id, { variant: 'success', title: '委托单已下载' })

    expect(getToasts()).toEqual([])
  })

  it('notifies subscribers until they unsubscribe', () => {
    const listener = vi.fn()
    const unsubscribe = subscribeToasts(listener)

    const id = showToast({ variant: 'error', title: '委托单生成失败，请重试' })

    expect(listener).toHaveBeenCalledTimes(1)

    unsubscribe()
    dismissToast(id)

    expect(listener).toHaveBeenCalledTimes(1)
  })
})
