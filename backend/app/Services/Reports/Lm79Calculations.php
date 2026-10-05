<?php

namespace App\Services\Reports;

final class Lm79Calculations
{
    public function uncertainty(array $v): array
    {
        $required = ['u_std_lamp', 'u_power_meter', 'u_spec_mismatch', 'u_temp_half', 'u_repeatability', 'u_gonio_dist', 'u_angular', 'total_flux', 'k_factor'];
        foreach ($required as $key) {
            if (! isset($v[$key]) || ! is_numeric($v[$key]) || ! is_finite((float) $v[$key]) || (float) $v[$key] < 0) {
                return [];
            }
        }
        if ((float) $v['total_flux'] <= 0 || (float) $v['k_factor'] <= 0) {
            return [];
        }
        // Expanded inputs explicitly specify k=2; temperature is a rectangular half-width.
        $components = [
            '标准灯/校准灯' => (float) $v['u_std_lamp'] / 2,
            '功率计校准' => (float) $v['u_power_meter'] / 2,
            '光谱失配修正' => (float) $v['u_spec_mismatch'] / 2,
            '温度影响' => (float) $v['u_temp_half'] / sqrt(3) / (float) $v['total_flux'] * 100,
            '测量重复性' => (float) $v['u_repeatability'],
            '距离/杂散光' => (float) $v['u_gonio_dist'],
            '角度定位' => (float) $v['u_angular'],
        ];
        $uc = sqrt(array_sum(array_map(fn ($n) => $n * $n, $components)));
        $reportedU = isset($v['u_flux']) && is_numeric($v['u_flux']) && (float) $v['u_flux'] >= 0 ? (float) $v['u_flux'] : $uc * (float) $v['k_factor'];
        $result = ['components' => $components, 'uc_percent' => $uc, 'U_calculated_percent' => $uc * (float) $v['k_factor'], 'U_percent' => $reportedU];
        if (isset($v['u_power_ac']) && is_numeric($v['u_power_ac'])) {
            $result['U_efficacy_percent'] = sqrt(pow($reportedU / (float) $v['k_factor'], 2) + pow((float) $v['u_power_ac'] / 2, 2)) * (float) $v['k_factor'];
        }

        return $result;
    }

    public function calculate(array $values, ?string $ies, ?string $gosPath = null): array
    {
        $photometry = new Photometry;
        // Native GOS coordinates match its report; an IES export may rotate the C axis.
        $source = $gosPath !== null ? 'gos' : ($ies !== null ? 'ies' : 'manual');
        $data = $gosPath !== null ? $photometry->parseGos($gosPath)
            : ($ies !== null ? $photometry->parseIes($ies) : (trim($values['candela_data'] ?? '') !== '' ? $photometry->parseText($values['candela_data']) : null));
        $computed = $data ? $photometry->calculate($data, (float) ($values['power'] ?? 0)) : [];
        if (! $data && is_numeric($values['total_flux'] ?? '') && (float) ($values['power'] ?? 0) > 0) {
            $computed['efficacy'] = (float) $values['total_flux'] / (float) $values['power'];
        }
        foreach ($computed as $key => $value) {
            $values[$key] = $value === null ? '' : (is_numeric($value) ? rtrim(rtrim(sprintf('%.4f', $value), '0'), '.') : (string) $value);
        }
        $values['u_flux'] = '';
        $uncertainty = $this->uncertainty($values);
        if (isset($uncertainty['U_percent'])) {
            $values['u_flux'] = number_format($uncertainty['U_percent'], 4, '.', '');
        }

        return ['values' => $values, 'uncertainty' => $uncertainty, 'photometry_calculated' => $data !== null, 'photometry_source' => $source];
    }
}
