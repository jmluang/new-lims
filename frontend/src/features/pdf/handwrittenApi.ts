import { api } from '../../lib/api'

export type NormalizedRect = { x: string; y: string; width: string; height: string }
export type SignatureRole = 'inspector' | 'reviewer' | 'issuer'

export type Placement = {
  semantic_role: SignatureRole
  page_index: number
  normalized_rect: NormalizedRect
}

export type PlanningOptions = {
  assignees: Array<{ id: number; name: string }>
  policies: Array<{ version_uuid: string; signing_material_version: string; policy_hash: string }>
}

export type InspectedSigningSource = {
  source_uuid: string
  sha256: string
  file_size: number
  page_count: number
  status: string
  inspection: {
    pageCount?: number
    pages?: Array<Record<string, unknown>>
  } | null
}

export type FinalizedPlanningRevision = {
  revision_uuid: string
  revision_number: number
  revision_role: 'finalized_unsigned'
  sha256: string
  file_size: number
  integrity_state: string
  disposition: string
}

export type SigningOperation = {
  operation_uuid: string
  action: string
  state: string
  stage: string
  java_execution_state?: string | null
  error_code?: string | null
  result_revision_uuid?: string | null
  result_sha256?: string | null
  result_size?: number | null
  status_url: string
}

export type SigningRequestSummary = {
  request_uuid: string
  status: string
  sequence: number
  semantic_role: SignatureRole
  report_number: string
  workflow_uuid: string
  field_name: string
  revision_uuid: string
  created_at: string
}

export type SigningRequestDetail = {
  request_uuid: string
  status: string
  sequence: number
  semantic_role: SignatureRole
  pdf_signature_role: 'certification_p2' | 'approval'
  field: {
    field_uuid: string
    field_name: string
    slots: Array<{ page_index: number; widget_index: number; normalized_rect: NormalizedRect }>
  }
  revision: { revision_uuid: string; sha256: string; file_size: number }
  certificate_subject: string
}

export type DocumentSigner = {
  sequence: number
  semantic_role: SignatureRole | null
  assigned_user_id: number
  assigned_user_name: string | null
  status: string
  act_status: string | null
}

export type SigningDocument = {
  document_uuid: string
  report_number: string
  status: string
  stage: string
  integrity_state: string
  evidence_hold_state: string
  has_running_work: boolean
  workflow_uuid: string | null
  workflow_status: string | null
  signers: DocumentSigner[]
  revisions: Array<{
    revision_uuid: string
    revision_number: number | null
    revision_role: string | null
    integrity_state: string
  }>
  created_by_id: number
  is_owner: boolean
  created_at: string
}

export async function fetchSigningDocuments(params: { search?: string; page?: number; perPage?: number } = {}) {
  const response = await api.get<{ data: SigningDocument[]; meta: { current_page: number; per_page: number; total: number } }>(
    '/api/pdf/documents',
    { params: { search: params.search || undefined, page: params.page || undefined, per_page: params.perPage || undefined } },
  )
  return response.data
}

export async function fetchSigningDocument(documentUuid: string) {
  const response = await api.get<{ data: SigningDocument }>(`/api/pdf/documents/${documentUuid}`)
  return response.data.data
}

export async function fetchSigningWorkflow(workflowUuid: string) {
  const response = await api.get<{
    data: {
      workflow_uuid: string
      status: string
      requests: Array<{ sequence: number; semantic_role: SignatureRole; assigned_user_id: number; status: string }>
      fields: Array<{
        field_name: string
        status: string
        slots: Array<{ page_index: number; normalized_rect: NormalizedRect }>
      }>
    }
  }>(`/api/pdf/signing-workflows/${workflowUuid}`)
  return response.data.data
}

export async function renameSigningDocument(documentUuid: string, reportNumber: string) {
  const response = await api.patch<{ data: SigningDocument }>(`/api/pdf/documents/${documentUuid}`, {
    report_number: reportNumber,
  })
  return response.data.data
}

export async function deleteSigningDocument(documentUuid: string) {
  const response = await api.delete<{ data: { document_uuid: string; report_number: string; deleted_files: number } }>(
    `/api/pdf/documents/${documentUuid}`,
  )
  return response.data.data
}

export async function fetchPlanningOptions() {
  const response = await api.get<{ data: PlanningOptions }>('/api/pdf/handwritten-signing/options')
  return response.data.data
}

export async function fetchAssignedSigningRequests() {
  const response = await api.get<{ data: SigningRequestSummary[] }>('/api/pdf/signing-requests')
  return response.data.data
}

export async function fetchSigningRequest(requestUuid: string) {
  const response = await api.get<{ data: SigningRequestDetail }>(`/api/pdf/signing-requests/${requestUuid}`)
  return response.data.data
}

export async function downloadRevision(revisionUuid: string) {
  const response = await api.get<Blob>(`/api/pdf/revisions/${revisionUuid}/download`, { responseType: 'blob' })
  return response.data
}

export async function inspectSigningSource(file: File) {
  const sourceForm = new FormData()
  sourceForm.append('pdf_file', file)
  const response = await api.post<{ data: InspectedSigningSource }>('/api/pdf/signing-sources/inspect', sourceForm)
  return response.data.data
}

