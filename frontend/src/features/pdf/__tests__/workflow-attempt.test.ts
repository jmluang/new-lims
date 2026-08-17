import { describe, expect, it } from 'vitest'
import { canFreeze, workflowIdempotencyKey } from '../workflowAttempt'

const resumed = { uploaded: '', previousWorkflowUuid: null, documentUuid: 'doc-1', revisionUuid: 'rev-1' }

describe('workflowIdempotencyKey', () => {
  it('derives the first generation from the document and its revision', () => {
    expect(workflowIdempotencyKey(resumed)).toBe('workflow-doc-1-rev-1')
  })

  it('is stable across renders so a double click replays instead of creating twice', () => {
    expect(workflowIdempotencyKey(resumed)).toBe(workflowIdempotencyKey(resumed))
  })

  // Reusing the first key after a cancel replays the cancelled workflow, and the
  // freeze looks like it did nothing at all.
  it('changes once a generation exists', () => {
    const next = workflowIdempotencyKey({ ...resumed, previousWorkflowUuid: 'wf-1' })

    expect(next).toBe('workflow-after-wf-1')
    expect(next).not.toBe(workflowIdempotencyKey(resumed))
  })

  // The cancel may have happened before the page was ever opened, so this cannot
  // depend on having observed it.
  it('gives every generation its own key', () => {
    const first = workflowIdempotencyKey(resumed)
    const second = workflowIdempotencyKey({ ...resumed, previousWorkflowUuid: 'wf-1' })
    const third = workflowIdempotencyKey({ ...resumed, previousWorkflowUuid: 'wf-2' })

    expect(new Set([first, second, third]).size).toBe(3)
  })

  it('keeps the key the upload path already generated', () => {
    expect(workflowIdempotencyKey({ ...resumed, uploaded: 'workflow-uploaded', previousWorkflowUuid: 'wf-1' }))
      .toBe('workflow-uploaded')
  })

  it('has no key before anything has been finalized', () => {
    expect(workflowIdempotencyKey({ uploaded: '', previousWorkflowUuid: null, documentUuid: null, revisionUuid: null }))
      .toBe('')
  })
})

describe('canFreeze', () => {
  it('allows freezing when no workflow exists yet', () => {
    expect(canFreeze(null)).toBe(true)
    expect(canFreeze(undefined)).toBe(true)
  })

  it('allows freezing again after a cancel, which frees the document', () => {
    expect(canFreeze('cancelled')).toBe(true)
  })

  it('refuses once the fields are committed', () => {
    expect(canFreeze('ready')).toBe(false)
    expect(canFreeze('signing')).toBe(false)
    expect(canFreeze('preparing')).toBe(false)
  })
})
