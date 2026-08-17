<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Controller;
use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfSigningOperation;
use App\Models\PdfSigningRequest;
use App\Models\PdfSigningWorkflow;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Pdf\PdfDocumentDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Report-level view of the handwritten signing flow.
 *
 * A report number is claimed at confirm time, so it can be held by a document
 * that never reached a workflow. Without this the only signal was a 409 on the
 * next upload, with no way to see what held the number or who still owed a
 * signature.
 */
class PdfDocumentController extends Controller
{
    private const RESOURCE = 'pdf.document';

    public function index(Request $request, PdfDocumentDraftService $drafts): JsonResponse
    {
        $this->authorizePermission($request, 'pdf.document.read', self::RESOURCE);

        $query = PdfDocument::query()
            ->where('organization_scope', (string) config('pdf_service.organization_scope'));

        if (filled($search = $request->string('search')->trim()->value())) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('authoritative_report_number', 'like', "%{$search}%")
                    ->orWhere('normalized_report_number', 'like', "%{$search}%")
                    ->orWhere('document_uuid', $search);
            });
        }

        if (filled($status = $request->string('status')->trim()->value())) {
            $query->where('status', $status);
        }

        $documents = $query->orderByDesc('id')->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $documents->getCollection()
                ->map(fn (PdfDocument $document): array => $this->serialize($document, $request->user()))
                ->values(),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    public function update(
        Request $request,
        PdfDocument $document,
        PdfDocumentDraftService $drafts,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $this->authorizePermission($request, 'pdf.document.update', self::RESOURCE);
        $validated = $request->validate([
            'report_number' => ['required', 'string', 'max:128'],
        ]);
        $before = $document->authoritative_report_number;
        $renamed = $drafts->rename($document, $validated['report_number'], $request->user());
        $auditLogger->record(
            actor: $request->user(),
            action: 'pdf.document.renamed',
            module: self::RESOURCE,
            subject: $renamed,
            before: ['report_number' => $before],
            after: ['report_number' => $renamed->authoritative_report_number],
        );

        return response()->json(['data' => $this->serialize($renamed, $request->user())]);
    }

    public function destroy(
        Request $request,
        PdfDocument $document,
        PdfDocumentDraftService $drafts,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $this->authorizePermission($request, 'pdf.document.delete', self::RESOURCE);
        $summary = $drafts->delete($document, $request->user());
        // The document row is gone, so the audit entry carries its identity in
        // the values rather than as a subject reference.
        $auditLogger->record(
            actor: $request->user(),
            action: 'pdf.document.deleted',
            module: self::RESOURCE,
            before: $summary,
        );

        return response()->json(['data' => $summary]);
    }

    /** @return array<string, mixed> */
    private function serialize(PdfDocument $document, ?User $actor): array
    {
        $workflow = PdfSigningWorkflow::query()
            ->where('document_id', $document->id)
            ->orderByDesc('workflow_generation')
            ->first();

        $signers = [];

        if ($workflow !== null) {
            $names = User::query()
                ->whereIn('id', PdfSigningRequest::query()->where('workflow_id', $workflow->id)->pluck('assigned_user_id'))
                ->pluck('name', 'id');
            $signers = PdfSigningRequest::query()
                ->where('workflow_id', $workflow->id)
                ->with('act')
                ->orderBy('sequence')
                ->get()
                ->map(fn (PdfSigningRequest $item): array => [
                    'sequence' => $item->sequence,
                    'semantic_role' => $item->act?->semantic_role,
                    'assigned_user_id' => $item->assigned_user_id,
                    'assigned_user_name' => $names[$item->assigned_user_id] ?? null,
                    'status' => $item->status,
                    'act_status' => $item->act?->status,
                ])->all();
        }

        $revisions = PdfFile::query()
            ->where('document_id', $document->id)
            ->orderBy('revision_number')
            ->get(['revision_uuid', 'revision_number', 'revision_role', 'integrity_state']);

        return [
            'document_uuid' => $document->document_uuid,
            'report_number' => $document->authoritative_report_number,
            'status' => $document->status,
            'stage' => $this->stage($document, $workflow, $signers),
            'integrity_state' => $document->integrity_state,
            'evidence_hold_state' => $document->evidence_hold_state,
            'has_running_work' => $document->active_operation_id !== null
                || PdfSigningOperation::query()
                    ->where('document_id', $document->id)
                    ->whereNotIn('state', ['completed', 'failed', 'irreversible_failed', 'cancelled'])
                    ->exists(),
            'workflow_uuid' => $workflow?->workflow_uuid,
            'workflow_status' => $workflow?->status,
            'signers' => $signers,
            'revisions' => $revisions->map(fn (PdfFile $file): array => [
                'revision_uuid' => $file->revision_uuid,
                'revision_number' => $file->revision_number,
                'revision_role' => $file->revision_role,
                'integrity_state' => $file->integrity_state,
            ])->all(),
            'created_by_id' => $document->created_by_id,
            'is_owner' => $actor !== null && (int) $document->created_by_id === (int) $actor->id,
            'created_at' => $document->created_at,
        ];
    }

    /**
     * A short, human-readable answer to "where is this report right now".
     *
     * @param  list<array<string, mixed>>  $signers
     */
    private function stage(PdfDocument $document, ?PdfSigningWorkflow $workflow, array $signers): string
    {
        if ($document->published_revision_id !== null) {
            return 'published';
        }

        if ($workflow === null) {
            return PdfFile::query()->where('document_id', $document->id)->exists()
                ? 'finalized_awaiting_workflow'
                : 'confirmed_awaiting_finalize';
        }

        if (in_array($workflow->status, ['cancelled', 'failed'], true)) {
            return $workflow->status;
        }

        $pending = collect($signers)->first(
            static fn (array $signer): bool => ! in_array($signer['status'], ['signed', 'cancelled', 'rejected'], true),
        );

        if ($pending !== null) {
            return 'awaiting_signature';
        }

        return $signers === [] ? 'preparing_fields' : 'all_signed';
    }
}
