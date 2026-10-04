<?php

namespace Tests\Unit\Reports;

use App\Services\Reports\MeasurementImport;
use InvalidArgumentException;
use Tests\Support\MeasurementFixtures;
use Tests\TestCase;

class MeasurementImportTest extends TestCase
{
    private array $paths = [];

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'measurement-');
        file_put_contents($path, $bytes);
        $this->paths[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function test_haas_maps_colorimetry_and_spectrum_without_overwriting_sample_identity_or_gonio_flux(): void
    {
        $result = (new MeasurementImport)->parse($this->file(MeasurementFixtures::haas()), 'haas');
        $this->assertSame(1, $result['selected_record']);
        $this->assertSame('5585', $result['values']['cct']);
        $this->assertSame('88', $result['values']['cri_r9']);
        $this->assertSame('400-402 nm', $result['values']['spectrum_range']);
        $this->assertSame('1', $result['values']['spectrum_interval']);
        $this->assertSame(3, $result['spectrum_point_count']);
        $this->assertCount(3, explode("\n", $result['values']['spectrum_data']));
        foreach (['model', 'product_name', 'lab_name', 'test_date', 'total_flux', 'power', 'duv', 'tm30_rf', 'sdcm'] as $field) {
            $this->assertArrayNotHasKey($field, $result['values']);
        }
    }

    public function test_multiple_haas_records_require_an_explicit_choice(): void
    {
        $path = $this->file(MeasurementFixtures::haas([3000, 6000]));
        $parser = new MeasurementImport;
        $unselected = $parser->parse($path, 'haas');
        $this->assertNull($unselected['selected_record']);
        $this->assertSame([], $unselected['values']);
        $this->assertCount(2, $unselected['records']);
        $this->assertSame('6000', $parser->parse($path, 'haas', 2)['values']['cct']);
        $this->expectException(InvalidArgumentException::class);
        $parser->parse($path, 'haas', 3);
    }

    public function test_gos_electrical_header_is_imported_after_variable_length_sample_strings(): void
    {
        $result = (new MeasurementImport)->parse($this->file(MeasurementFixtures::gos(details: ['Model detail', str_repeat('A', 300), 'Factory'])), 'gos');
        $this->assertSame('600', $result['values']['total_flux']);
        $this->assertSame('25.3', $result['values']['ambient_temp']);
        $this->assertSame('220', $result['values']['voltage'] ?? null);
        $this->assertSame('0.125', $result['values']['current'] ?? null);
        $this->assertSame('27.5', $result['values']['power'] ?? null);
        $this->assertSame('1', $result['values']['power_factor'] ?? null);
        $this->assertSame('50', $result['values']['frequency'] ?? null);
        $this->assertSame('', $result['values']['displacement_factor'] ?? null);
        foreach (['current_thd', 'voltage_regulation', 'waveform_thd', 'candela_data'] as $field) {
            $this->assertArrayNotHasKey($field, $result['values']);
        }
    }

    public function test_gos_displacement_uses_its_availability_flag_and_never_power_factor(): void
    {
        $parser = new MeasurementImport;
        $valid = $parser->parse($this->file(MeasurementFixtures::gos(frequency: 60, hasDisplacement: 1, displacement: 0.75)), 'gos');
        $this->assertSame('60', $valid['values']['frequency'] ?? null);
        $this->assertSame('0.75', $valid['values']['displacement_factor'] ?? null);
        $zero = $parser->parse($this->file(MeasurementFixtures::gos(hasDisplacement: 1, displacement: 0)), 'gos');
        $this->assertSame('0', $zero['values']['displacement_factor'] ?? null);
        $unavailable = $parser->parse($this->file(MeasurementFixtures::gos(hasDisplacement: 0, displacement: 0.75)), 'gos');
        $this->assertSame('', $unavailable['values']['displacement_factor'] ?? null);
        $ignored = $parser->parse($this->file(MeasurementFixtures::gos(hasDisplacement: 0, displacement: NAN)), 'gos');
        $this->assertSame('', $ignored['values']['displacement_factor']);
        $raw = \App\Services\Reports\Instrument\parseGos($this->file(MeasurementFixtures::gos()));
        $this->assertFalse($raw['has_displacement_factor'] ?? null);
        $this->assertArrayHasKey('displacement_factor', $raw);
        $this->assertNull($raw['displacement_factor']);
    }

    public function test_gos_tail_search_ignores_marker_bytes_inside_the_angle_matrix(): void
    {
        $bytes = MeasurementFixtures::gos(hasDisplacement: 1, displacement: 0.75);
        $matrix = strpos($bytes, pack('V2', 16, 4)) + 8 + 4 * (16 + 4);
        $bytes = substr_replace($bytes, "\x04R_V1", $matrix + 32, 5);
        $result = (new MeasurementImport)->parse($this->file($bytes), 'gos');
        $this->assertSame('50', $result['values']['frequency']);
        $this->assertSame('0.75', $result['values']['displacement_factor']);
    }

    public function test_gos_malformed_electrical_tails_fail_with_friendly_errors(): void
    {
        $bytes = MeasurementFixtures::gos(hasDisplacement: 1, displacement: 0.75);
        $q = strpos($bytes, "\x04R_V1");
        foreach ([
            substr($bytes, 0, $q - 12), substr($bytes, 0, -1), $bytes."\x04R_V1",
            substr_replace($bytes, pack('V', 0xFFFFFFFF), $q + 13, 4),
            substr_replace($bytes, pack('V', 100000000), $q + 13, 4),
            substr_replace($bytes, pack('V', 2), $q + 29 + 4 * 3, 4),
            substr_replace($bytes, pack('g', NAN), $q - 12, 4),
            substr_replace($bytes, pack('g', NAN), $q + 33 + 4 * 3, 4),
        ] as $bad) {
            try {
                (new MeasurementImport)->parse($this->file($bad), 'gos');
                $this->fail('Malformed electrical tails must be rejected.');
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('文件无法解析', $error->getMessage());
                $this->assertNotNull($error->getPrevious());
            }
        }
    }

    public function test_gos_empty_electrical_record_does_not_replace_manual_measurements_with_zeroes(): void
    {
        $result = (new MeasurementImport)->parse($this->file(MeasurementFixtures::gos([0, 0, 0, 0])), 'gos');
        foreach (['voltage', 'current', 'power', 'power_factor'] as $field) {
            $this->assertArrayNotHasKey($field, $result['values']);
        }
        $this->assertStringContainsString('电气数据均为 0', $result['notice']);
    }

    public function test_gos_non_finite_electrical_measurements_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('文件无法解析');
        (new MeasurementImport)->parse($this->file(MeasurementFixtures::gos([NAN, 0.125, 27.5, 1])), 'gos');
    }

    public function test_wrong_binary_format_is_rejected_even_with_a_matching_extension(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MeasurementImport)->parse($this->file(MeasurementFixtures::haas()), 'gos');
    }

    public function test_truncated_and_oversized_spectrum_headers_fail_before_large_allocations(): void
    {
        $oversizedSpectrum = MeasurementFixtures::haas();
        $offset = strpos($oversizedSpectrum, pack('V', 3));
        $oversizedSpectrum = substr_replace($oversizedSpectrum, pack('V', 1000000), $offset, 4);
        foreach ([substr(MeasurementFixtures::haas(), 0, 30), chr(8).'LED300_B'.pack('V2', 0, 100000), $oversizedSpectrum] as $bytes) {
            try {
                (new MeasurementImport)->parse($this->file($bytes), 'haas');
                $this->fail('Invalid binary input must fail.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('文件无法解析', $e->getMessage());
                $this->assertStringNotContainsString('EOF', $e->getMessage());
            }
        }
    }
}
