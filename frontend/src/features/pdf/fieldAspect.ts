import type { NormalizedRect } from './handwrittenApi'

/** Shape of the pad when there is no field to take it from. */
const DEFAULT_ASPECT = 900 / 320

/** A4 portrait, in points; used until the page has reported its own size. */
const A4 = { width: 595, height: 842 }

/**
 * Width over height of a signature field, in real page proportions.
 *
 * A normalized rect gives fractions of page width and height separately, so
 * the page's own proportions are half the answer: 0.16 x 0.055 is 2.9:1 by
 * those numbers alone but 2.06:1 on a page taller than it is wide.
 *
 * The pad is drawn at this shape so a signature keeps its proportions on the
 * way into the document.
 */
export function fieldAspectRatio(
  rect: Pick<NormalizedRect, 'width' | 'height'> | null | undefined,
  pageSize: { width: number; height: number } | null | undefined,
): number {
  if (!rect) return DEFAULT_ASPECT

  const width = Number(rect.width)
  const height = Number(rect.height)

  if (!(width > 0) || !(height > 0)) return DEFAULT_ASPECT

  const page = pageSize ?? A4

  return (width * page.width) / (height * page.height)
}
