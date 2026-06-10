import { beforeEach, describe, expect, it, vi } from 'vitest'
import { queryClient } from '../../lib/query-client'
import { requireRoutePermission } from '../routePermissions'

const apiGet = vi.hoisted(() => vi.fn())

vi.mock('../../lib/api', () => ({
  api: { get: apiGet },
}))

describe('requireRoutePermission', () => {
  beforeEach(() => {
    queryClient.clear()
    apiGet.mockReset()
  })

  it('stores effective permissions in the shared query cache for PermissionGate reuse', async () => {
    const permissions = {
      resources: {
        equipment_systems: {
          actions: { read: true },
          fields: {},
        },
      },
    }
    apiGet.mockResolvedValueOnce({ data: { data: permissions } })

    await requireRoutePermission('equipment_systems')

    expect(queryClient.getQueryData(['effective-permissions'])).toEqual(permissions)
    expect(apiGet).toHaveBeenCalledTimes(1)
  })
})
