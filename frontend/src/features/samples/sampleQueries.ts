import type { QueryClient } from '@tanstack/react-query'

export function invalidateSampleFlowQueries(queryClient: QueryClient, sampleId: number) {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: ['samples'] }),
    queryClient.invalidateQueries({ queryKey: ['sample', sampleId], exact: true }),
    queryClient.invalidateQueries({ queryKey: ['sample-flows', sampleId], exact: true }),
    queryClient.invalidateQueries({ queryKey: ['sample-flow-card', sampleId], exact: true }),
    queryClient.invalidateQueries({ queryKey: ['sample-flow-records'] }),
  ])
}
