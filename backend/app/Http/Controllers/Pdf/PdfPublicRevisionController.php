<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Controller;
use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Services\Pdf\PdfRendererClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PdfPublicRevisionController extends Controller
{
    public function revision(string $revisionUuid): JsonResponse
    {
        $revision = $this->publishedRevision($revisionUuid);
        $document = PdfDocument::query()->findOrFail($revision->document_id);

        return response()->json(['data' => $this->registrationData($document, $revision)]);
    }

    public function verify(
        Request $request,
        string $revisionUuid,
        PdfRendererClient $renderer,
    ): JsonResponse {
        $validated = $request->validate([
            'pdf_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);
        $revision = $this->publishedRevision($revisionUuid);
        $document = PdfDocument::query()->findOrFail($revision->document_id);
        $upload = $validated['pdf_file'];
        $actualSha256 = hash_file('sha256', $upload->getRealPath());
        $actualSize = $upload->getSize();

        if ($document->integrity_state === 'hold') {
            return response()->json(['data' => [
                ...$this->registrationData($document, $revision),
                'verification_state' => 'indeterminate',
                'reason_code' => 'INTEGRITY_REVIEW_PENDING',
                'actual_sha256' => $actualSha256,
                'actual_size' => $actualSize,
            ]]);
        }

        $bytesMatch = is_string($actualSha256)
            && hash_equals($revision->sha256_hash, $actualSha256)
            && (int) $revision->file_size === (int) $actualSize;
        $cryptographic = $renderer->verifySignaturePdf($upload->getRealPath());
        $cryptoValid = ($cryptographic['documentCurrentState'] ?? null) === 'valid'
            && count($cryptographic['signatures'] ?? []) >= 3;

        return response()->json(['data' => [
            ...$this->registrationData($document, $revision),
            'verification_state' => $bytesMatch && $cryptoValid ? 'valid' : 'invalid',
            'registered_bytes_match' => $bytesMatch,
            'actual_sha256' => $actualSha256,
            'actual_size' => $actualSize,
            'cryptographic_verification' => $cryptographic,
        ]]);
    }

    public function document(string $publicId): JsonResponse
    {
        $document = PdfDocument::query()
            ->where('document_public_id', $publicId)
            ->with('publishedRevision')
            ->firstOrFail();
        $events = DB::table('pdf_document_publication_events')
            ->where('document_id', $document->id)
            ->whereNotNull('revision_id')
            ->orderBy('occurred_at')
            ->get();
        $revisions = PdfFile::query()
            ->whereIn('id', $events->pluck('revision_id')->unique())
            ->get()
            ->keyBy('id');

        return response()->json(['data' => [
            'document_public_id' => $document->document_public_id,
            'registration_state' => $document->integrity_state === 'hold'
                ? 'integrity_review_pending'
                : 'published',
            'publication_version' => (int) $document->publication_version,
            'current_revision' => $document->publishedRevision
                ? $this->revisionData($document->publishedRevision)
                : null,
            'history' => $events->map(function ($event) use ($revisions): array {
                $revision = $revisions->get($event->revision_id);

                return [
                    'event_type' => $event->event_type,
                    'occurred_at' => $event->occurred_at,
                    'revision' => $revision ? $this->revisionData($revision) : null,
                ];
            })->all(),
        ]]);
    }

    private function publishedRevision(string $revisionUuid): PdfFile
    {
        return PdfFile::query()
            ->where('revision_uuid', $revisionUuid)
            ->whereHas('document')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('pdf_document_publication_events')
                    ->whereColumn('pdf_document_publication_events.revision_id', 'pdf_files.id');
            })
            ->firstOrFail();
    }

    private function registrationData(PdfDocument $document, PdfFile $revision): array
    {
        return [
            'document_public_id' => $document->document_public_id,
            'revision' => $this->revisionData($revision),
            'registration_state' => $document->integrity_state === 'hold'
                ? 'integrity_review_pending'
                : 'registered',
            'download_available' => $document->integrity_state !== 'hold',
        ];
    }

    private function revisionData(PdfFile $revision): array
    {
        return [
            'revision_uuid' => $revision->revision_uuid,
            'revision_number' => (int) $revision->revision_number,
            'sha256' => $revision->sha256_hash,
            'file_size' => (int) $revision->file_size,
            'published_at' => $revision->first_published_at?->toIso8601String(),
        ];
    }
}
