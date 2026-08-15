<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Controller;
use App\Models\PdfFile;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The signed-PDF ledger: what was signed, by whom, and with which digests.
 */
class PdfFileController extends Controller
{
    private const RESOURCE = 'pdf_files';

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'pdf_files.read', self::RESOURCE);

        $files = $this->filteredQuery($request)
            ->orderByDesc('signed_at')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $files->getCollection()->map(fn (PdfFile $file): array => $this->serialize($file))->values(),
            'meta' => [
                'current_page' => $files->currentPage(),
                'per_page' => $files->perPage(),
                'total' => $files->total(),
            ],
        ]);
    }

    public function show(Request $request, PdfFile $pdfFile): JsonResponse
    {
        $this->authorizePermission($request, 'pdf_files.read', self::RESOURCE, $pdfFile);

        return response()->json(['data' => $this->serialize($pdfFile, includeMetadata: true)]);
    }

    public function download(Request $request, PdfFile $pdfFile, AuditLogger $auditLogger): StreamedResponse
    {
        $this->authorizePermission($request, 'pdf_files.download', self::RESOURCE, $pdfFile);

        $disk = Storage::disk('pdf');

        abort_unless(filled($pdfFile->file_path) && $disk->exists($pdfFile->file_path), 404);

        $auditLogger->record(
            actor: $request->user(),
            action: 'pdf_files.download',
            module: self::RESOURCE,
            subject: $pdfFile,
            after: ['file_id' => $pdfFile->file_id, 'file_name' => $pdfFile->file_name],
        );

        // download(), not response(): the latter defaults to an inline
        // disposition, which opens the report in a browser tab instead of
        // saving it.
        return $disk->download($pdfFile->file_path, $pdfFile->file_name, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Serves a signed report through a short-lived signed link.
     *
     * The signing desk hands the finished file to the browser as a `blob:` URL,
     * which browsers that delegate downloads to their own download manager
     * (360 浏览器 among them) cannot act on — the automatic download silently
     * does nothing. A real URL works everywhere, but a plain `<a href>` cannot
     * carry the SPA's bearer token, so the link carries its own authorisation:
     * the signature is scoped to one file and expires minutes after signing.
     *
     * The reference system solved this with an endpoint that had no
     * authorisation at all, which turned every file id into a public download.
     */
    public function temporaryDownload(Request $request, PdfFile $pdfFile, AuditLogger $auditLogger): StreamedResponse
    {
        $disk = Storage::disk('pdf');

        abort_unless(filled($pdfFile->file_path) && $disk->exists($pdfFile->file_path), 404);

        // No authenticated actor here — the link is the authorisation — so the
        // ledger records how the file left rather than who asked for it.
        $auditLogger->record(
            actor: $request->user(),
            action: 'pdf_files.download',
            module: self::RESOURCE,
            subject: $pdfFile,
            after: [
                'file_id' => $pdfFile->file_id,
                'file_name' => $pdfFile->file_name,
                'via' => 'signed_link',
            ],
        );

        return $disk->download($pdfFile->file_path, $pdfFile->signedDownloadName(), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * @return Builder<PdfFile>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = PdfFile::query();

        if (filled($search = $request->string('search')->trim()->value())) {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('file_name', 'like', "%{$search}%")
                    ->orWhere('file_id', 'like', "%{$search}%")
                    ->orWhere('cover_report_number', 'like', "%{$search}%")
                    // Exact digest lookup: pasting a hash finds its record.
                    ->orWhere('sha256_hash', $search)
                    ->orWhere('md5_hash', $search);
            });
        }

        if (filled($createdBy = $request->string('created_by')->trim()->value())) {
            $query->where('created_by', 'like', "%{$createdBy}%");
        }

        if (filled($from = $request->string('signed_from')->value())) {
            $query->whereDate('signed_at', '>=', $from);
        }

        if (filled($to = $request->string('signed_to')->value())) {
            $query->whereDate('signed_at', '<=', $to);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PdfFile $file, bool $includeMetadata = false): array
    {
        $metadata = is_array($file->metadata) ? $file->metadata : [];

        $data = [
            'id' => $file->id,
            'file_id' => $file->file_id,
            'file_name' => $file->file_name,
            'sha256_hash' => $file->sha256_hash,
            'md5_hash' => $file->md5_hash,
            'file_size' => $file->file_size,
            'cover_report_number' => $file->cover_report_number,
            'cover_fields' => is_array($metadata['cover_fields'] ?? null) ? $metadata['cover_fields'] : null,
            'signed' => (bool) ($metadata['signed'] ?? false),
            'created_by' => $file->created_by,
            'signed_at' => $file->signed_at?->toIso8601String(),
            'has_file' => filled($file->file_path),
        ];

        if ($includeMetadata) {
            $data['metadata'] = $metadata;
        }

        return $data;
    }
}
