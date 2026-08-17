import type { Placement } from './handwrittenApi'

/**
 * Whether every page that carries a placement box has reported its size.
 *
 * Boxes are positioned as percentages of their page, so until the page is
 * measured they sit in a 1x1 container — in the DOM, but not where they belong.
 * Scrolling a document whose boxes have not landed yet looks like they are
 * missing, so the workspace waits.
 *
 * Only pages that carry a box are waited on; a long report should not stay
 * locked because a page nobody is looking at is still rendering.
 */
export function placementPagesReady(placementPages: number[], measuredPages: ReadonlySet<number>): boolean {
  return [...new Set(placementPages)].every((pageIndex) => measuredPages.has(pageIndex))
}

/**
 * Whether two page slices of the plan are equivalent.
 *
 * The workspace re-filters this array for every page on every render, so a
 * memoized page has to compare by value. Without it, dragging one box
 * reconciles every page in the report.
 */
export function samePlacements(previous: readonly Placement[], next: readonly Placement[]): boolean {
  return previous.length === next.length
    && previous.every((placement, index) => {
      const other = next[index]

      return placement.semantic_role === other.semantic_role
        && placement.page_index === other.page_index
        && placement.normalized_rect.x === other.normalized_rect.x
        && placement.normalized_rect.y === other.normalized_rect.y
        && placement.normalized_rect.width === other.normalized_rect.width
        && placement.normalized_rect.height === other.normalized_rect.height
    })
}

/**
 * Style for the box a PDF page is drawn in.
 *
 * A fixed height beside maxWidth stops placements scaling with the page: a
 * narrower column squeezes the width while the height stays, and every box —
 * positioned in percentages of this container — is stretched with it. That is
 * why a field looked one shape while planning and another while signing, where
 * the sidebar is wider and the page column correspondingly narrower.
 */
export function pageBoxStyle(size: { width: number; height: number }) {
  return {
    width: size.width,
    maxWidth: '100%',
    aspectRatio: `${size.width} / ${size.height}`,
  } as const
}
