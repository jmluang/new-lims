<?php

namespace Tests\Unit\Reports;

use App\Services\Reports\Lm79Calculations;
use App\Services\Reports\Photometry;
use PHPUnit\Framework\TestCase;
use Tests\Support\MeasurementFixtures;

class Lm79CalculationsTest extends TestCase
{
    public function test_native_gos_coordinate_frame_is_used_when_an_ies_export_is_also_attached(): void
    {
        $gamma = range(0, 90);
        $wide = array_map(fn ($g) => max(0, 100 * (1 - $g / 60)), $gamma);
        $narrow = array_map(fn ($g) => max(0, 100 * (1 - $g / 20)), $gamma);
        $grid = ['gamma' => $gamma, 'planes' => [0, 90, 180, 270], 'intensity' => [$wide, $narrow, $wide, $narrow]];
        $path = tempnam(sys_get_temp_dir(), 'measurement-');
        file_put_contents($path, MeasurementFixtures::gos(photometry: $grid));
        $ies = "TILT=NONE\n1 1000 1 91 5 1 2 0 0 0 1 1 10\n".implode(' ', $gamma)."\n0 90 180 270 360\n"
            .implode(' ', array_merge($narrow, $wide, $narrow, $wide, $narrow));
        try {
            $this->assertEqualsWithDelta(20, (new Photometry)->calculate((new Photometry)->parseIes($ies), 10)['beam_angle'], 0.0001);
            $result = (new Lm79Calculations)->calculate(['power' => '10'], $ies, $path);
            $this->assertSame('60', $result['values']['beam_angle']);
            $this->assertSame('4', $result['values']['c_plane_count']);
            $this->assertSame('gos', $result['photometry_source']);
        } finally {
            unlink($path);
        }
    }

    public function test_expanded_inputs_and_rectangular_half_width_are_converted_before_combination(): void
    {
        $v = array_fill_keys(['u_power_meter', 'u_spec_mismatch', 'u_repeatability', 'u_gonio_dist', 'u_angular'], '0');
        $r = (new Lm79Calculations)->uncertainty([...$v, 'u_std_lamp' => '2', 'u_temp_half' => '3', 'total_flux' => '300', 'k_factor' => '2']);
        $this->assertEqualsWithDelta(1, $r['components']['标准灯/校准灯'], 1e-8);
        $this->assertEqualsWithDelta(1 / sqrt(3), $r['components']['温度影响'], 1e-8);
        $this->assertEqualsWithDelta(2 * sqrt(1 + 1 / 3), $r['U_percent'], 1e-8);
    }

    public function test_incomplete_inputs_never_produce_a_fabricated_uncertainty(): void
    {
        $this->assertSame([], (new Lm79Calculations)->uncertainty(['total_flux' => '']));
    }

    public function test_manual_flux_uncertainty_is_distinguished_from_the_component_budget(): void
    {
        $v = array_fill_keys(['u_power_meter', 'u_spec_mismatch', 'u_temp_half', 'u_repeatability', 'u_gonio_dist', 'u_angular'], '0');
        $r = (new Lm79Calculations)->uncertainty([...$v, 'u_std_lamp' => '2', 'total_flux' => '300', 'k_factor' => '4', 'u_flux' => '8', 'u_power_ac' => '1']);
        $this->assertEqualsWithDelta(4, $r['U_calculated_percent'], 1e-8);
        $this->assertEqualsWithDelta(8, $r['U_percent'], 1e-8);
        $this->assertEqualsWithDelta(4 * sqrt(4 + .25), $r['U_efficacy_percent'], 1e-8);
    }
}
