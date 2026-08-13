<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Controller;
use App\Models\PdfVerificationLog;
use App\Services\Pdf\PdfVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Tamper checks for a PDF the caller already holds.
 *
 * Two entry points, one engine: the signed-in desk posts digests the browser
 * computed (so a 100 MB report never leaves the operator's machine), while the
 * public page uploads the file and lets the server digest it.
 */
class PdfVerificationController extends Controller
{
    private const RESOURCE = 'pdf_verification';

    /**
     * Digest-only verification for signed-in operators.
     */
    public function verify(Request $request, PdfVerificationService $pdfVerificationService): JsonResponse
    {
        $this->authorizePermission($request, 'pdf_verification.create', self::RESOURCE);

        $validated = $request->validate([
            'file_name' => ['required', 'string', 'max:500'],
            'file_size' => ['required', 'integer', 'min:1'],
            'current_digests' => ['required', 'array'],
            'current_digests.primary_hash' => ['required', 'string'],
            'current_digests.secondary_hash' => ['nullable', 'string'],
            'current_digests.md5_hash' => ['nullable', 'string'],
            'current_digests.crc32_hash' => ['nullable', 'string'],
            'current_digests.file_size' => ['nullable', 'integer'],
        ]);

        $digests = $validated['current_digests'];
        $digests['file_size'] ??= $validated['file_size'];

        try {
            $result = $pdfVerificationService->verifyDigests(
                fileName: $validated['file_name'],
                fileSize: (int) $validated['file_size'],
                currentDigests: $digests,
                source: PdfVerificationLog::SOURCE_ADMIN,
                user: $request->user(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => 'pdf_verification_invalid_request',
                'error' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Upload-based verification for report recipients. No authentication: the
     * caller must already possess the file, and the response only confirms
     * whether these exact bytes were issued by this lab.
     */
    public function publicVerify(Request $request, PdfVerificationService $pdfVerificationService): JsonResponse
    {
        if (! config('pdf_service.public_verification.enabled')) {
            return response()->json(['message' => 'public_verification_disabled'], 404);
        }

        $request->validate([
            'pdf_file' => ['required', 'file', 'mimes:pdf', 'max:'.(int) config('pdf_service.signing.max_upload_kb')],
        ]);

        try {
            $result = $pdfVerificationService->verifyUploadedFile(
                file: $request->file('pdf_file'),
                source: PdfVerificationLog::SOURCE_PUBLIC,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => 'pdf_verification_invalid_request',
                'error' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['data' => $this->redactForPublic($result)]);
    }

    /**
     * The public response confirms authenticity without disclosing who signed
     * the report, when it was processed internally, or the ledger row id.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function redactForPublic(array $result): array
    {
        $database = $result['verification_details']['database_verification'] ?? [];
        $record = $database['record'] ?? null;

        $result['verification_details']['database_verification'] = [
            'found' => (bool) ($database['found'] ?? false),
            'record' => $record === null ? null : [
                'file_name' => $record['file_name'] ?? null,
                'signed_at' => $record['signed_at'] ?? null,
                'cover_report_number' => $record['cover_report_number'] ?? null,
                'cover_fields' => $record['cover_fields'] ?? null,
            ],
        ];

        return $result;
    }
}
