import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { SigningDocument } from '../handwrittenApi'

const state = vi.hoisted(() => ({
  document: null as SigningDocument | null,
  workflow: null as unknown,
  workflowError: null as Error | null,
}))

vi.mock('../handwrittenApi', () => ({
  fetchSigningDocument: vi.fn(async () => state.document),
  fetchSigningWorkflow: vi.fn(async () => {
    if (state.workflowError) throw state.workflowError
    return state.workflow
  }),
  downloadRevision: vi.fn(async () => new Blob(['%PDF resumed'])),
}))

const { resumePlanning, roleFromFieldName } = await import('../resumePlanning')

const baseDocument: SigningDocument = {
  document_uuid: 'doc-1',
  report_number: 'XDP-1',
  status: 'draft',
  stage: 'finalized_awaiting_workflow',
  integrity_state: 'ok',
  evidence_hold_state: 'none',
  has_running_work: false,
  workflow_uuid: null,
  workflow_status: null,
  signers: [],
  revisions: [{ revision_uuid: 'rev-1', revision_number: 1, revision_role: 'finalized_unsigned', integrity_state: 'ready' }],
  created_by_id: 1,
  is_owner: true,
  created_at: '2026-08-17T00:00:00Z',
}

describe('resumePlanning', () => {
  beforeEach(() => {
    state.document = baseDocument
    state.workflow = null
    state.workflowError = null
  })

  it('loads the finalized revision so planning skips upload and finalize', async () => {
    const resumed = await resumePlanning('doc-1')

    expect(resumed.reportNumber).toBe('XDP-1')
    expect(resumed.revision.revision_uuid).toBe('rev-1')
    expect(resumed.revision.revision_role).toBe('finalized_unsigned')
    expect(resumed.file.size).toBeGreaterThan(0)
    expect(resumed.activeWorkflow).toBeNull()
  })

  it('refuses a document that has not been finalized yet', async () => {
    state.document = { ...baseDocument, revisions: [] }

    await expect(resumePlanning('doc-1')).rejects.toThrow('还没有定稿版本')
  })

  it('restores the previous positions and assignees so they can be edited', async () => {
    state.document = { ...baseDocument, workflow_uuid: 'wf-1', workflow_status: 'ready' }
    state.workflow = {
      workflow_uuid: 'wf-1',
      status: 'ready',
      requests: [
        { sequence: 1, semantic_role: 'inspector', assigned_user_id: 11, status: 'available' },
        { sequence: 2, semantic_role: 'reviewer', assigned_user_id: 22, status: 'pending' },
        { sequence: 3, semantic_role: 'issuer', assigned_user_id: 33, status: 'pending' },
      ],
      fields: [
        { field_name: 'lims_inspector_g1', status: 'prepared', slots: [{ page_index: 2, normalized_rect: { x: '0.5', y: '0.5', width: '0.2', height: '0.1' } }] },
        { field_name: 'lims_reviewer_g1', status: 'prepared', slots: [{ page_index: 0, normalized_rect: { x: '0.1', y: '0.2', width: '0.2', height: '0.1' } }] },
      ],
    }

    const resumed = await resumePlanning('doc-1')

    expect(resumed.assignments).toEqual({ inspector: 11, reviewer: 22, issuer: 33 })
    expect(resumed.placements).toEqual([
      { semantic_role: 'inspector', page_index: 2, normalized_rect: { x: '0.5', y: '0.5', width: '0.2', height: '0.1' } },
      { semantic_role: 'reviewer', page_index: 0, normalized_rect: { x: '0.1', y: '0.2', width: '0.2', height: '0.1' } },
    ])
    // Surfaced so the workspace can require cancelling it before replanning.
    expect(resumed.activeWorkflow).toEqual({ workflow_uuid: 'wf-1', status: 'ready' })
  })

  it('still resumes when the previous plan cannot be read', async () => {
    state.document = { ...baseDocument, workflow_uuid: 'wf-1', workflow_status: 'ready' }
    state.workflowError = new Error('boom')

    const resumed = await resumePlanning('doc-1')

    expect(resumed.assignments).toBeNull()
    expect(resumed.placements).toBeNull()
    expect(resumed.revision.revision_uuid).toBe('rev-1')
  })
})

describe('roleFromFieldName', () => {
  it('reads the role out of the server-generated field name', () => {
    expect(roleFromFieldName('lims_inspector_g1')).toBe('inspector')
    expect(roleFromFieldName('lims_issuer_g12')).toBe('issuer')
  })

  it('ignores names it does not recognise', () => {
    expect(roleFromFieldName('lims_homepage_seal_g1')).toBeNull()
    expect(roleFromFieldName('something_else')).toBeNull()
  })
})
