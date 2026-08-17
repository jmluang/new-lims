import type { SigningDocument } from './handwrittenApi'

/**
 * Null when the document may be renamed or deleted, otherwise why it may not.
 *
 * The backend is the authority — it re-checks every one of these under a row
 * lock. This mirror exists so the buttons can explain themselves up front
 * instead of failing on click.
 */
export function editableReason(document: SigningDocument): string | null {
  if (!document.is_owner) return '只有创建者可以修改或删除'
  if (document.stage === 'published' || document.status !== 'draft') return '已发布的文档不可修改'
  if (document.evidence_hold_state !== 'none') return '文档处于证据保全中'
  if (document.integrity_state !== 'ok') return '文档处于完整性保护中'
  if (document.has_running_work) return '文档有进行中的任务'
  if (document.revisions.some((revision) => revision.revision_role !== 'finalized_unsigned')) return '文档已有签名'
  if (document.signers.some((signer) => signer.status === 'signed')) return '文档已有签名'

  return null
}
