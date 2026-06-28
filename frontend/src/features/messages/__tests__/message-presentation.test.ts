import { describe, expect, it } from 'vitest'
import { unreadBadgeLabel } from '../messagePresentation'

describe('unreadBadgeLabel', () => {
  it('formats unread message counts for the header badge', () => {
    expect(unreadBadgeLabel(0)).toBe('')
    expect(unreadBadgeLabel(3)).toBe('3')
    expect(unreadBadgeLabel(100)).toBe('99+')
  })
})
