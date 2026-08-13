const receivedSampleLabelIdsKey = 'new_lims_received_sample_label_ids'

export function saveSampleLabelIds(ids: number[]) {
  sessionStorage.setItem(receivedSampleLabelIdsKey, JSON.stringify(ids.filter((id) => Number.isInteger(id) && id > 0)))
}

export function loadSampleLabelIds() {
  try {
    const value = JSON.parse(sessionStorage.getItem(receivedSampleLabelIdsKey) || '[]') as unknown

    return Array.isArray(value) ? value.filter((id): id is number => Number.isInteger(id) && id > 0) : []
  } catch {
    return []
  }
}
