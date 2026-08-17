<?php

namespace App\Services\Pdf;

use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfSigningAct;
use App\Models\PdfSigningField;
use App\Models\PdfSigningPolicyVersion;
use App\Models\PdfSigningRequest;
use App\Models\PdfSigningSlot;
use App\Models\PdfSigningWorkflow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class PdfWorkflowService
{
    // Sealing a report is the legacy signing desk's job, so this workflow plans
    // handwritten signatures only. The deferred homepage-seal field and the
    // later-seal workflow that filled it are gone with it.
    private const ROLES = [
        'inspector' => ['sequence' => 1, 'pdf_role' => 'certification_p2', 'request_type' => 'handwritten'],
        'reviewer' => ['sequence' => 2, 'pdf_role' => 'approval', 'request_type' => 'handwritten'],
        'issuer' => ['sequence' => 3, 'pdf_role' => 'approval', 'request_type' => 'handwritten'],
    ];

    public function __construct(
        private readonly PdfRendererClient $renderer,
        private readonly PdfImmutableFileStore $files,
    ) {}

    /**
     * @param  array<string, int>  $assignments
     * @param  list<array<string, mixed>>  $placements
     */
    public function create(
        PdfFile $planningRevision,
        array $assignments,
        array $placements,
        PdfSigningPolicyVersion $policy,
        User $actor,
        string $idempotencyKey,
    ): PdfSigningWorkflow {
        $placementByRole = [];

        foreach ($placements as $placement) {
            $role = (string) ($placement['semantic_role'] ?? '');

            if (! isset(self::ROLES[$role]) || isset($placementByRole[$role])) {
                throw new UnprocessableEntityHttpException('PDF_PLACEMENT_ROLE_INVALID');
            }

            $placementByRole[$role] = $this->normalizePlacement($placement);
        }

        if (count($placementByRole) !== count(self::ROLES)
            || array_diff_key(self::ROLES, $placementByRole) !== []) {
            throw new UnprocessableEntityHttpException('PDF_ALL_V1_PLACEMENTS_REQUIRED');
        }

        foreach (['inspector', 'reviewer', 'issuer'] as $role) {
            if (! isset($assignments[$role])) {
                throw new UnprocessableEntityHttpException("PDF_{$role}_ASSIGNMENT_REQUIRED");
            }

            $assignee = User::query()->find($assignments[$role]);

            if ($assignee === null || $assignee->status !== 'active') {
                throw new UnprocessableEntityHttpException("PDF_{$role}_ASSIGNMENT_REQUIRED");
            }

            // Freezing the fields commits the document to these three people. One
            // who cannot sign would leave the workflow waiting on a signature
            // they are not able to give, and only a cancel could undo it.
            if (! $assignee->can('pdf.request.sign_assigned')) {
                throw new UnprocessableEntityHttpException('PDF_ASSIGNEE_CANNOT_SIGN');
            }
        }

        if ($policy->immutable_at === null || $policy->pades_profile !== 'B-T') {
            throw new UnprocessableEntityHttpException('PDF_SIGNING_POLICY_NOT_IMMUTABLE_PADES_BT');
        }

        ksort($assignments);
        $creationFingerprint = hash('sha256', CanonicalJson::encode([
            'planning_revision_uuid' => $planningRevision->revision_uuid,
            'signing_policy_version_uuid' => $policy->version_uuid,
            'assignments' => $assignments,
            'placements' => $placementByRole,
            'actor_user_id' => $actor->id,
        ]));

        return DB::transaction(function () use (
            $planningRevision, $assignments, $placementByRole, $policy, $actor, $idempotencyKey, $creationFingerprint,
        ): PdfSigningWorkflow {
            $document = PdfDocument::query()->lockForUpdate()->findOrFail($planningRevision->document_id);
            $revision = PdfFile::query()->lockForUpdate()->findOrFail($planningRevision->id);

            $existing = PdfSigningWorkflow::query()
                ->where('document_id', $document->id)
                ->orderBy('id')
                ->get()
                ->first(fn (PdfSigningWorkflow $candidate): bool => ($candidate->placement_plan['creation_idempotency_key'] ?? null) === $idempotencyKey);
            if ($existing !== null) {
                if (($existing->placement_plan['creation_fingerprint'] ?? null) !== $creationFingerprint) {
                    throw new ConflictHttpException('PDF_IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_INPUT');
                }

                return $existing->load(['requests.act', 'fields.slots']);
            }

            if ($revision->revision_role !== 'finalized_unsigned' || $revision->integrity_state !== 'ready'
                || $document->integrity_state !== 'ok' || $document->evidence_hold_state !== 'none'
                || (int) $document->evidence_hold_mask !== 0) {
                throw new ConflictHttpException('PDF_PLANNING_REVISION_NOT_ELIGIBLE');
            }

            if ($document->active_workflow_id !== null || $document->active_operation_id !== null) {
                throw new ConflictHttpException('PDF_DOCUMENT_ALREADY_HAS_ACTIVE_WORK');
            }

            $generation = (int) PdfSigningWorkflow::query()
                ->where('document_id', $document->id)
                ->max('workflow_generation') + 1;
            $fieldPlans = [];

            foreach (self::ROLES as $role => $contract) {
                $fieldPlans[] = [
                    'semantic_role' => $role,
                    'fieldName' => "lims_{$role}_g{$generation}",
                    'pageIndex' => $placementByRole[$role]['page_index'],
                    'rectangle' => $placementByRole[$role]['normalized_rect'],
                    'deferred' => false,
                    'pdf_signature_role' => $contract['pdf_role'],
                    'lock_policy' => 'include_self_only',
                ];
            }

            $placementPlan = [
                'version' => 'normalized-placement-v1',
                'creation_idempotency_key' => $idempotencyKey,
                'creation_fingerprint' => $creationFingerprint,
                'planning_revision_uuid' => $revision->revision_uuid,
                'planning_revision_sha256' => $revision->sha256_hash,
                'fields' => $fieldPlans,
            ];
            $fieldPlanHash = hash('sha256', CanonicalJson::encode($fieldPlans));
            $workflow = PdfSigningWorkflow::query()->create([
                'workflow_uuid' => (string) Str::uuid(),
                'document_id' => $document->id,
                'workflow_generation' => $generation,
                'base_revision_id' => $revision->id,
                'planning_revision_id' => $revision->id,
                'current_revision_id' => $revision->id,
                'publication_base_revision_id' => $document->published_revision_id,
                'expected_publication_version' => $document->publication_version,
                'placement_plan' => $placementPlan,
                'placement_plan_hash' => hash('sha256', CanonicalJson::encode($placementPlan)),
                'field_plan_hash' => $fieldPlanHash,
                'status' => 'draft',
                'created_by_id' => $actor->id,
            ]);

            $predecessorId = null;

            foreach (self::ROLES as $role => $contract) {
                $fieldPlan = collect($fieldPlans)->firstWhere('semantic_role', $role);
                $act = PdfSigningAct::query()->create([
                    'logical_act_uuid' => (string) Str::uuid(),
                    'document_id' => $document->id,
                    'plan_generation' => $generation,
                    'semantic_role' => $role,
                    'pdf_signature_role' => $contract['pdf_role'],
                    'sequence' => $contract['sequence'],
                    'field_name' => $fieldPlan['fieldName'],
                    'status' => 'planned',
                ]);
                $signingRequest = PdfSigningRequest::query()->create([
                    'request_uuid' => (string) Str::uuid(),
                    'workflow_id' => $workflow->id,
                    'signing_act_id' => $act->id,
                    'sequence' => $contract['sequence'],
                    'predecessor_request_id' => $predecessorId,
                    'request_type' => $contract['request_type'],
                    'assigned_user_id' => (int) $assignments[$role],
                    'signing_policy_version_id' => $policy->id,
                    'status' => 'pending',
                ]);
                $predecessorId = $signingRequest->id;

                $field = PdfSigningField::query()->create([
                    'field_uuid' => (string) Str::uuid(),
                    'workflow_id' => $workflow->id,
                    'signing_act_id' => $act->id,
                    'request_id' => $signingRequest?->id,
                    'field_name' => $fieldPlan['fieldName'],
                    'field_type' => $contract['request_type'],
                    'activation_mode' => 'current',
                    'binding_mode' => 'created_before_first_signature',
                    'lock_policy' => 'include_self_only',
                    'status' => 'planned',
                ]);
                PdfSigningSlot::query()->create([
                    'slot_uuid' => (string) Str::uuid(),
                    'field_id' => $field->id,
                    'page_index' => $fieldPlan['pageIndex'],
                    'widget_index' => 0,
                    'placement_type' => $role,
                    'normalized_rect' => $fieldPlan['rectangle'],
                    'geometry_hash' => hash('sha256', CanonicalJson::encode([
                        'page_index' => $fieldPlan['pageIndex'],
                        'widget_index' => 0,
                        'normalized_rect' => $fieldPlan['rectangle'],
                    ])),
                    'status' => 'planned',
                ]);
            }

            $document->update(['active_workflow_id' => $workflow->id, 'status' => 'signing']);

            return $workflow->load(['requests.act', 'fields.slots']);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $placement
     * @return array{page_index: int, normalized_rect: array{x: string, y: string, width: string, height: string}}
     */
    private function normalizePlacement(array $placement): array
    {
        $pageIndex = filter_var($placement['page_index'] ?? null, FILTER_VALIDATE_INT);
        $rect = $placement['normalized_rect'] ?? null;

        if ($pageIndex === false || $pageIndex < 0 || ! is_array($rect)) {
            throw new UnprocessableEntityHttpException('PDF_PLACEMENT_GEOMETRY_INVALID');
        }

        $normalized = [];

        foreach (['x', 'y', 'width', 'height'] as $key) {
            $value = $rect[$key] ?? null;

            if (! is_string($value) || ! preg_match('/^(?:0(?:\.\d{1,6})?|1(?:\.0{1,6})?)$/', $value)) {
                throw new UnprocessableEntityHttpException('PDF_PLACEMENT_COORDINATE_INVALID');
            }

            $normalized[$key] = number_format((float) $value, 6, '.', '');
        }

        if ((float) $normalized['width'] <= 0 || (float) $normalized['height'] <= 0
            || (float) $normalized['x'] + (float) $normalized['width'] > 1.000001
            || (float) $normalized['y'] + (float) $normalized['height'] > 1.000001) {
            throw new UnprocessableEntityHttpException('PDF_PLACEMENT_OUTSIDE_PAGE');
        }

        return ['page_index' => $pageIndex, 'normalized_rect' => $normalized];
    }
}
