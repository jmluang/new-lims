import { paginationParams } from '../system/utils'

export type SampleFlowRecordFilters = {
  search: string
  action_type: string
  action_time_from: string
  action_time_to: string
}

export const emptySampleFlowRecordFilters: SampleFlowRecordFilters = {
  search: '',
  action_type: '',
  action_time_from: '',
  action_time_to: '',
}

export function buildSampleFlowRecordParams(filters: SampleFlowRecordFilters, page: number, perPage: number) {
  return Object.fromEntries(
    Object.entries({
      ...filters,
      ...paginationParams(page, perPage),
    }).filter(([, value]) => value !== ''),
  )
}
