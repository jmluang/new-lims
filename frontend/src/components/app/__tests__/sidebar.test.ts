/// <reference types="node" />

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const sidebarSource = readFileSync(
  fileURLToPath(new URL('../Sidebar.tsx', import.meta.url)),
  'utf8',
)

describe('Sidebar', () => {
  it('keeps the expanded desktop navigation scrollable inside the viewport', () => {
    expect(sidebarSource).toContain('h-svh')
    expect(sidebarSource).toContain('flex-col')
    expect(sidebarSource).toContain('flex-1 overflow-y-auto')
  })
})
