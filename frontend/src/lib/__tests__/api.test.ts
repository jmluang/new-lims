import { afterEach, describe, expect, it, vi } from 'vitest'

afterEach(() => {
  vi.unstubAllEnvs()
  vi.resetModules()
})

describe('api client', () => {
  it('defaults local development requests to the Laravel API server', async () => {
    const { api } = await import('../api')

    expect(api.defaults.baseURL).toBe('http://localhost:8000')
  })

  it('uses same-origin API requests in production when no API base URL is configured', async () => {
    vi.stubEnv('DEV', false)
    vi.stubEnv('VITE_API_BASE_URL', '')

    const { api } = await import('../api')

    expect(api.defaults.baseURL).toBeUndefined()
  })
})
