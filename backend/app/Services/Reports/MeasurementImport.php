<?php

namespace App\Services\Reports;

use InvalidArgumentException;
use Throwable;

final class MeasurementImport
{
    public function parse(string $path, string $expected, ?int $record = null, float $power = 0): array
    {
        if ($expected === 'ies') {
            if (! is_file($path) || filesize($path) > 10 * 1024 * 1024) {
                throw new InvalidArgumentException('IES 文件无法读取或超过 10 MB，请重新选择。');
            }
            $photometry = new Photometry;

            return ['kind' => 'ies', 'records' => [], 'selected_record' => 1,
                'values' => $this->photometryValues($photometry->calculate($photometry->parseIes(file_get_contents($path)), $power)),
                'spectrum_point_count' => 0, 'notice' => '已解析并回填配光参数。'];
        }
        require_once __DIR__.'/Instrument/EverfineFormats.php';
        try {
            if (! is_file($path) || filesize($path) > 20 * 1024 * 1024) {
                throw new InvalidArgumentException('Measurement file size is invalid.');
            }
            $file = Instrument\parseMeasurement($path);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('文件无法解析，请确认是完整的 GOS 或 HAAS 原始测量文件。', previous: $e);
        }
        $kind = isset($file['records']) ? 'haas' : 'gos';
        if ($kind !== $expected) {
            throw new InvalidArgumentException('文件内容与上传区域不匹配，请选择对应的 GOS 或 HAAS 文件。');
        }
        $records = $kind === 'haas' ? $file['records'] : [$file];
        if ($records === []) {
            throw new InvalidArgumentException('文件没有可用的检测记录。');
        }
        $options = array_map(fn ($r, $index) => [
            'index' => $index + 1, 'model' => mb_substr($r['model'] ?? $r['instrument_model'] ?? '', 0, 255),
            'sample' => mb_substr($r['sample_no'] ?? $r['sample_name'] ?? '', 0, 255),
            'date' => mb_substr($r['timestamp'] ?? $r['test_date'] ?? '', 0, 255),
        ], $records, array_keys($records));
        $record ??= count($records) === 1 ? 1 : null;
        if ($record !== null && ! isset($records[$record - 1])) {
            throw new InvalidArgumentException('所选检测记录不存在，请重新选择。');
        }
        $values = $record !== null ? $this->values($kind, $records[$record - 1]) : [];
        if ($kind === 'gos') {
            $photometry = new Photometry;
            // Native GOS photometry must replace earlier IES values regardless of upload order.
            $computed = $photometry->calculate($photometry->parseGos($path), (float) ($values['power'] ?? $power));
            unset($computed['total_flux'], $computed['efficacy']);
            $values = array_replace($this->photometryValues($computed), $values);
            if ((float) ($values['power'] ?? $power) > 0) {
                $values['efficacy'] = $this->number((float) $values['total_flux'] / (float) ($values['power'] ?? $power));
            }
        }

        return ['kind' => $kind, 'records' => $options, 'selected_record' => $record, 'values' => $values,
            'spectrum_point_count' => $kind === 'haas' && $record !== null ? count($records[$record - 1]['spectrum']) : 0,
            'notice' => $record === null ? '请选择检测记录。' : ($kind === 'gos'
                ? (isset($values['voltage']) ? '已回填电气与原始配光参数。' : '已回填可用参数；基础电气数据均为 0。')
                    .($file['has_displacement_factor'] ? '' : '位移因数无有效数据。')
                : '已回填色度与光谱。')];
    }

    private function photometryValues(array $computed): array
    {
        return array_map(fn ($value) => $value === null ? '' : (is_numeric($value) ? $this->number($value) : (string) $value), $computed);
    }

    private function number(mixed $value): string
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException('文件包含无效的测量值，请检查原始文件。');
        }

        return rtrim(rtrim(sprintf('%.8F', (float) $value), '0'), '.') ?: '0';
    }

    private function values(string $kind, array $record): array
    {
        $values = [];
        foreach (['ambient_temp' => $kind === 'gos' ? 'temperature' : 'temperature_c', 'humidity' => $kind === 'gos' ? 'humidity' : 'humidity_pct'] as $target => $source) {
            if (is_numeric($record[$source] ?? null)) {
                $values[$target] = $this->number($record[$source]);
            }
        }
        if ($kind === 'gos') {
            $values['total_flux'] = $this->number($record['flux_lm']);
            // An all-zero header is used by files without an electrical measurement.
            if (array_filter($record['electrical'], fn ($value) => $value != 0.0) !== []) {
                foreach ($record['electrical'] as $field => $value) {
                    $values[$field] = $this->number($value);
                }
            }
            $values['frequency'] = $this->number($record['frequency_hz']);
            // An unavailable DF becomes a blank input, clearing any earlier imported value.
            $values['displacement_factor'] = $record['displacement_factor'] === null
                ? '' : $this->number($record['displacement_factor']);

            return $values;
        }
        $c = $record['colorimetry'];
        foreach (['cct' => 'cct_k', 'cx' => 'x', 'cy' => 'y', 'cu' => 'u_prime', 'cv' => 'v_prime', 'cri_ra' => 'ra'] as $target => $source) {
            $values[$target] = $this->number($c[$source]);
        }
        $values['cri_r9'] = $this->number($c['r'][8]);
        $values['cri_r1_r15'] = implode(', ', array_map($this->number(...), $c['r']));
        $values['peak_wl'] = $this->number($c['peak_wl_nm']).' nm';
        $values['fwhm'] = $this->number($c['fwhm_nm']).' nm';
        $derived = (new HaasColorimetry)->calculate((float) $c['x'], (float) $c['y'], (float) $c['cct_k']);
        foreach (['duv', 'sdcm'] as $field) {
            $values[$field] = $derived[$field] === null ? '' : $this->number($derived[$field]);
        }
        $values['sdcm_target'] = $derived['sdcm_target'];
        $start = (float) $this->number($record['wl_start_nm']);
        $step = (float) $this->number($record['wl_step_nm']);
        $values['spectrum_range'] = $this->number($start).'-'.$this->number($record['wl_end_nm']).' nm';
        $values['spectrum_interval'] = $this->number($step);
        $lines = [];
        foreach ($record['spectrum'] as $index => $value) {
            if (! is_finite($value) || $value < 0) {
                throw new InvalidArgumentException('光谱包含无效或负的强度，请检查原始文件。');
            }
            $lines[] = $this->number($start + $index * $step).' '.(string) $value;
        }
        $values['spectrum_data'] = implode("\n", $lines);
        app(Lm79Payload::class)->spectrum($values['spectrum_data']);

        return $values;
    }
}
