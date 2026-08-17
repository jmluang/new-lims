<?php

namespace App\Services\Pdf;

use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfSigningOperation;
use App\Models\PdfSigningRequest;
use App\Models\PdfSigningWorkflow;
use App\Models\PdfSourceUpload;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Rename and delete for documents that have not been signed yet.
 *
 * A report number is claimed at confirm time, long before any workflow exists,
 * so an abandoned upload can hold a number with nothing behind it. Without a way
 * to see or clear those, the only recovery was editing the database by hand.
 *
 * Everything here is gated on the document being provably untouched by signing.
 * Once a signature, publication or evidence hold exists the document is history,
 * and history is not editable.
 */
final class PdfDocumentDraftService
{
    /**
     * Terminal operation states. Anything else means work may still be running
     * against the document, so it must not be renamed or deleted underneath it.
     */
    private const SETTLED_OPERATION_STATES = ['completed', 'failed', 'irreversible_failed', 'cancelled'];

    /** Revision roles that carry no signature; everything else is evidence of one. */
    private const UNSIGNED_REVISION_ROLES = ['finalized_unsigned', 'prepared'];

    public function __construct(private readonly ReportNumberNormalizer $reportNumbers) {}

    public function rename(PdfDocument $document, string $reportNumber, User $actor): PdfDocument
    {
        $normalized = $this->reportNumbers->normalize($reportNumber);

        try {
            return DB::transaction(function () use ($document, $reportNumber, $normalized, $actor): PdfDocument {
                $locked = PdfDocument::query()->lockForUpdate()->findOrFail($document->id);
                $this->assertEditable($locked, $actor);
                $locked->update([
                    'authoritative_report_number' => $reportNumber,
                    'normalized_report_number' => $normalized,
                ]);

                return $locked->refresh();
            }, 3);
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                throw new ConflictHttpException('PDF_REPORT_NUMBER_ALREADY_REGISTERED', $exception);
            }

            throw $exception;
        }
    }

    /**
     * @return array{document_uuid: string, report_number: string, deleted_files: int}
     */
    public function delete(PdfDocument $document, User $actor): array
    {
        [$summary, $storedPaths] = DB::transaction(function () use ($document, $actor): array {
            $locked = PdfDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->assertEditable($locked, $actor);

            $paths = PdfSourceUpload::query()->where('document_id', $locked->id)->pluck('stored_path')
                ->merge(PdfFile::query()->where('document_id', $locked->id)->pluck('file_path'))
                ->filter()
                ->values()
                ->all();

            $operationIds = PdfSigningOperation::query()->where('document_id', $locked->id)->pluck('id');
            $workflowIds = PdfSigningWorkflow::query()->where('document_id', $locked->id)->pluck('id');

            // Children first, then the rows they point at, then the document.
            DB::table('pdf_signing_operation_events')->whereIn('operation_id', $operationIds)->delete();
            DB::table('pdf_operation_outbox')->whereIn('operation_id', $operationIds)->delete();
            PdfSigningRequest::query()->whereIn('workflow_id', $workflowIds)->delete();
            DB::table('pdf_signing_slots')->whereIn(
                'field_id',
                DB::table('pdf_signing_fields')->whereIn('workflow_id', $workflowIds)->pluck('id'),
            )->delete();
            DB::table('pdf_signing_fields')->whereIn('workflow_id', $workflowIds)->delete();
            DB::table('pdf_signing_acts')->where('document_id', $locked->id)->delete();
            $locked->update(['active_workflow_id' => null, 'active_operation_id' => null, 'published_revision_id' => null]);
            PdfSigningOperation::query()->whereIn('id', $operationIds)->delete();
            PdfSigningWorkflow::query()->whereIn('id', $workflowIds)->delete();
            // Source uploads are soft-deleted by default, which would leave the
            // row — and its document_id — behind for the delete below to trip on.
            PdfSourceUpload::query()->where('document_id', $locked->id)->forceDelete();
            PdfFile::query()->where('document_id', $locked->id)->delete();

            $summary = [
                'document_uuid' => $locked->document_uuid,
                'report_number' => $locked->authoritative_report_number,
                'deleted_files' => count($paths),
            ];
            $locked->delete();

            return [$summary, $paths];
        }, 3);

        // Only after the rows are gone, so a failure here leaves orphan bytes the
        // existing sweeper can quarantine rather than dangling database rows.
        foreach ($storedPaths as $path) {
            Storage::disk('pdf')->delete($path);
        }

        return $summary;
    }

    /**
     * A document is only a draft while nothing signed, published or held exists
     * for it. Each condition is checked separately so the error names the reason.
     */
    private function assertEditable(PdfDocument $document, User $actor): void
    {
        if ((int) $document->created_by_id !== (int) $actor->id) {
            throw new ConflictHttpException('PDF_DOCUMENT_NOT_OWNED');
        }

        // A cancelled document is as uncommitted as a fresh one: its workflow was
        // abandoned before anything was signed, and the signed-revision and
        // active-work checks below still stand guard.
        if (! in_array($document->status, ['draft', 'cancelled'], true)
            || $document->published_revision_id !== null
            || (int) $document->publication_version !== 0) {
            throw new ConflictHttpException('PDF_DOCUMENT_NOT_A_DRAFT');
        }

        if ($document->integrity_state !== 'ok' || (int) $document->integrity_hold_mask !== 0
            || $document->evidence_hold_state !== 'none' || (int) $document->evidence_hold_mask !== 0
            || $document->legal_hold_until !== null) {
            throw new ConflictHttpException('PDF_DOCUMENT_UNDER_HOLD');
        }

        $unsettled = PdfSigningOperation::query()
            ->where('document_id', $document->id)
            ->whereNotIn('state', self::SETTLED_OPERATION_STATES)
            ->exists();

        if ($document->active_operation_id !== null || $unsettled) {
            throw new ConflictHttpException('PDF_DOCUMENT_HAS_RUNNING_WORK');
        }

        // `prepared` holds the empty signature fields created before the first
        // signature, so it is not evidence of one. Every other role is.
        $signed = PdfFile::query()
            ->where('document_id', $document->id)
            ->whereNotIn('revision_role', self::UNSIGNED_REVISION_ROLES)
            ->exists();

        if ($signed) {
            throw new ConflictHttpException('PDF_DOCUMENT_ALREADY_SIGNED');
        }

        $signedAct = DB::table('pdf_signing_acts')
            ->where('document_id', $document->id)
            ->whereIn('status', ['completed', 'signed'])
            ->exists();

        if ($signedAct) {
            throw new ConflictHttpException('PDF_DOCUMENT_ALREADY_SIGNED');
        }
    }
}
