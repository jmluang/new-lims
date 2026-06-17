import type { EffectivePermissions } from '../auth/useCurrentUser'

export function canUseSampleFlowAction(action: string, permissions?: EffectivePermissions | null) {
  if (action !== 'return_room') {
    return true
  }

  return Boolean(permissions?.resources.sample_flows?.actions.return_room)
}

export function visibleSampleFlowActions<T extends string>(actions: readonly T[], permissions?: EffectivePermissions | null): T[] {
  return actions.filter((action) => canUseSampleFlowAction(action, permissions))
}
