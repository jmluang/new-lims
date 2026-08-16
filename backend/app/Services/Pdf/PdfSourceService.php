<?php

namespace App\Services\Pdf;

use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfSourceUpload;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class PdfSourceService
{
    public function __construct(
        private readonly PdfRendererClient $renderer,
        private readonly PdfImmutableFileStore $files,
        private readonly ReportNumberNormalizer $reportNumbers,
    ) {}

    public function inspect(UploadedFile $upload, User $actor): PdfSourceUpload
    {
        $sourceUuid = (string) Str::uuid();
        $targetPath = "workflow/sources/{$sourceUuid}.pdf";
        $stored = $this->files->copyFromPath($upload->getRealPath(), $targetPath);

        try {
            // Identical bytes are not a business conflict: the same report can be
            // re-uploaded after a failed attempt, and one file can legitimately
            // back more than one document. Business identity is enforced by the
            // report number at confirm time. Record the overlap so an operator can
            // still see it.
            $duplicateOf = PdfSourceUpload::query()->where('sha256', $stored['sha256'])->pluck('source_uuid')->all();
            $duplicateRevisions = PdfFile::query()->where('sha256_hash', $stored['sha256'])->pluck('revision_uuid')->all();

            if ($duplicateOf !== [] || $duplicateRevisions !== []) {
                Log::info('duplicate_content_detected', [
                    'sha256' => $stored['sha256'],
                    'existing_source_uuids' => $duplicateOf,
                    'existing_revision_uuids' => array_values(array_filter($duplicateRevisions)),
                    'actor_user_id' => $actor->id,
                    'new_source_uuid' => $sourceUuid,
                ]);
            }

            $inspection = $this->renderer->inspectSignaturePdf($stored['absolute_path']);

            if (($inspection['encrypted'] ?? true) !== false) {
                throw new UnprocessableEntityHttpException('PDF_SOURCE_ENCRYPTED');
            }

            if ((int) ($inspection['signatureCount'] ?? -1) !== 0
                || ($inspection['docMdpPermission'] ?? null) !== null) {
                throw new UnprocessableEntityHttpException('PDF_SOURCE_ALREADY_SIGNED');
            }

            if ((int) ($inspection['pageCount'] ?? 0) < 1) {
                throw new UnprocessableEntityHttpException('PDF_SOURCE_HAS_NO_PAGES');
            }

            $manifest = [
                'version' => 'pdf-inspection-v1',
                'source_sha256' => $stored['sha256'],
                'source_size' => $stored['size'],
                'inspection' => $inspection,
            ];

            return PdfSourceUpload::query()->create([
                'source_uuid' => $sourceUuid,
                'stored_path' => $targetPath,
                'sha256' => $stored['sha256'],
                'file_size' => $stored['size'],
                'page_count' => (int) $inspection['pageCount'],
                'inspection_manifest' => $manifest,
                'inspection_manifest_hash' => hash('sha256', CanonicalJson::encode($manifest)),
                'created_by_id' => $actor->id,
                'expires_at' => now()->addDay(),
                'status' => 'inspected',
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('pdf')->delete($targetPath);
            throw $exception;
        }
    }

    public function confirm(PdfSourceUpload $source, string $reportNumber, User $actor): PdfDocument
    {
        $normalized = $this->reportNumbers->normalize($reportNumber);
        $organizationScope = (string) config('pdf_service.organization_scope');

        try {
            return DB::transaction(function () use ($source, $reportNumber, $normalized, $organizationScope, $actor): PdfDocument {
                $lockedSource = PdfSourceUpload::query()->lockForUpdate()->findOrFail($source->id);

                if ((int) $lockedSource->created_by_id !== (int) $actor->id) {
                    throw new ConflictHttpException('PDF_SOURCE_NOT_OWNED');
                }

                if ($lockedSource->document_id !== null && in_array($lockedSource->status, ['bound', 'consumed'], true)) {
                    $existing = PdfDocument::query()->lockForUpdate()->findOrFail($lockedSource->document_id);
                    if ($existing->created_by_id !== $actor->id
                        || $existing->authoritative_report_number !== $reportNumber
                        || $existing->normalized_report_number !== $normalized
                        || $existing->organization_scope !== $organizationScope) {
                        throw new ConflictHttpException('PDF_SOURCE_CONFIRMATION_IDENTITY_CHANGED');
                    }

                    return $existing;
                }

                if ($lockedSource->status !== 'inspected' || $lockedSource->document_id !== null
                    || $lockedSource->expires_at->isPast()) {
                    throw new ConflictHttpException('PDF_SOURCE_NOT_CONFIRMABLE');
                }

                $document = PdfDocument::query()->create([
                    'document_uuid' => (string) Str::uuid(),
                    'document_public_id' => Str::random(48),
                    'organization_scope' => $organizationScope,
                    'authoritative_report_number' => $reportNumber,
                    'normalized_report_number' => $normalized,
                    'status' => 'draft',
                    'created_by_id' => $actor->id,
                ]);

                $lockedSource->update([
                    'document_id' => $document->id,
                    'status' => 'bound',
                ]);

                return $document;
            }, 3);
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                throw new ConflictHttpException('PDF_REPORT_NUMBER_ALREADY_REGISTERED', $exception);
            }

            throw $exception;
        }
    }
}
