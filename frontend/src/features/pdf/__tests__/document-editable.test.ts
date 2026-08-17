import { describe, expect, it } from 'vitest'
import { editableReason } from '../documentEditable'
import type { SigningDocument } from '../handwrittenApi'

// The backend is the authority on this; the list only mirrors it so the buttons
// explain themselves instead of failing on click.
const draft: SigningDocument = {
  document_uuid: '11111111-2222-3333-4444-555555555555',
  report_number: 'RPT-1',
  status: 'draft',
  stage: 'finalized_awaiting_workflow',
  integrity_state: 'ok',
  evidence_hold_state: 'none',
  has_running_work: false,
  workflow_uuid: null,
  workflow_status: null,
  signers: [],
  revisions: [{ revision_uuid: 'r1', revision_number: 1, revision_role: 'finalized_unsigned', integrity_state: 'ready' }],
  created_by_id: 7,
  is_owner: true,
  created_at: '2026-08-17T00:00:00Z',
}

describe('editableReason', () => {
  it('allows an owned draft that has only a finalized unsigned revision', () => {
    expect(editableReason(draft)).toBeNull()
  })

  it('blocks documents owned by someone else', () => {
    expect(editableReason({ ...draft, is_owner: false })).toBe('只有创建者可以修改或删除')
  })

  // Cancelling a workflow marks the document cancelled, but nothing was signed,
  // so it must stay open for a new plan.
  it('still allows a document whose workflow was cancelled', () => {
    expect(editableReason({ ...draft, status: 'cancelled', stage: 'cancelled' })).toBeNull()
  })

  it('blocks published documents', () => {
    expect(editableReason({ ...draft, status: 'published', stage: 'published' })).toBe('已发布的文档不可修改')
  })

  it('blocks documents under an evidence hold', () => {
    expect(editableReason({ ...draft, evidence_hold_state: 'active' })).toBe('文档处于证据保全中')
  })

  it('blocks documents with work still running', () => {
    expect(editableReason({ ...draft, has_running_work: true })).toBe('文档有进行中的任务')
  })

  it('blocks documents that already carry a signature revision', () => {
    expect(editableReason({
      ...draft,
      revisions: [...draft.revisions, {
        revision_uuid: 'r2',
        revision_number: 2,
        revision_role: 'handwritten_signature',
        integrity_state: 'ready',
      }],
    })).toBe('文档已有签名')
  })

  it('blocks documents where a signer has already signed', () => {
    expect(editableReason({
      ...draft,
      signers: [{
        sequence: 1,
        semantic_role: 'inspector',
        assigned_user_id: 3,
        assigned_user_name: '张三',
        status: 'signed',
        act_status: 'completed',
      }],
    })).toBe('文档已有签名')
  })
})
