import type { QueryClient } from '@tanstack/react-query'

export const integratingSphereRecordsQueryKey = ['integrating-sphere-inspection-records'] as const
export const integratingSphereEquipmentQueryKey = ['integrating-sphere-inspection-equipment'] as const

/**
 * A record and its device associations are two views of the same aggregate, so any
 * mutation invalidates both list families. Invalidating only the record list left
 * the used-equipment ledger serving rows from cache for the client's staleTime,
 * which is long enough for an operator to switch tabs and see the old associations.
 *
 * Both keys are prefixes: the page's own query keys append filters and pagination,
 * and invalidation matches them by prefix.
 */
export function invalidateIntegratingSphereLists(queryClient: QueryClient) {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: integratingSphereRecordsQueryKey }),
    queryClient.invalidateQueries({ queryKey: integratingSphereEquipmentQueryKey }),
  ])
}

/**
 * The success paths of every mutation on the inspection page. Both run the same
 * invalidation, and the save path only closes the editor once the lists have been
 * refreshed, so the list behind the modal is never a frame out of date.
 */
export function integratingSphereMutationHandlers(queryClient: QueryClient, onSaved: () => void) {
  return {
    saveSuccess: async () => {
      await invalidateIntegratingSphereLists(queryClient)
      onSaved()
    },
    deleteSuccess: () => invalidateIntegratingSphereLists(queryClient),
  }
}
