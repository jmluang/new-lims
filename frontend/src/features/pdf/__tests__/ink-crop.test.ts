import { describe, expect, it } from 'vitest'
import { inkBounds } from '../inkCrop'

/** A transparent canvas with one opaque dark rectangle of ink. */
function canvasWith(ink: { left: number; top: number; width: number; height: number } | null, size = 100): ImageData {
  const data = new Uint8ClampedArray(size * size * 4)

  if (ink) {
    for (let y = ink.top; y < ink.top + ink.height; y += 1) {
      for (let x = ink.left; x < ink.left + ink.width; x += 1) {
        const offset = (y * size + x) * 4
        data[offset] = 20
        data[offset + 1] = 40
        data[offset + 2] = 80
        data[offset + 3] = 255
      }
    }
  }

  return { data, width: size, height: size, colorSpace: 'srgb' } as ImageData
}

describe('inkBounds', () => {
  it('finds nothing on an empty pad', () => {
    expect(inkBounds(canvasWith(null))).toBeNull()
  })

  // The server keeps max(8, ceil(inkHeight * 0.12)) around the ink. Diverging
  // would make the preview show a different crop from the stored appearance.
  it('keeps the same margin the server keeps', () => {
    const bounds = inkBounds(canvasWith({ left: 40, top: 40, width: 20, height: 20 }))

    // 20 * 0.12 = 2.4, below the floor of 8.
    expect(bounds).toEqual({ left: 32, top: 32, width: 36, height: 36 })
  })

  it('scales the margin with tall ink rather than staying at the floor', () => {
    const bounds = inkBounds(canvasWith({ left: 10, top: 10, width: 30, height: 80 }))

    // ceil(80 * 0.12) = 10, above the floor.
    expect(bounds).toEqual({ left: 0, top: 0, width: 50, height: 100 })
  })

  it('crops to the ink rather than the pad, whatever shape the pad is', () => {
    const bounds = inkBounds(canvasWith({ left: 45, top: 48, width: 10, height: 4 }))!

    // A signature written small in the middle must not carry the pad's empty
    // space into a field of a different aspect ratio.
    expect(bounds.width).toBeLessThan(40)
    expect(bounds.height).toBeLessThan(40)
  })

  it('ignores pixels too faint or too transparent to be ink', () => {
    const faint = canvasWith(null)
    const offset = (50 * 100 + 50) * 4
    faint.data[offset] = 250
    faint.data[offset + 1] = 250
    faint.data[offset + 2] = 250
    faint.data[offset + 3] = 255

    expect(inkBounds(faint)).toBeNull()
  })
})
