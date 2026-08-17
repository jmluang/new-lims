import { describe, expect, it } from 'vitest'
import { placementPagesReady, samePlacements } from '../placementReady'
import type { Placement } from '../handwrittenApi'

describe('placementPagesReady', () => {
  it('is not ready before any page has been measured', () => {
    expect(placementPagesReady([0, 0, 0], new Set())).toBe(false)
  })

  it('becomes ready once every page carrying a box is measured', () => {
    expect(placementPagesReady([0, 0, 1], new Set([0]))).toBe(false)
    expect(placementPagesReady([0, 0, 1], new Set([0, 1]))).toBe(true)
  })

  // A 13 page report should not stay locked because page 9 is still rendering
  // when all three boxes live on page 1.
  it('ignores pages that carry no box', () => {
    expect(placementPagesReady([0, 0, 0], new Set([0]))).toBe(true)
  })

  it('is ready when there are no boxes to place at all', () => {
    expect(placementPagesReady([], new Set())).toBe(true)
  })
})

describe('samePlacements', () => {
  const box = (x: string): Placement => ({
    semantic_role: 'inspector',
    page_index: 0,
    normalized_rect: { x, y: '0.5', width: '0.16', height: '0.055' },
  })

  // The parent hands each page a freshly filtered array every render, so
  // identity says nothing; only the values do.
  it('treats a rebuilt but identical slice as unchanged', () => {
    expect(samePlacements([box('0.1')], [box('0.1')])).toBe(true)
  })

  it('sees a moved box', () => {
    expect(samePlacements([box('0.1')], [box('0.2')])).toBe(false)
  })

  it('sees a box added or removed', () => {
    expect(samePlacements([], [box('0.1')])).toBe(false)
    expect(samePlacements([box('0.1')], [])).toBe(false)
  })
})
