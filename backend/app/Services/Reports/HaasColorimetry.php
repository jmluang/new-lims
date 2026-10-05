<?php

namespace App\Services\Reports;

/** Derived parameters matching the supplied HaasSuite instrument reports. */
final class HaasColorimetry
{
    // Legacy F-series MacAdam references from the instrument's sdcm.dat.
    // These are distinct from the separate ANSI377 reference table.
    private const REFERENCES = [
        [6500, 0.313, 0.337, 86, -40, 45],
        [5000, 0.346, 0.359, 56, -25, 28],
        [4000, 0.380, 0.380, 39.5, -21.5, 26],
        [3500, 0.409, 0.394, 38, -20, 25],
        [3000, 0.440, 0.403, 39, -19.5, 27.5],
        [2700, 0.463, 0.420, 44, -18.6, 27],
    ];

    // CIE 1931 2-degree observer, 380-780 nm at 5 nm, using the instrument's
    // Calc_Color.xyz precision. Its black-body calculation uses c2=1.4388e7 nm K.
    private const MATCHING = [
        [0.0014, 0.0, 0.0065],
        [0.0022, 0.0001, 0.0105],
        [0.0042, 0.0001, 0.0201],
        [0.0076, 0.0002, 0.0362],
        [0.0143, 0.0004, 0.0679],
        [0.0232, 0.0006, 0.1102],
        [0.0435, 0.0012, 0.2074],
        [0.0776, 0.0022, 0.3713],
        [0.1344, 0.004, 0.6456],
        [0.2148, 0.0073, 1.0391],
        [0.2839, 0.0116, 1.3856],
        [0.3285, 0.0168, 1.623],
        [0.3483, 0.023, 1.7471],
        [0.3481, 0.0298, 1.7826],
        [0.3362, 0.038, 1.7721],
        [0.3187, 0.048, 1.7441],
        [0.2908, 0.06, 1.6692],
        [0.2511, 0.0739, 1.5281],
        [0.1954, 0.091, 1.2876],
        [0.1421, 0.1126, 1.0419],
        [0.0956, 0.139, 0.813],
        [0.058, 0.1693, 0.6162],
        [0.032, 0.208, 0.4652],
        [0.0147, 0.2586, 0.3533],
        [0.0049, 0.323, 0.272],
        [0.0024, 0.4073, 0.2123],
        [0.0093, 0.503, 0.1582],
        [0.0291, 0.6082, 0.1117],
        [0.0633, 0.71, 0.0782],
        [0.1096, 0.7932, 0.0573],
        [0.1655, 0.862, 0.0422],
        [0.2257, 0.9149, 0.0298],
        [0.2904, 0.954, 0.0203],
        [0.3597, 0.9803, 0.0134],
        [0.4334, 0.995, 0.0087],
        [0.5121, 1.0, 0.0057],
        [0.5945, 0.995, 0.0039],
        [0.6784, 0.9786, 0.0027],
        [0.7621, 0.952, 0.0021],
        [0.8425, 0.9154, 0.0018],
        [0.9163, 0.87, 0.0017],
        [0.9786, 0.8163, 0.0014],
        [1.0263, 0.757, 0.0011],
        [1.0567, 0.6949, 0.001],
        [1.0622, 0.631, 0.0008],
        [1.0456, 0.5668, 0.0006],
        [1.0026, 0.503, 0.0003],
        [0.9384, 0.4412, 0.0002],
        [0.8544, 0.381, 0.0002],
        [0.7514, 0.321, 0.0001],
        [0.6424, 0.265, 0.0],
        [0.5419, 0.217, 0.0],
        [0.4479, 0.175, 0.0],
        [0.3608, 0.1382, 0.0],
        [0.2835, 0.107, 0.0],
        [0.2187, 0.0816, 0.0],
        [0.1649, 0.061, 0.0],
        [0.1212, 0.0446, 0.0],
        [0.0874, 0.032, 0.0],
        [0.0636, 0.0232, 0.0],
        [0.0468, 0.017, 0.0],
        [0.0329, 0.0119, 0.0],
        [0.0227, 0.0082, 0.0],
        [0.0158, 0.0057, 0.0],
        [0.0114, 0.0041, 0.0],
        [0.0081, 0.0029, 0.0],
        [0.0058, 0.0021, 0.0],
        [0.0041, 0.0015, 0.0],
        [0.0029, 0.001, 0.0],
        [0.002, 0.0007, 0.0],
        [0.0014, 0.0005, 0.0],
        [0.001, 0.0004, 0.0],
        [0.0007, 0.0002, 0.0],
        [0.0005, 0.0002, 0.0],
        [0.0003, 0.0001, 0.0],
        [0.0002, 0.0001, 0.0],
        [0.0002, 0.0001, 0.0],
        [0.0001, 0.0, 0.0],
        [0.0001, 0.0, 0.0],
        [0.0001, 0.0, 0.0],
        [0.0, 0.0, 0.0],
    ];

    public function calculate(float $x, float $y, float $cct): array
    {
        $empty = ['duv' => null, 'sdcm' => null, 'sdcm_target' => ''];
        if (! is_finite($x) || ! is_finite($y) || ! is_finite($cct)
            || $x <= 0 || $y <= 0 || $x + $y > 1 || $cct <= 0 || $cct >= 100000) {
            return $empty;
        }
        $xyz = [0.0, 0.0, 0.0];
        foreach (self::MATCHING as $index => $matching) {
            $wavelength = 380 + 5 * $index;
            $radiance = 1 / (pow($wavelength, 5) * (exp(1.4388e7 / ($wavelength * $cct)) - 1));
            foreach ($matching as $channel => $value) {
                $xyz[$channel] += $radiance * $value;
            }
        }
        $locusDenominator = $xyz[0] + 15 * $xyz[1] + 3 * $xyz[2];
        if (! is_finite($locusDenominator) || $locusDenominator <= 0) {
            return $empty;
        }
        $denominator = -2 * $x + 12 * $y + 3;
        $du = 4 * $x / $denominator - 4 * $xyz[0] / $locusDenominator;
        $dv = 6 * $y / $denominator - 6 * $xyz[1] / $locusDenominator;
        $duv = ($dv < 0 ? -1 : 1) * hypot($du, $dv);

        // Default to the nearest nominal F-series CCT; the editor remains editable.
        $reference = self::REFERENCES[0];
        foreach (self::REFERENCES as $candidate) {
            if (abs($candidate[0] - $cct) < abs($reference[0] - $cct)) {
                $reference = $candidate;
            }
        }
        [$temperature, $cx, $cy, $g11, $g12, $g22] = $reference;
        $dx = $x - $cx;
        $dy = $y - $cy;
        $sdcm = sqrt(max(0, $g11 * $dx * $dx + 2 * $g12 * $dx * $dy + $g22 * $dy * $dy)) * 100;

        return ['duv' => $duv, 'sdcm' => $sdcm,
            'sdcm_target' => 'F'.$temperature.', x='.$cx.' y='.$cy];
    }
}
