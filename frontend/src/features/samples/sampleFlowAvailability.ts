export type SampleFlowAction = 'lend' | 'transfer' | 'return_room' | 'send_out' | 'receive_back' | 'return_client' | 'scrap' | 'position_change'

type SampleFlowState = {
  status?: string | null
  current_holder?: string | null
}

export function availableSampleFlowActions(sample: SampleFlowState): SampleFlowAction[] {
  const actions: SampleFlowAction[] = []

  if (sample.status === 'pending' && sample.current_holder === '样品室') {
    actions.push('lend')
  }

  if (sample.status === 'testing' && sample.current_holder !== '样品室') {
    actions.push('transfer', 'return_room')
  }

  if (sample.status === 'outsourced') {
    actions.push('receive_back')
  }

  if (!['returned', 'scrapped'].includes(sample.status ?? '')) {
    actions.push('send_out', 'return_client', 'scrap', 'position_change')
  }

  return actions
}
