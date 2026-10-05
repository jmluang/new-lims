<?php

namespace App\Services\Reports;

use InvalidArgumentException;

/** Type-C measurements use indexed coordinates, never floating-point array keys. */
final class Photometry
{
    public function parseGos(string $path): array
    {
        require_once __DIR__.'/Instrument/EverfineFormats.php';
        try {
            $record = Instrument\parseGos($path);
            $bytes = file_get_contents($path);
        } catch (\Throwable $error) {
            throw new InvalidArgumentException('GOS 配光数据无法读取，请核对原始文件。', previous: $error);
        }
        $grids = array_values(array_filter($record['blocks'], fn ($block) => $block['angles_a'][0] == 0
            && end($block['angles_a']) <= 180 && $block['angles_b'][0] == 0 && end($block['angles_b']) <= 360));
        if (count($grids) !== 1) {
            throw new InvalidArgumentException('GOS 文件没有唯一可识别的 C-γ 配光矩阵。');
        }
        $grid = $grids[0];
        $count = $grid['dim_a'] * $grid['dim_b'];
        $offset = $grid['offset'] + 8 + 4 * ($grid['dim_a'] + $grid['dim_b']);
        if ($count > 500000 || $bytes === false || $offset + 4 * $count > strlen($bytes)) {
            throw new InvalidArgumentException('GOS 配光矩阵不完整或超过 500000 个测量点。');
        }
        // GODATA 100 stores each gamma row across all C planes.
        $flat = array_values(unpack('g'.$count, $bytes, $offset));
        $matrix = [];
        for ($c = 0; $c < $grid['dim_b']; $c++) {
            $row = [];
            for ($g = 0; $g < $grid['dim_a']; $g++) {
                $row[] = $flat[$g * $grid['dim_b'] + $c];
            }
            $matrix[] = $row;
        }

        return $this->validated($grid['angles_b'], $grid['angles_a'], $matrix);
    }

    public function parseIes(string $text): array
    {
        if (! preg_match('/^TILT\s*=\s*(\S+)\s*$/mi', $text, $match, PREG_OFFSET_CAPTURE)) {
            throw new InvalidArgumentException('IES 文件缺少 TILT 声明。');
        }
        if (strtoupper($match[1][0]) !== 'NONE') {
            throw new InvalidArgumentException('当前支持 TILT=NONE 的 IES 文件，请从仪器软件导出无倾斜修正的 Type-C 配光数据。');
        }
        $tokens = preg_split('/[\s,]+/', trim(substr($text, $match[0][1] + strlen($match[0][0]))));
        foreach ($tokens as $token) {
            if (! is_numeric($token) || ! is_finite((float) $token)) {
                throw new InvalidArgumentException('IES 数值数据无效。');
            }
        }
        if (count($tokens) < 13) {
            throw new InvalidArgumentException('IES 文件数据不完整。');
        }
        $v = (int) $tokens[3];
        $h = (int) $tokens[4];
        if ($v < 2 || $h < 1 || $v * $h > 500000 || (int) $tokens[5] !== 1
            || (float) $tokens[3] !== (float) $v || (float) $tokens[4] !== (float) $h
            || (float) $tokens[2] <= 0 || count($tokens) !== 13 + $v + $h + $v * $h) {
            throw new InvalidArgumentException('需要完整的 Type-C IES 数据，测量点数最多 500000。');
        }
        $gamma = array_map('floatval', array_slice($tokens, 13, $v));
        $planes = array_map('floatval', array_slice($tokens, 13 + $v, $h));
        $matrix = array_chunk(array_map(fn ($n) => (float) $n * (float) $tokens[2], array_slice($tokens, 13 + $v + $h)), $v);

        return $this->validated($planes, $gamma, $matrix);
    }

