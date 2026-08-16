<?php

namespace Database\Seeders;

use App\Models\DigitalSignature;
use App\Models\HomepageFunctionStamp;
use App\Models\PerforationStamp;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Seeds placeholder seal artwork for the legacy PDF signing desk.
 *
 * The desk cannot sign or stamp anything without at least one seal record, so a
 * fresh database leaves /pdf/signing with nothing to select. This makes the flow
 * exercisable locally; the images are the Java service's test fixtures, not real
 * organisational seals, and every record is named accordingly.
 */
class PdfLegacyAssetSeeder extends Seeder
{
    private const DISK = 'pdf';

    public function run(): void
    {
        $fixtures = base_path('../services/pdf-renderer-java/src/test/resources');

        // signature.png and stamp.png are 1x1 placeholders. The perforation seal is
        // sliced across pages, so it needs artwork with real dimensions.
        $signature = $this->copy("{$fixtures}/samples/stamp1.png", 'digital-signatures');
        $perforation = $this->copy("{$fixtures}/samples/stamp2.png", 'perforation-stamps');
        $functionOne = $this->copy("{$fixtures}/samples/stamp1.png", 'function-stamps');
        $functionTwo = $this->copy("{$fixtures}/samples/stamp2.png", 'function-stamps');

        DigitalSignature::query()->updateOrCreate(
            ['name' => '测试首页章'],
            [
                'appearance_image_path' => $signature,
                'description' => '本地测试素材，非真实机构印章',
                'signature_contact' => 'lims-test@example.invalid',
                'signature_location' => '本地测试环境',
                'signature_reason' => '本地流程验证',
                'is_default' => true,
                'is_active' => true,
            ],
        );

        PerforationStamp::query()->updateOrCreate(
            ['name' => '测试骑缝章'],
            [
                'appearance_image_path' => $perforation,
                'description' => '本地测试素材，非真实机构印章',
                'is_default' => true,
                'is_active' => true,
            ],
        );

        foreach ([['测试功能章 A', $functionOne, 1], ['测试功能章 B', $functionTwo, 2]] as [$name, $path, $order]) {
            HomepageFunctionStamp::query()->updateOrCreate(
                ['name' => $name],
                [
                    'image_path' => $path,
                    'sort_order' => $order,
                    'is_default' => $order === 1,
                    'is_active' => true,
                ],
            );
        }

        $this->command?->info('Seeded placeholder legacy PDF signing assets.');
    }

    /** Mirrors PdfAssetController: a UUID filename inside the asset's directory. */
    private function copy(string $source, string $directory): string
    {
        if (! is_file($source)) {
            throw new RuntimeException("Missing seal fixture: {$source}");
        }

        $target = $directory.'/'.Str::uuid()->toString().'.'.pathinfo($source, PATHINFO_EXTENSION);
        Storage::disk(self::DISK)->put($target, (string) file_get_contents($source));

        return $target;
    }
}
