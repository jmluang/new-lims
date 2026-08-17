import { describe, expect, it } from 'vitest'
import { applyDrag, type DragState } from '../placementDrag'
import type { Placement } from '../handwrittenApi'

const inspector: Placement = {
  semantic_role: 'inspector',
  page_index: 0,
  normalized_rect: { x: '0.200000', y: '0.500000', width: '0.160000', height: '0.055000' },
}

const reviewer: Placement = {
  semantic_role: 'reviewer',
  page_index: 0,
  normalized_rect: { x: '0.600000', y: '0.500000', width: '0.160000', height: '0.055000' },
}

function drag(overrides: Partial<DragState> = {}): DragState {
  return {
    role: 'inspector',
    mode: 'move',
    startX: 100,
    startY: 100,
    rect: { x: 0.2, y: 0.5, width: 0.16, height: 0.055 },
    pageWidth: 1000,
    pageHeight: 1000,
    ...overrides,
  }
}

describe('applyDrag', () => {
  it('moves the dragged box by the pointer delta', () => {
    const [moved] = applyDrag([inspector], drag(), 0, 200, 300)

    expect(moved.normalized_rect.x).toBe('0.300000')
    expect(moved.normalized_rect.y).toBe('0.700000')
    expect(moved.normalized_rect.width).toBe('0.160000')
  })

  it('leaves the other boxes untouched', () => {
    const [, untouched] = applyDrag([inspector, reviewer], drag(), 0, 200, 300)

    expect(untouched).toBe(reviewer)
  })

  it('does not move a box of the same role on another page', () => {
    const elsewhere = { ...inspector, page_index: 4 }
    const [same] = applyDrag([elsewhere], drag(), 0, 200, 300)

    expect(same).toBe(elsewhere)
  })

  it('keeps a moved box inside the page', () => {
    const [clamped] = applyDrag([inspector], drag(), 0, 5000, 5000)

    expect(clamped.normalized_rect.x).toBe('0.840000')
    expect(clamped.normalized_rect.y).toBe('0.945000')
  })

  it('resizes from the drag origin instead of the previous move', () => {
    const [resized] = applyDrag([inspector], drag({ mode: 'resize' }), 0, 300, 200)

    expect(resized.normalized_rect.width).toBe('0.360000')
    expect(resized.normalized_rect.height).toBe('0.155000')
    // Resizing never shifts the corner it grows from.
    expect(resized.normalized_rect.x).toBe('0.200000')
  })

  it('refuses to shrink a box below the minimum', () => {
    const [tiny] = applyDrag([inspector], drag({ mode: 'resize' }), 0, -5000, -5000)

    expect(tiny.normalized_rect.width).toBe('0.035000')
    expect(tiny.normalized_rect.height).toBe('0.035000')
  })

  // Offsets come from where the drag began, so a dropped frame cannot make the
  // box drift away from the pointer.
  it('lands in the same place whether or not intermediate moves were seen', () => {
    const direct = applyDrag([inspector], drag(), 0, 250, 250)
    const viaMiddle = applyDrag(applyDrag([inspector], drag(), 0, 150, 150), drag(), 0, 250, 250)

    expect(viaMiddle[0].normalized_rect).toEqual(direct[0].normalized_rect)
  })
})
