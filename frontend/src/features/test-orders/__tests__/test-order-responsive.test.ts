import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const source = readFileSync(fileURLToPath(new URL('../TestOrderEntrustForm.tsx', import.meta.url)), 'utf8')

describe('test order detail responsive layout', () => {
  it('stacks paired fields on mobile and restores the formal grid on desktop', () => {
    expect(source).toContain('grid-cols-[7rem_minmax(0,1fr)]')
    expect(source).toContain('md:grid-cols-[7rem_minmax(0,1fr)_7rem_minmax(0,1fr)]')
    expect(source).toContain('md:grid-cols-[6rem_minmax(0,1fr)_6rem_minmax(0,1fr)_6rem_minmax(0,1fr)]')
    expect(source).not.toContain('grid-cols-[6rem_1fr_6rem_1fr_6rem_1fr]')
  })

  it('keeps confirmation rows readable instead of squeezing ten cells together', () => {
    expect(source).toContain('function ConfirmationRow')
    expect(source).toContain('grid-cols-[7rem_minmax(0,1fr)] md:grid-cols-5')
    expect(source).toContain('grid-cols-[4rem_minmax(0,1fr)] md:contents')
  })

  it('uses phone-sized touch targets for editable controls', () => {
    expect(source).toContain("const cellInput = 'h-11")
    expect(source).toContain('min-h-11 items-center')
    expect(source).toContain('md:h-9')
  })
})
