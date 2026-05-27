import { redirect } from '@tanstack/react-router'
import { api } from '../lib/api'

type EffectivePermissions = {
  resources: Record<string, { actions: Record<string, boolean> }>
}

export function allowsRoute(permissions: EffectivePermissions, resource: string, action: string) {
  return Boolean(permissions.resources[resource]?.actions[action])
}

export async function requireRoutePermission(resource: string, action = 'read') {
  const response = await api.get<{ data: EffectivePermissions }>('/api/permissions/effective')

  if (!allowsRoute(response.data.data, resource, action)) {
    throw redirect({ to: '/' })
  }
}
