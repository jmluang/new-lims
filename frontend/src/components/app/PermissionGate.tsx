import type { PropsWithChildren, ReactNode } from 'react'
import { useEffectivePermissions } from '../../features/auth/useCurrentUser'

type PermissionGateProps = PropsWithChildren<{
  resource: string
  action: string
  field?: string
  fallback?: ReactNode
}>

export function PermissionGate({ resource, action, field, fallback = null, children }: PermissionGateProps) {
  const permissions = useEffectivePermissions()
  const resourcePermissions = permissions.data?.resources[resource]
  const allowed = field
    ? Boolean(resourcePermissions?.fields[field]?.[action])
    : Boolean(resourcePermissions?.actions[action])

  if (!allowed) {
    return fallback
  }

  return children
}
