import { describe, expect, it } from 'vitest'
import { api } from '../api'

describe('api client', () => {
  it('defaults local development requests to the Laravel API server', () => {
    expect(api.defaults.baseURL).toBe('http://localhost:8000')
  })
})
