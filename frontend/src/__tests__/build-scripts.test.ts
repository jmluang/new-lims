import { describe, expect, it } from 'vitest'
import packageJson from '../../package.json' with { type: 'json' }

describe('frontend build scripts', () => {
  it('publishes the production frontend into the backend public app during the default build', () => {
    expect(packageJson.scripts.build).toContain('vite.backend.config.ts')
    expect(packageJson.scripts['build:backend']).toContain('vite.backend.config.ts')
  })
})
