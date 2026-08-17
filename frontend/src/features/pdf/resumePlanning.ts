import {
  downloadRevision,
  fetchSigningDocument,
  fetchSigningWorkflow,
  type FinalizedPlanningRevision,
  type Placement,
  type SignatureRole,
} from './handwrittenApi'

export type ResumedPlanning = {
  reportNumber: string
  revision: FinalizedPlanningRevision
  file: Blob
  /** Restored from the previous plan when there is one, otherwise null. */
  assignments: Record<SignatureRole, number> | null
  placements: Placement[] | null
  /** Set while a workflow still owns the document, so it can be cancelled first. */
  activeWorkflow: { workflow_uuid: string; status: string } | null
}

/**
 * Reload an existing document into the planning workspace.
 *
 * Uploading, confirming and finalizing already happened, so planning starts from
 * the stored finalized revision instead of a fresh file. When the document was
 * planned before, the previous positions and assignees come back with it so the
 * operator edits rather than retypes — the previous workflow still has to be
 * cancelled before a new generation can replace it.
 */
export async function resumePlanning(documentUuid: string): Promise<ResumedPlanning> {
  const document = await fetchSigningDocument(documentUuid)
  const finalized = document.revisions.find((revision) => revision.revision_role === 'finalized_unsigned')

  if (!finalized) {
    throw new Error('该文档还没有定稿版本，无法编排签名位置')
  }

  const file = await downloadRevision(finalized.revision_uuid)
  const revision: FinalizedPlanningRevision = {
    revision_uuid: finalized.revision_uuid,
    revision_number: finalized.revision_number ?? 0,
    revision_role: 'finalized_unsigned',
    sha256: '',
    file_size: file.size,
    integrity_state: finalized.integrity_state,
    disposition: 'active',
  }

  let assignments: Record<SignatureRole, number> | null = null
  let placements: Placement[] | null = null

  if (document.workflow_uuid) {
    try {
      const workflow = await fetchSigningWorkflow(document.workflow_uuid)
      const restoredAssignments = {} as Record<SignatureRole, number>

      for (const request of workflow.requests) {
        restoredAssignments[request.semantic_role] = request.assigned_user_id
      }

      const restoredPlacements = workflow.fields
        .map((field): Placement | null => {
          const role = roleFromFieldName(field.field_name)
          const slot = field.slots[0]

          return role && slot
            ? { semantic_role: role, page_index: slot.page_index, normalized_rect: slot.normalized_rect }
            : null
        })
        .filter((placement): placement is Placement => placement !== null)

      if (Object.keys(restoredAssignments).length > 0) assignments = restoredAssignments
      if (restoredPlacements.length > 0) placements = restoredPlacements
    } catch {
      // A plan we cannot read is not worth blocking on; fall back to defaults.
    }
  }

  return {
    reportNumber: document.report_number,
    revision,
    file,
    assignments,
    placements,
    activeWorkflow: document.workflow_uuid && document.workflow_status
      ? { workflow_uuid: document.workflow_uuid, status: document.workflow_status }
      : null,
  }
}

/** Document handed over by the signing document list to continue planning. */
export function resumeDocumentUuid(): string | null {
  return new URLSearchParams(window.location.search).get('document')
}

/** Field names are generated server-side as lims_{role}_g{generation}. */
export function roleFromFieldName(fieldName: string): SignatureRole | null {
  const match = /^lims_(inspector|reviewer|issuer)_g\d+$/.exec(fieldName)

  return match ? (match[1] as SignatureRole) : null
}
