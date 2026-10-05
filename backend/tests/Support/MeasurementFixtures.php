<?php

namespace Tests\Support;

final class MeasurementFixtures
{
    public static function ies(): string
    {
        return "IESNA:LM-63-2002\nTILT=NONE\n1 -1 1 3 1 1 2 0 0 0\n1 1 10\n0 45 90\n0\n100 100 100";
    }

    private static function text(string $value): string
    {
        $bytes = mb_convert_encoding($value, 'GB18030', 'UTF-8');

        return (strlen($bytes) < 255 ? chr(strlen($bytes)) : "\xff".pack('v', strlen($bytes))).$bytes;
    }

    public static function haas(array $temperatures = [5585]): string
    {
        $bytes = self::text('LED300_B').pack('V2', 0, count($temperatures));
        foreach ($temperatures as $index => $cct) {
            $bytes .= self::text('LED300_DATA_A').self::text('HAAS_TEST').pack('V4', 0, 0, 0, 0)
                .pack('g', 1).pack('V2', 1, 1).pack('g2', 1, 300).pack('V2', 1, 1);
            foreach (['LED lamp', (string) ($index + 1), 'Factory', '25.3', '65', 'Operator', '2026-10-04', ''] as $value) {
                $bytes .= self::text($value);
            }
            $bytes .= pack('V', 0).pack('g4', 74, 1, 400, 402).pack('V', 3).pack('g3', .1, 1, .2)
                .pack('V', 65536).pack('g*', 52324, 300, 11, .33, .34, .20, .47, $cct, 14, 540, 451, 2, 20, 83.6)
                .pack('g*', ...range(80, 94)).pack('g3', 0, 0, 0);
        }

        return $bytes.self::text('OP_O');
    }

    public static function gos(array $electrical = [220, 0.125, 27.5, 1], array $details = [], float $frequency = 50, int $hasDisplacement = 0, float $displacement = 0, ?array $photometry = null): string
    {
        $bytes = self::text('GODATA 100').self::text('V2.0').pack('V3', 1, 0, 2).self::text('GO_TEST')
            .pack('g2', 9, 9).pack('V2', 10000, 1);
        foreach (['', '25.3', '65', '', '', '', '', ''] as $value) {
            $bytes .= self::text($value);
        }
        $bytes .= pack('g', 600).pack('V', 6).self::text('LED lamp');
        foreach (array_pad($details, 7, '') as $detail) {
            $bytes .= self::text($detail);
        }
        $bytes .= pack('g4', ...$electrical).pack('V', 1)
            .self::text('Example Lab').self::text('2026-10-04').self::text('EVERFINE SYSTEM');
        $photometry ??= ['gamma' => range(0, 15), 'planes' => [0, 90, 180, 270], 'intensity' => array_fill(0, 4, array_fill(0, 16, 1))];
        $bytes .= pack('V2', count($photometry['gamma']), count($photometry['planes']))
            .pack('g*', ...$photometry['gamma']).pack('g*', ...$photometry['planes']);
        foreach ($photometry['gamma'] as $g => $_) {
            foreach ($photometry['planes'] as $c => $_) {
                $bytes .= pack('g', $photometry['intensity'][$c][$g]);
            }
        }

        return $bytes.self::text('OP V102').pack('g3', 50, 2, 12).str_repeat("\0", 12)
            .pack('g3', 90, 10, .7).str_repeat("\0", 12).pack('g*', 100, 200, 300, ...array_fill(0, 16, 0))
            .pack('g', $frequency).pack('V2', 0, 0).self::text('R_V1').pack('V3', 0, 0, 3)
            .pack('g3', 1, 2, 3).pack('V', 0).pack('g2', 0, 0).pack('V', $hasDisplacement).pack('g', $displacement);
    }
}
