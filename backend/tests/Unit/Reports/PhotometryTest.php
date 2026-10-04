<?php

namespace Tests\Unit\Reports;

use App\Services\Reports\Photometry;
use PHPUnit\Framework\TestCase;

class PhotometryTest extends TestCase
{
    public function test_fractional_angles_remain_distinct_and_ies_tokens_can_cross_lines(): void
    {
        $ies = "IESNA:LM-63-2002\nTILT=NONE\n1 1000 1 3 1 1 2 0 0 0 1 1 10 0 0.5 90 0 100 80 0";
        $data = (new Photometry)->parseIes($ies);
        $this->assertSame([0.0, 0.5, 90.0], $data['gamma']);
        $this->assertSame([100.0, 80.0, 0.0], $data['intensity'][0]);
    }

    public function test_half_maximum_is_found_near_the_peak_on_both_sides(): void
    {
        $data = (new Photometry)->parseText("0 0 100\n0 10 80\n0 20 50\n0 30 20\n0 90 0\n180 0 100\n180 10 80\n180 20 50\n180 30 20\n180 90 0");
        $this->assertEqualsWithDelta(40, (new Photometry)->calculate($data, 10)['beam_angle'], 0.00001);
    }

    public function test_uniform_hemisphere_flux_and_zones_do_not_extrapolate_beyond_measured_angles(): void
    {
        $data = (new Photometry)->parseIes("IESNA:LM-63-2002\nTILT=NONE\n1 1000 1 3 1 1 2 0 0 0\n1 1 10\n0 45 90\n0\n100 100 100");
        $values = (new Photometry)->calculate($data, 10);
        $this->assertEqualsWithDelta(200 * M_PI, $values['total_flux'], 0.00001);
        $this->assertEqualsWithDelta(200 * M_PI * (1 - cos(deg2rad(30))), $values['zonal_0_30'], 0.00001);
        $this->assertEqualsWithDelta($values['total_flux'], $values['zonal_0_90'], 0.00001);
    }

    public function test_truncated_ies_is_rejected_instead_of_returning_partial_measurements(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Photometry)->parseIes("TILT=NONE\n1 1000 1 3 1 1 2 0 0 0 1 1 10 0 45 90 0 100");
    }
}
