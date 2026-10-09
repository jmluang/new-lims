<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Controller;
use App\Models\PdfYanzhenjiaSetting;
use App\Models\PdfYanzhenjiaSync;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PdfYanzhenjiaSettingsController extends Controller
{
    private const RESOURCE = 'pdf_yanzhenjia_settings';

    public function show(Request $request): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE);

        $setting = PdfYanzhenjiaSetting::query()->find(PdfYanzhenjiaSetting::SINGLETON_ID);

        return response()->json(['data' => $this->serialize($setting)]);
    }

    public function recentSyncs(Request $request): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE);

        $syncs = PdfYanzhenjiaSync::query()
            ->with('pdfFile:id,file_id,file_name,cover_report_number,sha256_hash')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json(['data' => $syncs->map(function (PdfYanzhenjiaSync $sync): array {
            $payload = is_array($sync->request_payload) ? $sync->request_payload : [];

            return [
                'id' => $sync->id,
                'api_version' => $sync->api_version,
                'file_id' => $sync->source_file_id ?? $sync->pdfFile?->file_id,
                'file_name' => $sync->source_file_name ?? $sync->pdfFile?->file_name,
                'report_number' => $payload['report_number'] ?? $sync->pdfFile?->cover_report_number,
                'sha256' => $payload['sha256'] ?? $sync->pdfFile?->sha256_hash,
                'status' => $sync->status,
                'source_deleted_at' => $sync->source_deleted_at?->toIso8601String(),
                'updated_at' => $sync->updated_at?->toIso8601String(),
            ];
        })->values()]);
    }

    public function update(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.update', self::RESOURCE);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'appid' => ['sometimes', 'nullable', 'string', 'regex:/\A[a-fA-F0-9]{32}\z/'],
            'secret' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ]);

        $setting = DB::transaction(function () use ($validated): PdfYanzhenjiaSetting {
            $setting = PdfYanzhenjiaSetting::query()->find(PdfYanzhenjiaSetting::SINGLETON_ID);
            if ($setting === null) {
                $setting = new PdfYanzhenjiaSetting;
                $setting->id = PdfYanzhenjiaSetting::SINGLETON_ID;
            }
            $appid = array_key_exists('appid', $validated) ? $validated['appid'] : $setting->appid;
            $newSecret = $validated['secret'] ?? null;
            $hasSecret = filled($newSecret) || filled($setting->secret);

            if ((bool) $validated['enabled'] && blank($appid)) {
                throw ValidationException::withMessages(['appid' => ['启用同步前请填写有效的 32 位十六进制 AppID。']]);
            }

            if ((bool) $validated['enabled'] && ! $hasSecret) {
                throw ValidationException::withMessages(['secret' => ['启用同步前请填写 Secret。']]);
            }

            $setting->enabled = (bool) $validated['enabled'];
            $setting->appid = $appid === null ? null : strtolower($appid);
            if (filled($newSecret)) {
                $setting->secret = $newSecret;
            }
            $setting->save();

            return $setting;
        });

        $auditLogger->record(
            actor: $request->user(),
            action: self::RESOURCE.'.update',
            module: self::RESOURCE,
            subject: $setting,
            after: [
                'enabled' => $setting->enabled,
                'appid' => $setting->appid,
                'has_secret' => $setting->hasCredentials(),
            ],
        );

        return response()->json(['data' => $this->serialize($setting)]);
    }

    /**
     * @return array{enabled: bool, appid: ?string, has_secret: bool, api_url: string}
     */
    private function serialize(?PdfYanzhenjiaSetting $setting): array
    {
        return [
            'enabled' => (bool) ($setting?->enabled ?? false),
            'appid' => $setting?->appid,
            'has_secret' => $setting?->hasCredentials() ?? false,
            'api_url' => (string) config('services.yanzhenjia.api_url'),
        ];
    }
}
