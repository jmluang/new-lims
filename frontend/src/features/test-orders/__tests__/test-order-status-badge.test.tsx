import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { StatusBadge } from '../../system/shared'

const testOrderStatusPalettes = {
  not_received: 'slate',
  partially_received: 'amber',
  received: 'sky',
  testing: 'indigo',
  completed: 'emerald',
} as const

describe('test order status badge', () => {
  it.each(Object.entries(testOrderStatusPalettes))('uses the %s workflow palette', (status, palette) => {
    const markup = renderToStaticMarkup(<StatusBadge status={status} />)

    expect(markup).toContain(`border-${palette}-200`)
    expect(markup).toContain(`bg-${palette}-`)
    expect(markup).toContain(`text-${palette}-700`)
  })

  it('gives every workflow status a distinct color', () => {
    expect(new Set(Object.values(testOrderStatusPalettes)).size).toBe(Object.keys(testOrderStatusPalettes).length)
  })
})
