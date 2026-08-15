<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Controller;
use App\Models\PdfVerificationLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every verification attempt, including failed ones — a run of failures on the
 * same report number is the signal that someone is circulating an edited copy.
 */
class PdfVerificationLogController extends Controller
{
    private const RESOURCE = 'pdf_verification_logs';

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'pdf_verification_logs.read', self::RESOURCE);

        $logs = $this->filteredQuery($request)
            ->with('user:id,name')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $logs->getCollection()->map(fn (PdfVerificationLog $log): array => $this->serialize($log))->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function show(Request $request, PdfVerificationLog $pdfVerificationLog): JsonResponse
    {
        $this->authorizePermission($request, 'pdf_verification_logs.read', self::RESOURCE, $pdfVerificationLog);

        return response()->json([
            'data' => $this->serialize($pdfVerificationLog->load('user:id,name')) + [
                'verification_data' => $pdfVerificationLog->verification_data,
            ],
        ]);
    }

    /**
     * Downloads the copy kept of the file that was checked, when one was stored.
     */
    public function download(Request $request, PdfVerificationLog $pdfVerificationLog, AuditLogger $auditLogger): StreamedResponse
    {
        $this->authorizePermission($request, 'pdf_verification_logs.download', self::RESOURCE, $pdfVerificationLog);

        $disk = Storage::disk('pdf');

        abort_unless(
            filled($pdfVerificationLog->saved_file_path) && $disk->exists($pdfVerificationLog->saved_file_path),
            404,
        );

        $auditLogger->record(
            actor: $request->user(),
            action: 'pdf_verification_logs.download',
            module: self::RESOURCE,
            subject: $pdfVerificationLog,
            after: ['file_name' => $pdfVerificationLog->file_name],
        );

        // download(), not response(): the latter defaults to an inline
        // disposition, which opens the file in a browser tab instead of saving
        // it.
        return $disk->download($pdfVerificationLog->saved_file_path, $pdfVerificationLog->file_name, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * @return Builder<PdfVerificationLog>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = PdfVerificationLog::query();

        if (filled($search = $request->string('search')->trim()->value())) {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('file_name', 'like', "%{$search}%")
                    ->orWhere('primary_hash', $search)
                    ->orWhere('md5_hash', $search);
            });
        }

        // `has()` would be true for the empty string the "all" option sends, and
        // `boolean('')` is false — which silently hid every passing check.
        if (filled($request->string('overall_valid')->value())) {
            $query->where('overall_valid', $request->boolean('overall_valid'));
        }

        if (filled($source = $request->string('verify_source')->value())) {
            $query->where('verify_source', $source);
        }

        if (filled($securityLevel = $request->string('security_level')->value())) {
            $query->where('security_level', $securityLevel);
        }

        if (filled($from = $request->string('verified_from')->value())) {
            $query->whereDate('created_at', '>=', $from);
        }

        if (filled($to = $request->string('verified_to')->value())) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PdfVerificationLog $log): array
    {
        return [
            'id' => $log->id,
            'file_name' => $log->file_name,
            'file_size' => $log->file_size,
            'primary_hash' => $log->primary_hash,
            'md5_hash' => $log->md5_hash,
            'overall_valid' => $log->overall_valid,
            'security_level' => $log->security_level,
            'verification_message' => $log->verification_message,
            'verify_source' => $log->verify_source,
            'ip_address' => $log->ip_address,
            'user' => $log->user?->only(['id', 'name']),
            'has_saved_file' => filled($log->saved_file_path),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