    public function parseText(string $text): array
    {
        $rows = [];
        $planes = [];
        $gamma = [];
        foreach (preg_split('/\R/', trim($text)) as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }
            $parts = preg_split('/[\s,;]+/', trim($line));
            if (count($parts) !== 3 || count(array_filter($parts, 'is_numeric')) !== 3) {
                throw new InvalidArgumentException('配光数据每行需要 C 角、γ 角和光强三个数字。');
            }
            [$c, $g, $i] = array_map('floatval', $parts);
            $planes[] = $c;
            $gamma[] = $g;
            $key = json_encode([$c, $g]);
            if (isset($rows[$key])) {
                throw new InvalidArgumentException('配光数据包含重复的角度测量点。');
            }
            $rows[$key] = $i;
            if (count($rows) > 500000) {
                throw new InvalidArgumentException('配光测量点数最多 500000。');
            }
        }
        $planes = array_values(array_unique($planes));
        sort($planes);
        $gamma = array_values(array_unique($gamma));
        sort($gamma);
        $matrix = [];
        foreach ($planes as $c) {
            $row = [];
            foreach ($gamma as $g) {
                $key = json_encode([$c, $g]);
                if (! isset($rows[$key])) {
                    throw new InvalidArgumentException('配光数据缺少测量点，请提供完整矩阵。');
                }
                $row[] = $rows[$key];
            }
            $matrix[] = $row;
        }

