export type RequestOperationState = {
  requestUuid: string
  operationUuid: string
}

export type SignatureDrawingState = {
  key: string
  previewUrl: string | null
  ready: boolean
}

const TERMINAL_SIGNING_STATES = ['completed', 'failed', 'irreversible_failed', 'manual_review', 'cancelled']

export function operationUuidForRequest(state: RequestOperationState, requestUuid: string): string {
  return state.requestUuid === requestUuid ? state.operationUuid : ''
}

export function drawingStateForKey(state: SignatureDrawingState, key: string): SignatureDrawingState {
  return state.key === key ? state : { key, previewUrl: null, ready: false }
}

export function isSigningTerminalState(state: string | null | undefined): boolean {
  return TERMINAL_SIGNING_STATES.includes(state ?? '')
}

export function signingTaskSwitchUnavailable(
  submitPending: boolean,
  operationPending: boolean,
  rejectPending = false,
): boolean {
  return submitPending || operationPending || rejectPending
}
