import { describe, expect, it } from 'vitest'
import { canFreeze, workflowIdempotencyKey } from '../workflowAttempt'

const resumed = { uploaded: '', attempt: null, documentUuid: 'doc-1', revisionUuid: 'rev-1' }

describe('workflowIdempotencyKey', () => {
  it('derives the first attempt on a resumed document', () => {
    expect(workflowIdempotencyKey(resumed)).toBe('workflow-doc-1-rev-1')
  })

  // Reusing the derived key after a cancel replays the cancelled workflow, and
  // preparing that hangs instead of creating a new generation.
  it('uses a fresh key once one has been minted by a cancel', () => {
    expect(workflowIdempotencyKey({ ...resumed, attempt: 'workflow-fresh' })).toBe('workflow-fresh')
  })

  it('keeps the key the upload path already generated', () => {
    expect(workflowIdempotencyKey({ ...resumed, uploaded: 'workflow-uploaded', attempt: 'workflow-fresh' }))
      .toBe('workflow-uploaded')
  })

  it('has no key before anything has been finalized', () => {
    expect(workflowIdempotencyKey({ uploaded: '', attempt: null, documentUuid: null, revisionUuid: null })).toBe('')
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
