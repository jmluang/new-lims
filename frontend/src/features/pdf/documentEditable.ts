import type { SigningDocument } from './handwrittenApi'

/**
 * Revision roles that carry no signature.
 *
 * `prepared` is the revision that holds the empty signature fields created
 * before the first signature; treating it as signed would lock a document that
 * nobody has signed. Anything else — approval_signature, organization_seal,
 * legacy_signed_output — means a signature exists and the document is history.
 */
const UNSIGNED_ROLES = ['finalized_unsigned', 'prepared']

/** Workflow states that no longer own the document. */
const SETTLED_WORKFLOW = ['cancelled', 'failed']

function signed(document: SigningDocument): boolean {
  return document.revisions.some(
    (revision) => revision.revision_role !== null && !UNSIGNED_ROLES.includes(revision.revision_role),
  ) || document.signers.some((signer) => signer.status === 'signed')
}

function frozen(document: SigningDocument): string | null {
  if (!document.is_owner) return '只有创建者可以修改或删除'
  if (document.stage === 'published' || document.status === 'published') return '已发布的报告不可修改'
  if (document.evidence_hold_state !== 'none') return '该报告处于证据保全中'
  if (document.integrity_state !== 'ok') return '该报告处于完整性保护中'
  if (document.has_running_work) return '该报告有正在处理的操作'
  if (signed(document)) return '该报告已有签名'

  return null
}

/**
 * Null when the report number may be changed or the document removed.
 *
 * A live workflow owns the document, so it has to be cancelled first — which is
 * done from the planning workspace, and {@link planReason} lets you get there.
 */
export function editableReason(document: SigningDocument): string | null {
  const blocked = frozen(document)
  if (blocked) return blocked

  if (document.workflow_status && !SETTLED_WORKFLOW.includes(document.workflow_status)) {
    return '该报告正在签署中，需先在编排页取消'
  }

  return null
}

/**
 * Null when the signing plan may be opened for editing.
 *
 * Deliberately permitted while a workflow is live: that page is where it gets
 * cancelled, and blocking the way in would leave no way to replan.
 */
export function planReason(document: SigningDocument): string | null {
  return frozen(document)
}
