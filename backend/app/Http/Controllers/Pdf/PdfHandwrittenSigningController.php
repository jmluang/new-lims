<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Controller;
use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfSignatureAppearanceArtifact;
use App\Models\PdfSigningOperation;
use App\Models\PdfSigningPolicyVersion;
use App\Models\PdfSigningRequest;
use App\Models\PdfSigningWorkflow;
use App\Models\PdfSourceUpload;
use App\Models\User;
use App\Services\Pdf\CancelPdfWorkflowService;
use App\Services\Pdf\PdfImmutableFileStore;
use App\Services\Pdf\PdfRequestContext;
use App\Services\Pdf\PdfRevisionIntegrityService;
use App\Services\Pdf\PdfSigningChallengeService;
use App\Services\Pdf\PdfSigningOperationService;
use App\Services\Pdf\PdfSourceService;
use App\Services\Pdf\PdfWorkflowControlOperationService;
use App\Services\Pdf\PdfWorkflowService;
use App\Services\Pdf\RejectPdfSigningRequestService;
use App\Services\Pdf\SignatureAppearanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PdfHandwrittenSigningController extends Controller
{
    public function planningOptions(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'pdf.workflow.create', 'pdf.workflow');

        return response()->json(['data' => [
            // Only people who can actually sign. Offering everyone let a report
            // be assigned to someone who cannot open the signing page at all,
            // and the workflow then waited on a signature they could not give.
            'assignees' => User::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->filter(fn (User $user): bool => $user->can('pdf.request.sign_assigned'))
                ->values()
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
            'policies' => PdfSigningPolicyVersion::query()
                ->whereNotNull('immutable_at')
                ->where('pades_profile', 'B-T')
                ->orderByDesc('immutable_at')
                ->get()
                ->map(fn (PdfSigningPolicyVersion $policy): array => [
                    'version_uuid' => $policy->version_uuid,
                    'signing_material_version' => $policy->signing_material_version,
                    'policy_hash' => $policy->policy_hash,
                ]),
        ]]);
    }

    public function inspect(Request $request, PdfSourceService $sources): JsonResponse
    {
        $this->authorizePermission($request, 'pdf.workflow.create', 'pdf.workflow');
        $validated = $request->validate([
            'pdf_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);
        $source = $sources->inspect($validated['pdf_file'], $request->user());

        return response()->json(['data' => $this->sourceData($source)], 201);
    }

    public function confirm(
        Request $request,
        PdfSourceUpload $source,
        PdfSourceService $sources,
    ): JsonResponse {
        $this->authorizePermission($request, 'pdf.workflow.create', 'pdf.workflow');
        $validated = $request->validate([
            'report_number' => ['required', 'string', 'max:512'],
        ]);
        $document = $sources->confirm($source, $validated['report_number'], $request->user());

        return response()->json(['data' => [
            'document_uuid' => $document->document_uuid,
            'document_public_id' => $document->document_public_id,
            'report_number' => $document->authoritative_report_number,
            'normalized_report_number' => $document->normalized_report_number,
            'status' => $document->status,
        ]], 201);
    }

    public function finalize(
        Request $request,
        PdfSourceUpload $source,
        PdfWorkflowControlOperationService $operations,
    ): JsonResponse {
        $this->authorizePermission($request, 'pdf.workflow.create', 'pdf.workflow');
        $operation = $operations->claimFinalize(
            $source,
            $this->idempotencyKey($request),
            PdfRequestContext::auditContext($request),
            $request->user(),
        );

        return response()->json(['data' => $this->operationData($operation)],
            $operation->state === 'completed' ? 200 : 202);
    }

    public function createWorkflow(Request $request, PdfWorkflowService $workflows): JsonResponse
    {
        $this->authorizePermission($request, 'pdf.workflow.create', 'pdf.workflow');
        $validated = $request->validate([
            'planning_revision_uuid' => ['required', 'uuid', 'exists:pdf_files,revision_uuid'],
            'signing_policy_version_uuid' => ['required', 'uuid', 'exists:pdf_signing_policy_versions,version_uuid'],
            'assignments' => ['required', 'array'],
            'assignments.inspector' => ['required', 'integer', 'exists:users,id'],
            'assignments.reviewer' => ['required', 'integer', 'exists:users,id'],
            'assignments.issuer' => ['required', 'integer', 'exists:users,id'],
            'placements' => ['required', 'array', 'size:3'],
            'placements.*.semantic_role' => ['required', 'in:inspector,reviewer,issuer'],
            'placements.*.page_index' => ['required', 'integer', 'min:0'],
            'placements.*.normalized_rect' => ['required', 'array'],
            'placements.*.normalized_rect.x' => ['required', 'string'],
            'placements.*.normalized_rect.y' => ['required', 'string'],
            'placements.*.normalized_rect.width' => ['required', 'string'],
            'placements.*.normalized_rect.height' => ['required', 'string'],
        ]);
        $revision = PdfFile::query()->where('revision_uuid', $validated['planning_revision_uuid'])->firstOrFail();
        $policy = PdfSigningPolicyVersion::query()
            ->where('version_uuid', $validated['signing_policy_version_uuid'])
            ->firstOrFail();
        $workflow = $workflows->create(
            $revision,
            $validated['assignments'],
            $validated['placements'],
            $policy,
            $request->user(),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->workflowData($workflow)], 201);
    }

    public function prepareWorkflow(
        Request $request,
        PdfSigningWorkflow $workflow,
        PdfWorkflowControlOperationService $operations,
    ): JsonResponse {
        $this->authorizePermission($request, 'pdf.workflow.create', 'pdf.workflow');
        $operation = $operations->claimPrepare(
            $workflow,
            $this->idempotencyKey($request),
            PdfRequestContext::auditContext($request),
            $request->user(),
        );

        return response()->json(['data' => $this->operationData($operation)],
            $operation->state === 'completed' ? 200 : 202);
    }

    public function cancelWorkflow(
        Request $request,
        PdfSigningWorkflow $workflow,
        CancelPdfWorkflowService $canceller,
    ): JsonResponse {
        $this->authorizePermission($request, 'pdf.workflow.cancel', 'pdf.workflow');
        $validated = $request->validate([
            'reason_code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{2,95}$/'],
        ]);
        $cancelled = $canceller->cancel($workflow, $validated['reason_code'], $request->user());

        return response()->json(['data' => $this->workflowData($cancelled)]);
    }

    public function workflow(Request $request, PdfSigningWorkflow $workflow): JsonResponse
    {
        $this->authorizePermission($request, 'pdf.workflow.read', 'pdf.workflow');

        return response()->json(['data' => $this->workflowData(
            $workflow->load(['requests.act', 'fields.slots']),
        )]);
    }

    public function signingRequest(Request $request, PdfSigningRequest $signingRequest): JsonResponse
    {
        $this->authorizePermission($request, 'pdf.request.read', 'pdf.request');
        abort_unless($signingRequest->assigned_user_id === $request->user()->id, 403);
        $signingRequest->load(['workflow.document', 'act', 'field.slots']);
        $revision = PdfFile::query()->find($signingRequest->expected_source_revision_id);

        return response()->json(['data' => [
            'request_uuid' => $signingRequest->request_uuid,
            'status' => $signingRequest->status,
            'sequence' => $signingRequest->sequence,
            'semantic_role' => $signingRequest->act->semantic_role,
            'pdf_signature_role' => $signingRequest->act->pdf_signature_role,
            'field' => $signingRequest->field ? [
                'field_uuid' => $signingRequest->field->field_uuid,
                'field_name' => $signingRequest->field->field_name,
                'slots' => $signingRequest->field->slots->map(fn ($slot): array => [
                    'page_index' => $slot->page_index,
                    'widget_index' => $slot->widget_index,
                    'normalized_rect' => $slot->normalized_rect,
                ])->all(),
            ] : null,
            'revision' => $revision ? $this->revisionData($revision) : null,
            'certificate_subject' => 'organization',
        ]]);
    }

    public function signingRequests(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'pdf.request.read', 'pdf.request');
        $requests = PdfSigningRequest::query()
            ->where('assigned_user_id', $request->user()->id)
            ->whereIn('status', ['available', 'signing'])
            ->with(['workflow.document', 'act', 'field.slots'])
            ->orderBy('created_at')
            ->get()
            ->map(function (PdfSigningRequest $item): array {
                $revision = $item->expected_source_revision_id
                    ? PdfFile::query()->find($item->expected_source_revision_id)
                    : null;

                return [
                    'request_uuid' => $item->request_uuid,
                    'status' => $item->status,
                    'sequence' => $item->sequence,
                    'semantic_role' => $item->act->semantic_role,
                    'report_number' => $item->workflow->document->authoritative_report_number,
                    'workflow_uuid' => $item->workflow->workflow_uuid,
                    'field_name' => $item->field?->field_name,
                    'revision_uuid' => $revision?->revision_uuid,
                    'created_at' => $item->created_at?->toIso8601String(),
                ];
            });

        return response()->json(['data' => $requests]);
    }

    public function createAppearance(
        Request $request,
        PdfSigningRequest $signingRequest,
        SignatureAppearanceService $appearances,
    ): JsonResponse {
        $this->authorizePermission($request, 'pdf.request.sign_assigned', 'pdf.request');
        $validated = $request->validate([
            'appearance' => ['required', 'file', 'mimes:png', 'max:5120'],
        ]);
        $appearance = $appearances->create($signingRequest, $validated['appearance'], $request->user());

        return response()->json(['data' => [
            'appearance_uuid' => $appearance->appearance_uuid,
            'canonical_image_sha256' => $appearance->canonical_image_sha256,
            'appearance_manifest_hash' => $appearance->appearance_manifest_hash,
            'width' => $appearance->width,
            'height' => $appearance->height,
            'crop_box' => $appearance->crop_box,
            'state' => $appearance->state,
        ]], 201);
    }

    public function rejectSigningRequest(
        Request $request,
        PdfSigningRequest $signingRequest,
        RejectPdfSigningRequestService $rejector,
    ): JsonResponse {
        $this->authorizePermission($request, 'pdf.request.reject', 'pdf.request');
        $validated = $request->validate([
            'reason_code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{2,95}$/'],
        ]);
        $rejected = $rejector->reject($signingRequest, $validated['reason_code'], $request->user());

        return response()->json(['data' => [
            'request_uuid' => $rejected->request_uuid,
            'status' => $rejected->status,
        ]]);
    }

    public function createChallenge(
        Request $request,
        PdfSigningRequest $signingRequest,
        PdfSigningChallengeService $challenges,
    ): JsonResponse {
        $this->authorizePermission($request, 'pdf.request.sign_assigned', 'pdf.request');
        $this->authorizePermission($request, 'pdf.organization_key.use', 'pdf.organization_key');
        $validated = $request->validate([
            'appearance_uuid' => ['required', 'uuid', 'exists:pdf_signature_appearance_artifacts,appearance_uuid'],
            'current_password' => ['required', 'string', 'max:1024'],
        ]);
        $appearance = PdfSignatureAppearanceArtifact::query()
            ->where('appearance_uuid', $validated['appearance_uuid'])
            ->firstOrFail();
        $challenge = $challenges->create(
            $signingRequest,
            $appearance,
            $validated['current_password'],
            PdfRequestContext::authContextId($request),
            $request->user(),
        );

        return response()->json(['data' => [
            'challenge_uuid' => $challenge->challenge_uuid,
            'source_sha256' => $challenge->source_sha256,
            'field_plan_hash' => $challenge->field_plan_hash,
            'appearance_manifest_hash' => $challenge->appearance_manifest_hash,
            'policy_hash' => $challenge->policy_hash,
            'expected_certificate_fingerprint' => $challenge->expected_certificate_fingerprint,
            'expires_at' => $challenge->expires_at->toIso8601String(),
        ]], 201);
    }

    public function claimSigningOperation(
        Request $request,
        PdfSigningRequest $signingRequest,
        PdfSigningOperationService $operations,
    ): JsonResponse {
        $this->authorizePermission($request, 'pdf.request.sign_assigned', 'pdf.request');
        $this->authorizePermission($request, 'pdf.organization_key.use', 'pdf.organization_key');
        $validated = $request->validate([
            'challenge_uuid' => ['required', 'uuid', 'exists:pdf_signing_challenges,challenge_uuid'],
        ]);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        abort_unless(
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $idempotencyKey) === 1,
            422,
            'PDF_IDEMPOTENCY_KEY_INVALID',
        );
        $operation = $operations->claim(
            $signingRequest,
            $validated['challenge_uuid'],
            $idempotencyKey,
            PdfRequestContext::authContextId($request),
            PdfRequestContext::auditContext($request),
            $request->user(),
        );

        return response()->json(['data' => $this->operationData($operation)],
            in_array($operation->state, ['completed', 'failed', 'irreversible_failed', 'manual_review', 'cancelled'], true)
                ? 200
                : 202);
    }

    public function signingOperation(Request $request, PdfSigningOperation $operation): JsonResponse
    {
        $this->authorizePermission(
            $request,
            $operation->action === 'fill_signature_field' ? 'pdf.request.read' : 'pdf.workflow.create',
            $operation->action === 'fill_signature_field' ? 'pdf.request' : 'pdf.workflow',
        );
        abort_unless($operation->actor_user_id === $request->user()->id, 403);

        return response()->json(['data' => $this->operationData($operation)]);
    }

    public function downloadRevision(
        Request $request,
        string $revisionUuid,
        PdfImmutableFileStore $files,
        PdfRevisionIntegrityService $integrity,
    ): BinaryFileResponse {
        $this->authorizePermission($request, 'pdf.revision.download', 'pdf.revision');
        $revision = PdfFile::query()->where('revision_uuid', $revisionUuid)->firstOrFail();
        $document = $revision->document_id ? PdfDocument::query()->findOrFail($revision->document_id) : null;
        abort_if($revision->integrity_state !== 'ready' || $document?->integrity_state === 'hold', 409);
        try {
            $path = $files->verifiedAbsolutePath(
                $revision->file_path,
                $revision->sha256_hash,
                (int) $revision->file_size,
            );
        } catch (\Throwable $exception) {
            $integrity->withdraw($revision, 'REVISION_DOWNLOAD_INTEGRITY_FAILURE');
            abort(409, 'PDF_REVISION_INTEGRITY_UNAVAILABLE');
        }

        return response()->download($path, $revision->file_name, [
            'Content-Type' => 'application/pdf',
            'X-Pdf-Revision-Uuid' => $revision->revision_uuid,
            'X-Pdf-Sha256' => $revision->sha256_hash,
        ]);
    }

    private function sourceData(PdfSourceUpload $source): array
    {
        return [
            'source_uuid' => $source->source_uuid,
            'sha256' => $source->sha256,
            'file_size' => $source->file_size,
            'page_count' => $source->page_count,
            'status' => $source->status,
            'inspection' => $source->inspection_manifest['inspection'] ?? null,
            'expires_at' => $source->expires_at?->toIso8601String(),
        ];
    }

    private function revisionData(PdfFile $revision): array
    {
        return [
            'revision_uuid' => $revision->revision_uuid,
            'revision_number' => $revision->revision_number,
            'revision_role' => $revision->revision_role,
            'sha256' => $revision->sha256_hash,
            'file_size' => $revision->file_size,
            'integrity_state' => $revision->integrity_state,
            'disposition' => $revision->disposition,
        ];
    }

    private function workflowData(PdfSigningWorkflow $workflow): array
    {
        $workflow->loadMissing(['requests.act', 'fields.slots']);

        return [
            'workflow_uuid' => $workflow->workflow_uuid,
            'status' => $workflow->status,
            'workflow_generation' => $workflow->workflow_generation,
            'placement_plan_hash' => $workflow->placement_plan_hash,
            'field_plan_hash' => $workflow->field_plan_hash,
            'current_revision_id' => $workflow->current_revision_id,
            'requests' => $workflow->requests->map(fn (PdfSigningRequest $item): array => [
                'request_uuid' => $item->request_uuid,
                'sequence' => $item->sequence,
                'semantic_role' => $item->act->semantic_role,
                'status' => $item->status,
                'assigned_user_id' => $item->assigned_user_id,
                'expected_source_sha256' => $item->expected_source_sha256,
            ])->all(),
            'fields' => $workflow->fields->map(fn ($field): array => [
                'field_uuid' => $field->field_uuid,
                'field_name' => $field->field_name,
                'activation_mode' => $field->activation_mode,
                'status' => $field->status,
                'slots' => $field->slots->map(fn ($slot): array => [
                    'page_index' => $slot->page_index,
                    'normalized_rect' => $slot->normalized_rect,
                ])->all(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function operationData(PdfSigningOperation $operation): array
    {
        return [
            'operation_uuid' => $operation->operation_uuid,
            'action' => $operation->action,
            'state' => $operation->state,
            'stage' => $operation->stage,
            'java_execution_state' => $operation->java_execution_state,
            'java_execution_deadline_at' => $operation->java_execution_deadline_at?->toIso8601String(),
            'next_java_poll_at' => $operation->next_java_poll_at?->toIso8601String(),
            'error_code' => $operation->error_code,
            'error_retryability' => $operation->error_retryability,
            'result_revision_uuid' => $operation->result_revision_uuid,
            'result_sha256' => $operation->result_sha256,
            'result_size' => $operation->result_size,
            'status_url' => url("/api/pdf/signing-operations/{$operation->operation_uuid}"),
        ];
    }

    private function idempotencyKey(Request $request): string
    {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        abort_unless(
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $idempotencyKey) === 1,
            422,
            'PDF_IDEMPOTENCY_KEY_INVALID',
        );

        return $idempotencyKey;
    }
}
