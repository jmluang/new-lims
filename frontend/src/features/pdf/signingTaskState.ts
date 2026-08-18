export type RequestOperationState = {
  requestUuid: string
  operationUuid: string
}

export type SignatureDrawingState = {
  key: string
  previewUrl: string | null
  ready: boolean
}

export function operationUuidForRequest(state: RequestOperationState, requestUuid: string): string {
  return state.requestUuid === requestUuid ? state.operationUuid : ''
}

export function drawingStateForKey(state: SignatureDrawingState, key: string): SignatureDrawingState {
  return state.key === key ? state : { key, previewUrl: null, ready: false }
}
