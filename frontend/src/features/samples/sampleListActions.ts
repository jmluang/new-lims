export type SampleListAction = 'lend' | 'transfer' | 'return_room' | 'receive_back' | 'return_client'

export type SampleListActionSubject = {
  status: 'pending' | 'testing' | 'completed' | 'retained' | 'returned' | 'scrapped' | 'outsourced' | 'outsource_returned' | 'abnormal'
  current_holder?: string | null
}

export function visibleSampleListActions(sample: SampleListActionSubject): SampleListAction[] {
  const actions: SampleListAction[] = []
  const needsReturnRoom = sample.status === 'testing' && sample.current_holder !== '样品室'

  if (sample.status === 'pending' && sample.current_holder === '样品室') {
    actions.push('lend')
  }

  if (needsReturnRoom) {
    actions.push('transfer', 'return_room')
  }

  if (sample.status === 'outsourced') {
    actions.push('receive_back')
  }

  if (!needsReturnRoom && !['returned', 'scrapped'].includes(sample.status)) {
    actions.push('return_client')
  }

  return actions
}
