<?php

namespace App\Services\Pdf;

use App\Models\PdfSignatureAppearanceArtifact;
use App\Models\PdfSigningRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class SignatureAppearanceService
{
    public const RENDERER_VERSION = 'handwriting-png-v1';

    public function __construct(private readonly PdfImmutableFileStore $files) {}

    public function create(PdfSigningRequest $request, UploadedFile $upload, User $actor): PdfSignatureAppearanceArtifact
    {
        if ($upload->getSize() > 5 * 1024 * 1024) {
            throw new UnprocessableEntityHttpException('PDF_APPEARANCE_TOO_LARGE');
        }

        $raw = file_get_contents($upload->getRealPath());

        if (! is_string($raw) || $raw === '') {
            throw new UnprocessableEntityHttpException('PDF_APPEARANCE_EMPTY');
        }

        [$png, $width, $height, $cropBox] = $this->canonicalize($raw);
        $appearanceUuid = (string) Str::uuid();
        $targetPath = "workflow/appearances/{$appearanceUuid}.png";
        $stored = $this->files->putBytes($png, $targetPath);

        try {
            return DB::transaction(function () use (
                $request, $actor, $appearanceUuid, $targetPath, $stored, $width, $height, $cropBox,
            ): PdfSignatureAppearanceArtifact {
                $locked = PdfSigningRequest::query()->lockForUpdate()->findOrFail($request->id);

                if ($locked->assigned_user_id !== $actor->id || $locked->status !== 'available') {
                    throw new ConflictHttpException('PDF_SIGNING_REQUEST_NOT_AVAILABLE_TO_USER');
                }

                $field = $locked->field()->with('slots')->firstOrFail();
                $slotManifest = $field->slots->map(fn ($slot): array => [
                    'slot_uuid' => $slot->slot_uuid,
                    'page_index' => $slot->page_index,
                    'widget_index' => $slot->widget_index,
                    'geometry_hash' => $slot->geometry_hash,
                    'normalized_rect' => $slot->normalized_rect,
                ])->all();
                $manifest = [
                    'version' => self::RENDERER_VERSION,
                    'request_uuid' => $locked->request_uuid,
                    'source_sha256' => $locked->expected_source_sha256,
                    'field_name' => $field->field_name,
                    'canonical_image_sha256' => $stored['sha256'],
                    'canonical_image_size' => $stored['size'],
                    'width' => $width,
                    'height' => $height,
                    'crop_box' => $cropBox,
                    'slots' => $slotManifest,
                ];

                return PdfSignatureAppearanceArtifact::query()->create([
                    'appearance_uuid' => $appearanceUuid,
                    'request_id' => $locked->id,
                    'created_by_id' => $actor->id,
                    'artifact_type' => $locked->request_type === 'homepage_seal' ? 'homepage_seal' : 'handwriting',
                    'canonical_image_sha256' => $stored['sha256'],
                    'appearance_manifest_hash' => hash('sha256', CanonicalJson::encode($manifest)),
                    'slot_manifest' => $slotManifest,
                    'width' => $width,
                    'height' => $height,
                    'crop_box' => $cropBox,
                    'renderer_version' => self::RENDERER_VERSION,
                    'state' => 'available',
                    'retention_until' => now()->addDay(),
                    'file_path' => $targetPath,
                ]);
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('pdf')->delete($targetPath);
            throw $exception;
        }
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: array{x: int, y: int, width: int, height: int}}
     */
    private function canonicalize(string $raw): array
    {
        $source = @imagecreatefromstring($raw);

        if ($source === false) {
            throw new UnprocessableEntityHttpException('PDF_APPEARANCE_IMAGE_INVALID');
        }

        try {
            if (! imageistruecolor($source)) {
                imagepalettetotruecolor($source);
            }

            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);

            if ($sourceWidth < 8 || $sourceHeight < 8 || $sourceWidth > 4096 || $sourceHeight > 4096
                || $sourceWidth * $sourceHeight > 8_000_000) {
                throw new UnprocessableEntityHttpException('PDF_APPEARANCE_DIMENSIONS_INVALID');
            }

            $left = $sourceWidth;
            $top = $sourceHeight;
            $right = -1;
            $bottom = -1;

            for ($y = 0; $y < $sourceHeight; $y++) {
                for ($x = 0; $x < $sourceWidth; $x++) {
                    $rgba = imagecolorat($source, $x, $y);
                    $alpha = ($rgba >> 24) & 0x7F;
                    $red = ($rgba >> 16) & 0xFF;
                    $green = ($rgba >> 8) & 0xFF;
                    $blue = $rgba & 0xFF;
                    $luminance = (int) round(0.2126 * $red + 0.7152 * $green + 0.0722 * $blue);

                    if ($alpha < 124 && $luminance < 248) {
                        $left = min($left, $x);
                        $top = min($top, $y);
                        $right = max($right, $x);
                        $bottom = max($bottom, $y);
                    }
                }
            }

            if ($right < $left || $bottom < $top) {
                throw new UnprocessableEntityHttpException('PDF_APPEARANCE_HAS_NO_INK');
            }

            $inkWidth = $right - $left + 1;
            $inkHeight = $bottom - $top + 1;
            $padding = max(8, (int) ceil($inkHeight * 0.12));
            $cropWidth = $inkWidth + 2 * $padding;
            $cropHeight = $inkHeight + 2 * $padding;
            $scale = min(1.0, 1024 / $cropWidth, 384 / $cropHeight);
            $width = max(1, (int) round($cropWidth * $scale));
            $height = max(1, (int) round($cropHeight * $scale));
            $canonical = imagecreatetruecolor($width, $height);

            if ($canonical === false) {
                throw new RuntimeException('Unable to create canonical signature image.');
            }

            try {
                imagealphablending($canonical, false);
                imagesavealpha($canonical, true);
                $transparent = imagecolorallocatealpha($canonical, 0, 0, 0, 127);
                imagefill($canonical, 0, 0, $transparent);
                $intermediate = imagecreatetruecolor($cropWidth, $cropHeight);

                if ($intermediate === false) {
                    throw new RuntimeException('Unable to create signature normalization buffer.');
                }

                try {
                    imagealphablending($intermediate, false);
                    imagesavealpha($intermediate, true);
                    imagefill($intermediate, 0, 0, imagecolorallocatealpha($intermediate, 0, 0, 0, 127));

                    for ($y = $top; $y <= $bottom; $y++) {
                        for ($x = $left; $x <= $right; $x++) {
                            $rgba = imagecolorat($source, $x, $y);
                            $sourceAlpha = ($rgba >> 24) & 0x7F;
                            $red = ($rgba >> 16) & 0xFF;
                            $green = ($rgba >> 8) & 0xFF;
                            $blue = $rgba & 0xFF;
                            $luminance = 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
                            $opacity = ((127 - $sourceAlpha) / 127) * max(0, min(1, (255 - $luminance) / 64));
                            $alpha = 127 - (int) round(127 * $opacity);

                            if ($alpha < 127) {
                                $color = imagecolorallocatealpha($intermediate, 23, 59, 108, $alpha);
                                imagesetpixel($intermediate, $x - $left + $padding, $y - $top + $padding, $color);
                            }
                        }
                    }

                    imagecopyresampled(
                        $canonical,
                        $intermediate,
                        0,
                        0,
                        0,
                        0,
                        $width,
                        $height,
                        $cropWidth,
                        $cropHeight,
                    );
                } finally {
                    imagedestroy($intermediate);
                }

                ob_start();
                imagepng($canonical, null, 9, PNG_ALL_FILTERS);
                $png = ob_get_clean();

                if (! is_string($png) || $png === '') {
                    throw new RuntimeException('Unable to encode canonical signature PNG.');
                }

                return [$png, $width, $height, [
                    'x' => $left,
                    'y' => $top,
                    'width' => $inkWidth,
                    'height' => $inkHeight,
                ]];
            } finally {
                imagedestroy($canonical);
            }
        } finally {
            imagedestroy($source);
        }
    }
}
