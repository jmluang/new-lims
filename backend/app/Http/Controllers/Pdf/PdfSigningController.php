<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Controller;
use App\Models\CertificateTemplate;
use App\Models\DigitalSignature;
use App\Models\HomepageFunctionStamp;
use App\Models\PerforationStamp;
use App\Services\Audit\AuditLogger;
use App\Services\Pdf\PdfSigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 签名台 — uploads a report, applies the selected seals, signs it through the
 * Java service, records its digests and streams the signed file back.
 */
class PdfSigningController extends Controller
{
    private const RESOURCE = 'pdf_signing';

    /**
     * Everything the signing desk needs to render its configuration panel.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'pdf_signing.read', self::RESOURCE);

        $signing = config('pdf_service.signing');

        return response()->json([
            'data' => [
                'certificate_templates' => CertificateTemplate::query()
                    ->active()
                    ->orderBy('language')
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->get(['id', 'name', 'language', 'description', 'file_name', 'file_size', 'is_default', 'updated_at']),
                'digital_signatures' => DigitalSignature::query()
                    ->active()
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->get(['id', 'name', 'description', 'is_default', 'updated_at']),
                'perforation_stamps' => PerforationStamp::query()
                    ->active()
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->get(['id', 'name', 'description', 'is_default', 'updated_at']),
                'function_stamps' => HomepageFunctionStamp::query()
                    ->active()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(['id', 'name', 'sort_order', 'is_default', 'updated_at']),
            ],
            'meta' => [
                'signing_enabled' => (bool) $signing['enabled'] && (bool) config('pdf_service.enabled'),
                // Ported from zs-lims but off: the UI hides the entry point and
                // the API rejects the flag while this is false.
                'photometric_removal_enabled' => (bool) $signing['photometric_removal_enabled'],
                'max_upload_kb' => (int) $signing['max_upload_kb'],
                'operator_name' => $request->user()?->name,
            ],
        ]);
    }

    /**
     * Streams a declaration page so the browser can merge it into the report
     * before upload. Guarded by the signing permission, not the template
     * settings permission, because operators need it while signing.
     */
    public function certificateTemplate(Request $request, CertificateTemplate $certificateTemplate): StreamedResponse
    {
        $this->authorizePermission($request, 'pdf_signing.read', self::RESOURCE);

        abort_unless($certificateTemplate->is_active, 404);

        $disk = Storage::disk('pdf');

        abort_unless(filled($certificateTemplate->file_path) && $disk->exists($certificateTemplate->file_path), 404);

        return $disk->response(
            $certificateTemplate->file_path,
            $certificateTemplate->file_name ?: 'certificate.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function process(Request $request, PdfSigningService $pdfSigningService, AuditLogger $auditLogger): BinaryFileResponse|JsonResponse
    {
        $this->authorizePermission($request, 'pdf_signing.create', self::RESOURCE);

        $signing = config('pdf_service.signing');

        if (! $signing['enabled'] || ! config('pdf_service.enabled')) {
            return response()->json(['message' => 'pdf_signing_disabled'], 503);
        }

        $validated = $request->validate([
            'pdf_file' => ['required', 'file', 'mimes:pdf', 'max:'.(int) $signing['max_upload_kb']],
            'file_number' => ['nullable', 'string', 'max:255'],
            'original_name' => ['nullable', 'string', 'max:255'],
            // Confirmed by the operator on the signing desk. Optional: a report
            // without a number is still signable, it just is not searchable by
            // one.
            'report_number' => ['nullable', 'string', 'max:255'],
            'certificate_id' => ['nullable', 'integer', 'exists:certificate_templates,id'],
            'digital_signature_id' => ['nullable', 'integer', 'exists:digital_signatures,id'],
            'perforation_stamp_id' => ['nullable', 'integer', 'exists:perforation_stamps,id'],
            'function_stamp_ids' => ['nullable', 'array', 'max:20'],
            'function_stamp_ids.*' => ['integer', 'exists:homepage_function_stamps,id'],
            'remove_photometric_content' => ['sometimes', 'boolean'],
        ]);

        $removePhotometric = (bool) ($validated['remove_photometric_content'] ?? false);

        if ($removePhotometric && ! $signing['photometric_removal_enabled']) {
            throw ValidationException::withMessages([
                'remove_photometric_content' => ['photometric_removal_disabled'],
            ]);
        }

        $upload = $request->file('pdf_file');
        $originalName = $validated['original_name'] ?? $upload->getClientOriginalName() ?? 'document.pdf';
        $fileNumber = $validated['file_number'] ?? $this->generateFileNumber();
        $certificateName = isset($validated['certificate_id'])
            ? CertificateTemplate::query()->whereKey($validated['certificate_id'])->value('name')
            : null;

        try {
            $result = $pdfSigningService->handle($upload->getRealPath(), [
                'file_number' => $fileNumber,
                'operator_name' => $request->user()?->name ?? 'unknown',
                'operator_id' => $request->user()?->id,
                'original_name' => $originalName,
                'report_number' => $validated['report_number'] ?? null,
                'certificate_id' => $validated['certificate_id'] ?? null,
                'certificate_name' => $certificateName,
                'digital_signature_id' => $validated['digital_signature_id'] ?? null,
                'perforation_stamp_id' => $validated['perforation_stamp_id'] ?? null,
                'function_stamp_ids' => array_map('intval', $validated['function_stamp_ids'] ?? []),
                'remove_photometric_content' => $removePhotometric,
            ]);
        } catch (\Throwable $exception) {
            Log::error('PDF 签章失败', [
                'error' => $exception->getMessage(),
                'file_number' => $fileNumber,
                'original_name' => $originalName,
            ]);

            return response()->json([
                'message' => 'pdf_signing_failed',
                'error' => $exception->getMessage(),
            ], 500);
        }

        $auditLogger->record(
            actor: $request->user(),
            action: 'pdf_signing.create',
            module: self::RESOURCE,
            subject: $result['pdf_file'],
            after: [
                'file_id' => $result['pdf_file']->file_id,
                'file_name' => $originalName,
                'sha256_hash' => $result['metadata']['sha256_hash'],
                'file_size' => $result['metadata']['file_size'],
                'certificate_id' => $validated['certificate_id'] ?? null,
                'digital_signature_id' => $validated['digital_signature_id'] ?? null,
                'perforation_stamp_id' => $validated['perforation_stamp_id'] ?? null,
                'function_stamp_ids' => $validated['function_stamp_ids'] ?? [],
                'remove_photometric_content' => $removePhotometric,
            ],
        );

        return response()->download($result['path'], $result['pdf_file']->signedDownloadName(), [
            'Content-Type' => 'application/pdf',
            'X-Final-File-Hash' => $result['metadata']['sha256_hash'],
            'X-Final-File-Md5' => $result['metadata']['md5_hash'],
            'X-Final-File-Size' => (string) $result['metadata']['file_size'],
            'X-Final-File-Id' => $result['pdf_file']->file_id,
            // A real URL for the same file. The browser is handed the bytes
            // inline as well, but a `blob:` URL is useless to a browser that
            // delegates downloads to its own download manager, so the desk
            // triggers the download from this instead when it is present.
            'X-Final-Download-Url' => URL::temporarySignedRoute(
                'pdf.files.temporary-download',
                now()->addMinutes((int) $signing['download_link_ttl_minutes']),
                ['pdfFile' => $result['pdf_file']->id],
            ),
            // These are listed in config/cors.php `exposed_headers` so the SPA
            // can read them cross-origin.
            //
            // Percent-encoded because HTTP header values are ISO-8859-1: a
            // Chinese report number sent raw reaches the browser as mojibake.
            'X-Cover-Report-Number' => rawurlencode((string) ($result['metadata']['cover_report_number'] ?? '')),
        ]);
    }

    private function generateFileNumber(): string
    {
        return 'ZST-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6));
    }
}
