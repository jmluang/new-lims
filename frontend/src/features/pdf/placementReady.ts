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
