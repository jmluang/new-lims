/**
 * Idempotency key for creating a signing workflow.
 *
 * Keys are derived rather than random so a double click replays the workflow it
 * just created instead of making a second one. The derivation therefore has to
 * change once a generation exists: reusing the key of a cancelled workflow
 * replays that workflow, and the freeze looks like it did nothing.
 *
 * Deriving each attempt from the generation before it gives every generation
 * its own key, whether the cancel happened a moment ago or before the page was
 * ever opened.
 */
export function workflowIdempotencyKey(input: {
  uploaded: string
  previousWorkflowUuid: string | null
  documentUuid: string | null
  revisionUuid: string | null
}): string {
  if (input.uploaded) return input.uploaded
  if (input.previousWorkflowUuid) return `workflow-after-${input.previousWorkflowUuid}`
  if (input.documentUuid && input.revisionUuid) return `workflow-${input.documentUuid}-${input.revisionUuid}`

  return ''
}

/** Whether the freeze action still has anything to do. */
export function canFreeze(workflowStatus: string | null | undefined): boolean {
  return workflowStatus == null || workflowStatus === 'cancelled'
}
