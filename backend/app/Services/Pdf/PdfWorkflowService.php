<?php

namespace App\Services\Pdf;

use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfSigningAct;
use App\Models\PdfSigningField;
use App\Models\PdfSigningOperation;
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
    private const ROLES = [
        'inspector' => ['sequence' => 1, 'pdf_role' => 'certification_p2', 'request_type' => 'handwritten'],
        'reviewer' => ['sequence' => 2, 'pdf_role' => 'approval', 'request_type' => 'handwritten'],
        'issuer' => ['sequence' => 3, 'pdf_role' => 'approval', 'request_type' => 'handwritten'],
        'homepage_seal' => ['sequence' => 4, 'pdf_role' => 'approval', 'request_type' => 'homepage_seal'],
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
            if (! isset($assignments[$role]) || ! User::query()->whereKey($assignments[$role])->exists()) {
                throw new UnprocessableEntityHttpException("PDF_{$role}_ASSIGNMENT_REQUIRED");
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
                    'deferred' => $role === 'homepage_seal',
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
                    'status' => $role === 'homepage_seal' ? 'deferred' : 'planned',
                ]);
                $signingRequest = null;

                if ($role !== 'homepage_seal') {
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
                }

                $field = PdfSigningField::query()->create([
                    'field_uuid' => (string) Str::uuid(),
                    'workflow_id' => $workflow->id,
                    'signing_act_id' => $act->id,
                    'request_id' => $signingRequest?->id,
                    'field_name' => $fieldPlan['fieldName'],
                    'field_type' => $contract['request_type'],
                    'activation_mode' => $role === 'homepage_seal' ? 'deferred' : 'current',
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

    public function activateHomepageSeal(
        PdfSigningWorkflow $completedWorkflow,
        int $assignedUserId,
        PdfSigningPolicyVersion $policy,
        User $actor,
        string $idempotencyKey,
        array $auditContext,
    ): PdfSigningWorkflow {
        if (! User::query()->whereKey($assignedUserId)->where('status', 'active')->exists()) {
            throw new UnprocessableEntityHttpException('PDF_HOMEPAGE_SEAL_ASSIGNEE_INVALID');
        }
        if ($policy->immutable_at === null || $policy->pades_profile !== 'B-T') {
            throw new UnprocessableEntityHttpException('PDF_SIGNING_POLICY_NOT_IMMUTABLE_PADES_BT');
        }

        $scopeKey = "pdf-homepage-seal-bind:{$completedWorkflow->workflow_uuid}:actor:{$actor->id}";
        $idempotencyFingerprint = hash('sha256', CanonicalJson::encode([
            'action' => 'bind_deferred_field',
            'source_workflow_uuid' => $completedWorkflow->workflow_uuid,
            'assigned_user_id' => $assignedUserId,
            'signing_policy_version_uuid' => $policy->version_uuid,
            'actor_user_id' => $actor->id,
        ]));
        $existing = PdfSigningOperation::query()
            ->where('idempotency_scope_key', $scopeKey)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            return $this->existingHomepageSealBinding($existing, $idempotencyFingerprint);
        }

        $sourceWorkflowSnapshot = PdfSigningWorkflow::query()->findOrFail($completedWorkflow->id);
        $sourceActSnapshot = PdfSigningAct::query()
            ->where('document_id', $sourceWorkflowSnapshot->document_id)
            ->where('semantic_role', 'homepage_seal')
            ->where('status', 'deferred')
            ->firstOrFail();
        $sourceFieldSnapshot = PdfSigningField::query()
            ->where('workflow_id', $sourceWorkflowSnapshot->id)
            ->where('signing_act_id', $sourceActSnapshot->id)
            ->with('slots')
            ->firstOrFail();
        $publishedSnapshot = PdfFile::query()->findOrFail($sourceWorkflowSnapshot->current_revision_id);
        $publishedBytes = $this->files->readVerifiedImmutableFile(
            $publishedSnapshot->file_path,
            $publishedSnapshot->sha256_hash,
            (int) $publishedSnapshot->file_size,
            (int) config('pdf_service.workflow.generated_revision_max_bytes', 33_554_432),
        );
        $publishedInspection = $this->renderer->inspectSignatureBytes($publishedBytes);
        $this->assertPublishedHomepageFieldMatches(
            $publishedSnapshot,
            $sourceFieldSnapshot,
            $publishedInspection,
        );

        try {
            return DB::transaction(function () use (
                $completedWorkflow,
                $assignedUserId,
                $policy,
                $actor,
                $idempotencyKey,
                $auditContext,
                $scopeKey,
                $idempotencyFingerprint,
                $publishedSnapshot,
                $sourceFieldSnapshot,
                $publishedInspection,
            ): PdfSigningWorkflow {
                $raced = PdfSigningOperation::query()
                    ->where('idempotency_scope_key', $scopeKey)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($raced !== null) {
                    return $this->existingHomepageSealBinding($raced, $idempotencyFingerprint);
                }

                $document = PdfDocument::query()->lockForUpdate()->findOrFail($completedWorkflow->document_id);
                $sourceWorkflow = PdfSigningWorkflow::query()->lockForUpdate()->findOrFail($completedWorkflow->id);

                if ($sourceWorkflow->workflow_uuid !== $completedWorkflow->workflow_uuid
                    || $sourceWorkflow->status !== 'completed'
                    || $document->published_revision_id === null
                    || $document->published_revision_id !== $sourceWorkflow->current_revision_id
                    || $document->active_workflow_id !== null
                    || $document->active_operation_id !== null
                    || $document->integrity_state !== 'ok' || $document->evidence_hold_state !== 'none'
                    || (int) $document->evidence_hold_mask !== 0) {
                    throw new ConflictHttpException('PDF_HOMEPAGE_SEAL_WORKFLOW_NOT_ACTIVATABLE');
                }

                $published = PdfFile::query()->lockForUpdate()->findOrFail($document->published_revision_id);
                $act = PdfSigningAct::query()
                    ->where('document_id', $document->id)
                    ->where('semantic_role', 'homepage_seal')
                    ->where('status', 'deferred')
                    ->lockForUpdate()
                    ->firstOrFail();
                $sourceField = PdfSigningField::query()
                    ->where('workflow_id', $sourceWorkflow->id)
                    ->where('signing_act_id', $act->id)
                    ->with('slots')
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($published->integrity_state !== 'ready'
                    || $published->disposition !== 'published'
                    || $published->id !== $publishedSnapshot->id
                    || ! hash_equals($published->sha256_hash, $publishedSnapshot->sha256_hash)
                    || (int) $published->file_size !== (int) $publishedSnapshot->file_size
                    || $sourceField->id !== $sourceFieldSnapshot->id
                    || $sourceField->request_id !== null
                    || $sourceField->source_field_id !== null
                    || $sourceField->activation_mode !== 'deferred'
                    || $sourceField->binding_mode !== 'created_before_first_signature'
                    || $sourceField->lock_policy !== 'include_self_only'
                    || $sourceField->prepared_revision_id !== $sourceWorkflow->prepared_revision_id
                    || ! is_string($sourceField->prepared_object_ref)
                    || preg_match('/^[1-9][0-9]* [0-9]+ R$/', $sourceField->prepared_object_ref) !== 1
                    || $sourceField->status !== 'prepared'
                    || $sourceField->slots->isEmpty()
                    || $sourceField->slots->contains(
                        fn (PdfSigningSlot $slot): bool => $slot->status !== 'prepared'
                            || ! is_string($slot->prepared_widget_object_ref)
                            || preg_match('/^[1-9][0-9]* [0-9]+ R$/', $slot->prepared_widget_object_ref) !== 1
                            || ! is_array($slot->prepared_appearance_object_refs)
                            || $slot->prepared_appearance_object_refs === [],
                    )) {
                    throw new ConflictHttpException('PDF_HOMEPAGE_SEAL_FIELD_NOT_BINDABLE');
                }
                $this->assertPublishedHomepageFieldMatches($published, $sourceField, $publishedInspection);
                $generation = (int) PdfSigningWorkflow::query()
                    ->where('document_id', $document->id)
                    ->max('workflow_generation') + 1;
                $activationManifest = [
                    'version' => 'homepage-seal-activation-v1',
                    'bind_operation_uuid' => (string) Str::uuid(),
                    'source_workflow_uuid' => $sourceWorkflow->workflow_uuid,
                    'source_revision_uuid' => $published->revision_uuid,
                    'source_revision_sha256' => $published->sha256_hash,
                    'source_field_uuid' => $sourceField->field_uuid,
                    'field_name' => $sourceField->field_name,
                ];
                $workflow = PdfSigningWorkflow::query()->create([
                    'workflow_uuid' => (string) Str::uuid(),
                    'document_id' => $document->id,
                    'workflow_generation' => $generation,
                    'base_revision_id' => $published->id,
                    'planning_revision_id' => $published->id,
                    'prepared_revision_id' => $published->id,
                    'current_revision_id' => $published->id,
                    'publication_base_revision_id' => $published->id,
                    'expected_publication_version' => $document->publication_version,
                    'placement_plan' => $activationManifest,
                    'placement_plan_hash' => hash('sha256', CanonicalJson::encode($activationManifest)),
                    'field_plan_hash' => $sourceWorkflow->field_plan_hash,
                    'status' => 'ready',
                    'created_by_id' => $actor->id,
                ]);
                $request = PdfSigningRequest::query()->create([
                    'request_uuid' => (string) Str::uuid(),
                    'workflow_id' => $workflow->id,
                    'signing_act_id' => $act->id,
                    'sequence' => 4,
                    'request_type' => 'homepage_seal',
                    'assigned_user_id' => $assignedUserId,
                    'signing_policy_version_id' => $policy->id,
                    'status' => 'available',
                    'expected_source_revision_id' => $published->id,
                    'expected_source_sha256' => $published->sha256_hash,
                ]);
                $field = PdfSigningField::query()->create([
                    'field_uuid' => (string) Str::uuid(),
                    'workflow_id' => $workflow->id,
                    'signing_act_id' => $act->id,
                    'request_id' => $request->id,
                    'source_field_id' => $sourceField->id,
                    'field_name' => $sourceField->field_name,
                    'field_type' => 'homepage_seal',
                    'activation_mode' => 'current',
                    'binding_mode' => 'rebound_existing',
                    'lock_policy' => $sourceField->lock_policy,
                    'prepared_revision_id' => $published->id,
                    'prepared_object_ref' => $sourceField->prepared_object_ref,
                    'status' => 'prepared',
                ]);
                foreach ($sourceField->slots as $sourceSlot) {
                    PdfSigningSlot::query()->create([
                        'slot_uuid' => (string) Str::uuid(),
                        'field_id' => $field->id,
                        'page_index' => $sourceSlot->page_index,
                        'widget_index' => $sourceSlot->widget_index,
                        'placement_type' => 'homepage_seal',
                        'normalized_rect' => $sourceSlot->normalized_rect,
                        'geometry_hash' => $sourceSlot->geometry_hash,
                        'prepared_widget_object_ref' => $sourceSlot->prepared_widget_object_ref,
                        'prepared_appearance_object_refs' => $sourceSlot->prepared_appearance_object_refs,
                        'status' => 'prepared',
                    ]);
                }
                $act->update(['status' => 'planned']);
                $operationManifest = [
                    'version' => 'pdf-control-operation-v1',
                    'action' => 'bind_deferred_field',
                    'source_workflow_uuid' => $sourceWorkflow->workflow_uuid,
                    'workflow_uuid' => $workflow->workflow_uuid,
                    'source_revision_uuid' => $published->revision_uuid,
                    'source_revision_sha256' => $published->sha256_hash,
                    'field_name' => $sourceField->field_name,
                    'field_object_ref' => $sourceField->prepared_object_ref,
                    'widget_contract' => $sourceField->slots->sortBy('widget_index')->map(
                        fn (PdfSigningSlot $slot): array => [
                            'widget_index' => $slot->widget_index,
                            'page_index' => $slot->page_index,
                            'geometry_hash' => $slot->geometry_hash,
                            'widget_object_ref' => $slot->prepared_widget_object_ref,
                            'appearance_object_refs' => $slot->prepared_appearance_object_refs,
                        ],
                    )->values()->all(),
                    'assigned_user_id' => $assignedUserId,
                    'signing_policy_version_uuid' => $policy->version_uuid,
                ];
                $operationManifestHash = hash('sha256', CanonicalJson::encode($operationManifest));
                PdfSigningOperation::query()->create([
                    'operation_uuid' => $activationManifest['bind_operation_uuid'],
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_scope_key' => $scopeKey,
                    'scope_type' => 'workflow',
                    'actor_user_id' => $actor->id,
                    'document_id' => $document->id,
                    'workflow_id' => $workflow->id,
                    'request_id' => $request->id,
                    'action' => 'bind_deferred_field',
                    'input_fingerprint' => hash('sha256', CanonicalJson::encode([
                        'operation_input_manifest_hash' => $operationManifestHash,
                        'source_sha256' => $published->sha256_hash,
                    ])),
                    'operation_input_manifest_hash' => $operationManifestHash,
                    'expected_source_revision_id' => $published->id,
                    'expected_source_sha256' => $published->sha256_hash,
                    'state' => 'completed',
                    'stage' => 'done',
                    'audit_context' => [
                        ...$auditContext,
                        'idempotency_request_fingerprint' => $idempotencyFingerprint,
                        'operation_manifest' => $operationManifest,
                    ],
                    'audit_context_hash' => hash('sha256', CanonicalJson::encode([
                        ...$auditContext,
                        'idempotency_request_fingerprint' => $idempotencyFingerprint,
                        'operation_manifest' => $operationManifest,
                    ])),
                ]);
                $document->update([
                    'active_workflow_id' => $workflow->id,
                    'status' => 'signing',
                ]);

                return $workflow->load(['requests.act', 'fields.slots']);
            }, 3);
        } catch (ConflictHttpException $exception) {
            $raced = PdfSigningOperation::query()
                ->where('idempotency_scope_key', $scopeKey)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($raced !== null) {
                return $this->existingHomepageSealBinding($raced, $idempotencyFingerprint);
            }

            throw $exception;
        }
    }

    private function existingHomepageSealBinding(
        PdfSigningOperation $operation,
        string $idempotencyFingerprint,
    ): PdfSigningWorkflow {
        if (($operation->audit_context['idempotency_request_fingerprint'] ?? null) !== $idempotencyFingerprint
            || $operation->action !== 'bind_deferred_field'
            || $operation->state !== 'completed'
            || $operation->workflow_id === null) {
            throw new ConflictHttpException('PDF_IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_INPUT');
        }

        return PdfSigningWorkflow::query()
            ->findOrFail($operation->workflow_id)
            ->load(['requests.act', 'fields.slots']);
    }

    /** @param array<string, mixed> $inspection */
    private function assertPublishedHomepageFieldMatches(
        PdfFile $published,
        PdfSigningField $sourceField,
        array $inspection,
    ): void {
        if (($inspection['sha256'] ?? null) !== $published->sha256_hash
            || ($inspection['encrypted'] ?? true) !== false
            || (int) ($inspection['signatureCount'] ?? -1) !== 3
            || (int) ($inspection['docMdpPermission'] ?? -1) !== 2) {
            throw new ConflictHttpException('PDF_HOMEPAGE_SEAL_PUBLISHED_REVISION_INVALID');
        }
        $inspectedField = collect($inspection['fields'] ?? [])->firstWhere('fieldName', $sourceField->field_name);
        if (! is_array($inspectedField)
            || ($inspectedField['signed'] ?? true) !== false
            || ($inspectedField['selfOnlyLock'] ?? false) !== true
            || ($inspectedField['objectRef'] ?? null) !== $sourceField->prepared_object_ref
            || (int) ($inspectedField['widgetCount'] ?? -1) !== $sourceField->slots->count()) {
            throw new ConflictHttpException('PDF_HOMEPAGE_SEAL_PUBLISHED_FIELD_CHANGED');
        }
        $widgets = collect($inspectedField['widgets'] ?? [])->keyBy('widgetIndex');
        foreach ($sourceField->slots->sortBy('widget_index') as $slot) {
            $widget = $widgets->get($slot->widget_index);
            if (! is_array($widget)
                || (int) ($widget['pageIndex'] ?? -1) !== $slot->page_index
                || ($widget['normalizedRectangle'] ?? null) !== $slot->normalized_rect
                || ($widget['objectRef'] ?? null) !== $slot->prepared_widget_object_ref
                || ($widget['appearanceObjectRefs'] ?? null) !== $slot->prepared_appearance_object_refs) {
                throw new ConflictHttpException('PDF_HOMEPAGE_SEAL_PUBLISHED_WIDGET_CHANGED');
            }
        }
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
