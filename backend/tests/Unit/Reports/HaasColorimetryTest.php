<?php

namespace Tests\Unit\Reports;

use App\Services\Reports\HaasColorimetry;
use PHPUnit\Framework\TestCase;

class HaasColorimetryTest extends TestCase
{
    public function test_derived_color_parameters_match_both_instrument_reference_reports(): void
    {
        $calculator = new HaasColorimetry;
        $warm = $calculator->calculate(0.4040684103965759, 0.387286514043808, 3499.10009765625);
        $this->assertEqualsWithDelta(-0.001244591492456028, $warm['duv'], 1e-9);
        $this->assertEqualsWithDelta(2.6956036966863244, $warm['sdcm'], 1e-8);
        $this->assertSame('F3500, x=0.409 y=0.394', $warm['sdcm_target']);
        $cool = $calculator->calculate(0.3152734935283661, 0.34031060338020325, 6300);
        $this->assertEqualsWithDelta(0.007626756704286495, $cool['duv'], 1e-8);
        $this->assertEqualsWithDelta(1.8319035448641892, $cool['sdcm'], 0.00001);
        $this->assertSame('F6500, x=0.313 y=0.337', $cool['sdcm_target']);
    }

    public function test_invalid_chromaticity_and_out_of_range_cct_do_not_create_measurements(): void
    {
        foreach ([[0, 0, 0], [0.4, 0.4, 100000], [NAN, 0.3, 3500], [0.8, 0.8, 3500]] as [$x, $y, $cct]) {
            $this->assertSame(['duv' => null, 'sdcm' => null, 'sdcm_target' => ''], (new HaasColorimetry)->calculate($x, $y, $cct));
        }
    }

    public function test_reference_centres_have_zero_sdcm_instead_of_a_missing_value(): void
    {
        foreach ([[6500, 0.313, 0.337], [5000, 0.346, 0.359], [4000, 0.380, 0.380],
            [3500, 0.409, 0.394], [3000, 0.440, 0.403], [2700, 0.463, 0.420]] as [$cct, $x, $y]) {
            $result = (new HaasColorimetry)->calculate($x, $y, $cct);
            $this->assertSame(0.0, $result['sdcm']);
            $this->assertStringStartsWith('F'.$cct.',', $result['sdcm_target']);
        }
    }
}
