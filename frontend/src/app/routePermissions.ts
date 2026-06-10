import { redirect } from '@tanstack/react-router'
import { clearAuthToken, isUnauthorizedError } from '../lib/api'
import { queryClient } from '../lib/query-client'
import { effectivePermissionsQueryKey, fetchEffectivePermissions } from '../features/auth/useCurrentUser'

type EffectivePermissions = {
  resources: Record<string, { actions: Record<string, boolean> }>
}

export function allowsRoute(permissions: EffectivePermissions, resource: string, action: string) {
  return Boolean(permissions.resources[resource]?.actions[action])
}

export async function requireRoutePermission(resource: string, action = 'read') {
  let permissions: EffectivePermissions

  try {
    permissions = await queryClient.fetchQuery({
      queryKey: effectivePermissionsQueryKey,
      queryFn: fetchEffectivePermissions,
      retry: false,
    })
  } catch (error) {
    if (isUnauthorizedError(error)) {
      clearAuthToken()
      throw redirect({ to: '/login' })
    }

    throw error
  }

  if (!allowsRoute(permissions, resource, action)) {
    throw redirect({ to: '/' })
  }
}