export async function confirmAndFinalizeSigningSource(input: { sourceUuid: string; reportNumber: string }) {
  await api.post(`/api/pdf/signing-sources/${input.sourceUuid}/confirm`, { report_number: input.reportNumber })
  const response = await api.post<{ data: SigningOperation }>(
    `/api/pdf/signing-sources/${input.sourceUuid}/finalize`,
    undefined,
    { headers: { 'Idempotency-Key': `finalize-${input.sourceUuid}` } },
  )
  const operation = await waitForSigningOperation(response.data.data)
  if (operation.state !== 'completed' || !operation.result_revision_uuid) {
    throw new Error(operation.error_code ?? 'PDF 定稿 operation 未完成')
  }
  const file = await downloadRevision(operation.result_revision_uuid)
  const revision: FinalizedPlanningRevision = {
    revision_uuid: operation.result_revision_uuid,
    revision_number: 0,
    revision_role: 'finalized_unsigned',
    sha256: operation.result_sha256 ?? '',
    file_size: operation.result_size ?? file.size,
    integrity_state: 'ready',
    disposition: 'active',
  }

  return { revision, file }
}

export async function createPreparedSigningWorkflow(input: {
  planningRevisionUuid: string
  idempotencyKey: string
  policyVersionUuid: string
  assignments: Record<'inspector' | 'reviewer' | 'issuer', number>
  placements: Placement[]
}) {
  const workflow = await api.post<{ data: { workflow_uuid: string } }>(
    '/api/pdf/signing-workflows',
    {
      planning_revision_uuid: input.planningRevisionUuid,
      signing_policy_version_uuid: input.policyVersionUuid,
      assignments: input.assignments,
      placements: input.placements.map((placement) => ({
        ...placement,
        normalized_rect: {
          x: Number(placement.normalized_rect.x).toFixed(6),
          y: Number(placement.normalized_rect.y).toFixed(6),
          width: Number(placement.normalized_rect.width).toFixed(6),
          height: Number(placement.normalized_rect.height).toFixed(6),
        },
      })),
    },
    { headers: { 'Idempotency-Key': input.idempotencyKey } },
  )
  const prepared = await api.post<{ data: SigningOperation }>(
    `/api/pdf/signing-workflows/${workflow.data.data.workflow_uuid}/prepare`,
    undefined,
    { headers: { 'Idempotency-Key': `prepare-${workflow.data.data.workflow_uuid}` } },
  )
  const operation = await waitForSigningOperation(prepared.data.data)
  if (operation.state !== 'completed') {
    throw new Error(operation.error_code ?? 'PDF 字段准备 operation 未完成')
  }
  const result = await api.get<{ data: { workflow_uuid: string; status: string } }>(
    `/api/pdf/signing-workflows/${workflow.data.data.workflow_uuid}`,
  )

  return result.data.data
}

export async function submitSignatureAppearance(input: {
  requestUuid: string
  appearance: Blob
  fileName: string
  currentPassword: string
}) {
  const appearanceForm = new FormData()
  appearanceForm.append('appearance', input.appearance, input.fileName)
  const appearance = await api.post<{ data: { appearance_uuid: string } }>(
    `/api/pdf/signing-requests/${input.requestUuid}/appearances`,
    appearanceForm,
  )
  const challenge = await api.post<{ data: { challenge_uuid: string } }>(
    `/api/pdf/signing-requests/${input.requestUuid}/challenge`,
    {
      appearance_uuid: appearance.data.data.appearance_uuid,
      current_password: input.currentPassword,
    },
  )
  const idempotencyKey = `handwritten-${input.requestUuid}-${crypto.randomUUID()}`
  const operation = await api.post<{
    data: { operation_uuid: string; state: string; stage: string; status_url: string }
  }>(
    `/api/pdf/signing-requests/${input.requestUuid}/sign`,
    { challenge_uuid: challenge.data.data.challenge_uuid },
    { headers: { 'Idempotency-Key': idempotencyKey } },
  )

  return operation.data.data
}

export async function cancelSigningWorkflow(workflowUuid: string, reasonCode: string) {
  const response = await api.post<{ data: { workflow_uuid: string; status: string } }>(
    `/api/pdf/signing-workflows/${workflowUuid}/cancel`,
    { reason_code: reasonCode },
  )
  return response.data.data
}

export async function rejectSigningRequest(requestUuid: string, reasonCode: string) {
  const response = await api.post<{ data: { request_uuid: string; status: string } }>(
    `/api/pdf/signing-requests/${requestUuid}/reject`,
    { reason_code: reasonCode },
  )
  return response.data.data
}

export async function fetchSigningOperation(operationUuid: string) {
  const response = await api.get<{ data: SigningOperation }>(`/api/pdf/signing-operations/${operationUuid}`)
  return response.data.data
}

async function waitForSigningOperation(initial: SigningOperation): Promise<SigningOperation> {
  let current = initial
  for (let attempt = 0; attempt < 240; attempt += 1) {
    if (['completed', 'failed', 'irreversible_failed', 'manual_review', 'cancelled'].includes(current.state)) {
      return current
    }
    await new Promise((resolve) => window.setTimeout(resolve, 500))
    current = await fetchSigningOperation(current.operation_uuid)
  }
  throw new Error('PDF operation 等待超时，请稍后按原操作重试')
}
