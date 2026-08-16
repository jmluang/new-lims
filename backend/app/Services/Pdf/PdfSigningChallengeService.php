<?php

namespace App\Services\Pdf;

use App\Models\PdfFile;
use App\Models\PdfSignatureAppearanceArtifact;
use App\Models\PdfSigningChallenge;
use App\Models\PdfSigningPolicyVersion;
use App\Models\PdfSigningRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class PdfSigningChallengeService
{
    public function __construct(private readonly PdfImmutableFileStore $files) {}

    public function create(
        PdfSigningRequest $request,
        PdfSignatureAppearanceArtifact $appearance,
        string $currentPassword,
        string $authContextId,
        User $actor,
    ): PdfSigningChallenge {
        if (! Hash::check($currentPassword, $actor->password)) {
            throw new UnprocessableEntityHttpException('PDF_CURRENT_PASSWORD_INVALID');
        }

        return DB::transaction(function () use (
            $request, $appearance, $authContextId, $actor,
        ): PdfSigningChallenge {
            $lockedRequest = PdfSigningRequest::query()->lockForUpdate()->findOrFail($request->id);
            $lockedAppearance = PdfSignatureAppearanceArtifact::query()->lockForUpdate()->findOrFail($appearance->id);

            if ($lockedRequest->assigned_user_id !== $actor->id || $lockedRequest->status !== 'available') {
                throw new ConflictHttpException('PDF_SIGNING_REQUEST_NOT_CHALLENGEABLE');
            }

            if ($lockedAppearance->request_id !== $lockedRequest->id
                || $lockedAppearance->created_by_id !== $actor->id
                || $lockedAppearance->state !== 'available'
                || $lockedAppearance->evidence_hold_state !== 'none'
                || $lockedAppearance->retirement_state !== 'none'
                || $lockedAppearance->deleted_at !== null) {
                throw new ConflictHttpException('PDF_APPEARANCE_NOT_CHALLENGEABLE');
            }

            $revision = PdfFile::query()->lockForUpdate()->findOrFail($lockedRequest->expected_source_revision_id);
            $policy = PdfSigningPolicyVersion::query()->findOrFail($lockedRequest->signing_policy_version_id);

            if ($revision->sha256_hash !== $lockedRequest->expected_source_sha256
                || $revision->integrity_state !== 'ready') {
                throw new ConflictHttpException('PDF_REQUEST_SOURCE_REVISION_CHANGED');
            }

            $this->files->verifiedAbsolutePath(
                $revision->file_path,
                $revision->sha256_hash,
                (int) $revision->file_size,
            );
            $expectedFingerprint = $policy->organization_certificate_fingerprints[0] ?? null;

            if (! is_string($expectedFingerprint) || ! preg_match('/^[0-9a-f]{64}$/', $expectedFingerprint)) {
                throw new ConflictHttpException('PDF_POLICY_CERTIFICATE_FINGERPRINT_INVALID');
            }

            PdfSigningChallenge::query()
                ->where('request_id', $lockedRequest->id)
                ->where('user_id', $actor->id)
                ->whereNull('consumed_at')
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => now()]);

            $expiresAt = now()->addMinutes(5);
            $minimumRetention = $expiresAt->copy()->addDay();
            $lockedAppearance->update([
                'retention_until' => $lockedAppearance->retention_until !== null
                    && $lockedAppearance->retention_until->greaterThan($minimumRetention)
                        ? $lockedAppearance->retention_until
                        : $minimumRetention,
            ]);

            return PdfSigningChallenge::query()->create([
                'challenge_uuid' => (string) Str::uuid(),
                'request_id' => $lockedRequest->id,
                'user_id' => $actor->id,
                'source_revision_id' => $revision->id,
                'source_sha256' => $revision->sha256_hash,
                'field_plan_hash' => $lockedRequest->workflow()->value('field_plan_hash'),
                'appearance_artifact_id' => $lockedAppearance->id,
                'appearance_manifest_hash' => $lockedAppearance->appearance_manifest_hash,
                'intent' => "Sign {$lockedRequest->request_uuid} with the organization certificate",
                'signing_policy_version_id' => $policy->id,
                'policy_hash' => $policy->policy_hash,
                'expected_certificate_fingerprint' => $expectedFingerprint,
                'auth_context_id' => $authContextId,
                'password_changed_at_snapshot' => $actor->password_changed_at,
                'reauthenticated_at' => now(),
                'expires_at' => $expiresAt,
            ]);
        }, 3);
    }
}
