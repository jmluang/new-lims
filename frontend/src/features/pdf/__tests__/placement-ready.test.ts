import { describe, expect, it } from 'vitest'
import { placementPagesReady } from '../placementReady'

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
