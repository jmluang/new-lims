/**
 * Crop a signature canvas to its ink, the way the server will.
 *
 * SignatureAppearanceService throws away everything outside the ink's bounding
 * box and keeps a 12% margin around it. A preview of the whole pad therefore
 * shows something the document will never contain: the empty space above and
 * below whatever was written, letterboxed into a field of a different shape.
 * Cropping here first makes the preview and the stored appearance agree.
 */
const PADDING_RATIO = 0.12
const MINIMUM_PADDING = 8

/** Same thresholds the server uses to decide a pixel counts as ink. */
const ALPHA_THRESHOLD = 124
const LUMINANCE_THRESHOLD = 248

export type InkBounds = { left: number; top: number; width: number; height: number }

export function inkBounds(pixels: ImageData): InkBounds | null {
  let left = pixels.width
  let top = pixels.height
  let right = -1
  let bottom = -1

  for (let y = 0; y < pixels.height; y += 1) {
    for (let x = 0; x < pixels.width; x += 1) {
      const offset = (y * pixels.width + x) * 4
      const alpha = 255 - pixels.data[offset + 3]
      const luminance = 0.299 * pixels.data[offset] + 0.587 * pixels.data[offset + 1] + 0.114 * pixels.data[offset + 2]

      if (alpha < ALPHA_THRESHOLD && luminance < LUMINANCE_THRESHOLD) {
        if (x < left) left = x
        if (y < top) top = y
        if (x > right) right = x
        if (y > bottom) bottom = y
      }
    }
  }

  if (right < left || bottom < top) return null

  const inkWidth = right - left + 1
  const inkHeight = bottom - top + 1
  const padding = Math.max(MINIMUM_PADDING, Math.ceil(inkHeight * PADDING_RATIO))

  return {
    left: left - padding,
    top: top - padding,
    width: inkWidth + 2 * padding,
    height: inkHeight + 2 * padding,
  }
}
