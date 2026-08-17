import type { Placement } from './handwrittenApi'

export type DragState = {
  role: Placement['semantic_role']
  mode: 'move' | 'resize'
  startX: number
  startY: number
  rect: { x: number; y: number; width: number; height: number }
  pageWidth: number
  pageHeight: number
}

/** Smallest box we let a drag produce, as a fraction of the page. */
const MINIMUM = 0.035

/**
 * Apply a pointer delta to the dragged box.
 *
 * Offsets are measured from where the drag began rather than from the previous
 * move, so a dropped frame cannot accumulate drift. Boxes stay inside the page.
 */
export function applyDrag(
  placements: readonly Placement[],
  drag: DragState,
  pageIndex: number,
  clientX: number,
  clientY: number,
): Placement[] {
  const dx = (clientX - drag.startX) / drag.pageWidth
  const dy = (clientY - drag.startY) / drag.pageHeight

  return placements.map((placement) => {
    if (placement.semantic_role !== drag.role || placement.page_index !== pageIndex) return placement
    let { x, y, width, height } = drag.rect

    if (drag.mode === 'move') {
      x = clamp(x + dx, 0, 1 - width)
      y = clamp(y + dy, 0, 1 - height)
    } else {
      width = clamp(width + dx, MINIMUM, 1 - x)
      height = clamp(height + dy, MINIMUM, 1 - y)
    }

    return {
      ...placement,
      normalized_rect: { x: decimal(x), y: decimal(y), width: decimal(width), height: decimal(height) },
    }
  })
}

function clamp(value: number, min: number, max: number) {
  return Math.min(Math.max(value, min), max)
}

function decimal(value: number) {
  return value.toFixed(6)
}