        return $this->validated($planes, $gamma, $matrix);
    }

    private function validated(array $planes, array $gamma, array $matrix): array
    {
        foreach ([[$planes, 360], [$gamma, 180]] as [$angles, $max]) {
            if ($angles === [] || $angles[0] < 0 || end($angles) > $max) {
                throw new InvalidArgumentException('配光角度范围无效。');
            }
            for ($i = 1; $i < count($angles); $i++) {
                if ($angles[$i] <= $angles[$i - 1]) {
                    throw new InvalidArgumentException('配光角度必须严格递增。');
                }
            }
        }
        $periodic = count($planes) > 1 && $this->step($planes) !== null && abs(end($planes) + $this->step($planes) - 360) < 1e-6;
        if (count($gamma) < 2 || $planes[0] != 0 || (count($planes) > 1 && ! in_array(end($planes), [90, 180, 360]) && ! $periodic)) {
            throw new InvalidArgumentException('C 平面需从 0° 开始，单平面旋转对称，或覆盖到 90°、180°、360°。');
        }
        foreach ($matrix as $row) {
            foreach ($row as $i) {
                if (! is_finite($i) || $i < 0) {
                    throw new InvalidArgumentException('光强必须为有限的非负数。');
                }
            }
        }

        return ['planes' => $planes, 'gamma' => $gamma, 'intensity' => $matrix];
    }

    public function calculate(array $data, float $power): array
    {
        $planes = $this->expandedPlanes($data);
        $flux = fn ($limit) => $this->flux($data, $planes, $limit);
        $total = $flux(180);
        $center = 0.0;
        foreach ($planes as $i => $p) {
            if ($i > 0) {
                $center += deg2rad($p[0] - $planes[$i - 1][0]) * ($this->atGamma($data['gamma'], $p[1], 0) + $this->atGamma($data['gamma'], $planes[$i - 1][1], 0)) / (4 * M_PI);
            }
        }
        $sourceEnd = end($data['planes']);
        $step = $this->step($data['planes']);
        $periodic = $step !== null && abs($sourceEnd + $step - 360) < 1e-6;
        $result = ['total_flux' => $total, 'efficacy' => $power > 0 ? $total / $power : null,
            'peak_intensity' => max(array_map('max', $data['intensity'])), 'center_intensity' => $center,
            'beam_angle' => $this->beam($data, $planes),
            'c_range' => $data['planes'][0].'-'.($periodic ? 360 : $sourceEnd), 'g_range' => $data['gamma'][0].'-'.end($data['gamma']),
            'c_plane_count' => count($data['planes']) - ($sourceEnd == 360 ? 1 : 0), 'g_point_count' => count($data['gamma']),
            'c_step' => $this->step($data['planes']), 'g_step' => $this->step($data['gamma'])];
        foreach ([30, 60, 90, 120, 180] as $limit) {
            $result['zonal_0_'.$limit] = $flux($limit);
        }

        return $result;
    }

    private function step(array $angles): ?float
    {
        if (count($angles) < 2) {
            return null;
        }
        $step = $angles[1] - $angles[0];
        for ($i = 2; $i < count($angles); $i++) {
            if (abs($angles[$i] - $angles[$i - 1] - $step) > 1e-6) {
                return null;
            }
        }

        return $step;
    }

    private function expandedPlanes(array $data): array
    {
        $rows = [];
        $end = end($data['planes']);
        foreach ($data['planes'] as $i => $c) {
            $angles = count($data['planes']) === 1 ? [0, 360] : ($end == 90 ? [$c, 180 - $c, 180 + $c, 360 - $c] : ($end == 180 ? [$c, 360 - $c] : [$c]));
            foreach ($angles as $angle) {
                $rows[(string) $angle] = [$angle, $data['intensity'][$i]];
            }
        }
        if (! isset($rows['360'])) {
            $rows['360'] = [360.0, $data['intensity'][0]];
        }
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);

        return $rows;
    }

    private function atGamma(array $gamma, array $row, float $g): float
    {
        if ($g < $gamma[0] || $g > end($gamma)) {
            return 0;
        }
        for ($i = 1; $i < count($gamma); $i++) {
            if ($g <= $gamma[$i]) {
                return $row[$i - 1] + ($row[$i] - $row[$i - 1]) * ($g - $gamma[$i - 1]) / ($gamma[$i] - $gamma[$i - 1]);
            }
        }

        return end($row);
    }

    private function rowIntegral(array $gamma, array $row, float $limit): float
    {
        $sum = 0.0;
        for ($i = 1; $i < count($gamma); $i++) {
            $lo = deg2rad($gamma[$i - 1]);
            $hi = deg2rad(min($limit, $gamma[$i]));
            if ($hi <= $lo) {
                continue;
            }
            // Trapezoidal intensity in solid angle reproduces the native ring-flux table.
            $end = deg2rad($gamma[$i]);
            $weight = 2 * sin(($lo + $hi) / 2) * sin(($hi - $lo) / 2);
            $fullWeight = 2 * sin(($lo + $end) / 2) * sin(($end - $lo) / 2);
            $endIntensity = $row[$i - 1] + ($row[$i] - $row[$i - 1]) * $weight / $fullWeight;
            $sum += ($row[$i - 1] + $endIntensity) / 2 * $weight;
        }

        return $sum;
    }

    private function flux(array $data, array $planes, float $limit): float
    {
        $sum = 0.0;
        for ($i = 1; $i < count($planes); $i++) {
            $sum += deg2rad($planes[$i][0] - $planes[$i - 1][0]) * ($this->rowIntegral($data['gamma'], $planes[$i - 1][1], $limit) + $this->rowIntegral($data['gamma'], $planes[$i][1], $limit)) / 2;
        }

        return $sum;
    }

    private function beam(array $data, array $planes): ?float
    {
        $opposite = null;
        foreach ($planes as $i => $p) {
            if ($p[0] == 180) {
                $opposite = $p[1];
            } elseif ($i > 0 && $p[0] > 180 && $planes[$i - 1][0] < 180) {
                $ratio = (180 - $planes[$i - 1][0]) / ($p[0] - $planes[$i - 1][0]);
                $opposite = array_map(fn ($a, $b) => $a + ($b - $a) * $ratio, $planes[$i - 1][1], $p[1]);
            }
        }
        $points = [];
        foreach (array_reverse($data['gamma'], true) as $i => $g) {
            $points[] = [-$g, $opposite[$i] ?? $planes[0][1][$i]];
        }
        foreach ($data['gamma'] as $i => $g) {
            if ($g > 0) {
                $points[] = [$g, $planes[0][1][$i]];
            }
        }
        $peak = array_search(max(array_column($points, 1)), array_column($points, 1));
        $half = $points[$peak][1] / 2;
        if ($half <= 0) {
            return null;
        }
        $crossings = [];
        foreach ([-1, 1] as $direction) {
            for ($i = $peak + $direction; $i >= 0 && $i < count($points); $i += $direction) {
                if ($points[$i][1] <= $half) {
                    [$x1, $y1] = $points[$i - $direction];
                    [$x2, $y2] = $points[$i];
                    $crossings[] = $x1 + ($half - $y1) * ($x2 - $x1) / ($y2 - $y1);
                    break;
                }
            }
        }

        return count($crossings) === 2 ? abs($crossings[1] - $crossings[0]) : null;
    }
}
