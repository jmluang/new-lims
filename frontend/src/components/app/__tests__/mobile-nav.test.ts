/// <reference types="node" />

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const mobileNavSource = readFileSync(
  fileURLToPath(new URL('../MobileNav.tsx', import.meta.url)),
  'utf8',
)

describe('MobileNav', () => {
  it('renders the drawer through a body portal so the sticky header cannot clip it on mobile browsers', () => {
    expect(mobileNavSource).toContain('createPortal')
    expect(mobileNavSource).toContain('document.body')
  })
})
