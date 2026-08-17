/**
 * Idempotency key for creating a signing workflow.
 *
 * The first attempt on a resumed document derives its key, so a double click
 * replays the workflow it just created instead of making a second one. That
 * only holds while the workflow is wanted: once cancelled, replaying it would
 * hand back the cancelled workflow and the freeze would appear to hang. A
 * cancel therefore mints a fresh key, and the next freeze is a new generation.
 */
export function workflowIdempotencyKey(input: {
  uploaded: string
  attempt: string | null
  documentUuid: string | null
  revisionUuid: string | null
}): string {
  if (input.uploaded) return input.uploaded
  if (input.attempt) return input.attempt
  if (input.documentUuid && input.revisionUuid) return `workflow-${input.documentUuid}-${input.revisionUuid}`

  return ''
}

/** Whether the freeze action still has anything to do. */
export function canFreeze(workflowStatus: string | null | undefined): boolean {
  return workflowStatus == null || workflowStatus === 'cancelled'
}
