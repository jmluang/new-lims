<?php

namespace App\Services\Reports;

use App\Models\Lm79Report;
use Illuminate\Validation\ValidationException;

final class Lm79Payload
{
    public function build(Lm79Report $report): array
    {
        $data = $report->data;
        $v = $data['values'];
        if (trim($v['lab_name'] ?? '') === '' || trim($v['model'] ?? '') === '' || (float) ($v['power'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['values' => ['生成报告前请填写实验室名称、样品型号以及大于 0 的实测输入功率。']]);
        }
        $sections = [];
        foreach (Lm79Fields::GROUPS as $group) {
            $rows = [];
            $distributionRows = [];
            $fields = $group['fields'];
            if ($fields[0]['name'] === 'product_name') {
                $order = array_flip(['applicant', 'applicant_address', 'manufacturer', 'manufacturer_address',
                    'product_name', 'model', 'brand', 'serial_no', 'rated_voltage', 'rated_power',
                    'rated_flux', 'rated_cct', 'rated_cri', 'led_driver', 'led_module']);
                usort($fields, fn ($a, $b) => ($order[$a['name']] ?? 100) <=> ($order[$b['name']] ?? 100));
            }
            foreach ($fields as $field) {
                if ($field['type'] === 'textarea') {
                    continue;
                }
                $label = preg_replace('/\s*\[[^]]*\]/u', '', $field['label']);
                $row = [$label, $this->displayValue($field['name'], $v[$field['name']] ?? '')];
                if ($group['fields'][0]['name'] === 'total_flux'
                    && ! in_array($field['name'], ['total_flux', 'efficacy', 'lor'])) {
                    $distributionRows[] = $row;
                } else {
                    $rows[] = $row;
                }
            }
            if ($group['title'] === '样品资料') {
                $rows[] = ['系统样品编号', $report->sample_snapshot['sample_no']];
                $rows[] = ['本次检测样品数量', '1'];
            }
            if ($group['title'] === '色度参数' && trim($v['cri_r1_r15'] ?? '') !== '') {
                $rows[] = ['CRI R1–R15', $this->displayValue('cri_r1_r15', $v['cri_r1_r15'])];
            }
            $layout = match ($group['fields'][0]['name']) {
                'lab_name' => 'information', 'product_name' => 'sample', 'c_step' => 'setup',
                'ambient_temp' => 'conditions', 'voltage' => 'electrical',
                'cct' => 'color', 'total_flux' => 'photometric',
                'u_std_lamp' => 'uncertainty', 'spectrum_range' => 'spectrum', default => 'parameters',
            };
            $sections[] = ['title' => $group['title'], 'layout' => $layout, 'headers' => ['项目', '结果 / 信息'], 'rows' => $rows];
            if ($distributionRows !== []) {
                $sections[] = ['title' => '光强分布测试数据', 'layout' => 'distribution', 'headers' => ['项目', '结果 / 信息'], 'rows' => $distributionRows];
            }
        }
        $sections[] = ['title' => '引用标准', 'layout' => 'standards', 'headers' => ['序号', '标准'], 'rows' => array_map(fn ($s, $i) => [(string) ($i + 1), $s], $data['standards'], array_keys($data['standards']))];
        $sections[] = ['title' => '测试设备及校准溯源', 'layout' => 'equipment', 'headers' => ['名称 / 型号', '序列号', '校准证书编号', '校准机构', '校准有效期'], 'rows' => array_map(fn ($e) => [trim($e['name'].' / '.$e['model']), $e['serial'], $e['cal_cert'], $e['cal_org'], $e['cal_due']], $data['equipment'])];
        $uncertainty = (new Lm79Calculations)->uncertainty($v);
        if ($uncertainty !== []) {
            $rows = [];
            foreach ($uncertainty['components'] as $label => $value) {
                $rows[] = [$label, number_format($value, 4, '.', '')];
            }
            $rows[] = ['合成标准不确定度 uc (%)', number_format($uncertainty['uc_percent'], 4, '.', '')];
            $rows[] = ['按分量计算总光通量 U (%)，k='.$v['k_factor'], number_format($uncertainty['U_calculated_percent'], 4, '.', '')];
            $rows[] = ['采用的总光通量扩展不确定度 U (%)', number_format($uncertainty['U_percent'], 4, '.', '')];
            if (isset($uncertainty['U_efficacy_percent'])) {
                $rows[] = ['光效扩展不确定度 U (%)', number_format($uncertainty['U_efficacy_percent'], 4, '.', '')];
            }
            $sections[] = ['title' => '不确定度计算结果', 'layout' => 'uncertainty', 'headers' => ['分量 / 测量量', '相对标准或扩展不确定度 (%)'], 'rows' => $rows];
        }
        // Signer identity and handwritten appearances belong to the signing workflow.
        $sections[] = ['title' => '签署人员', 'layout' => 'signatures', 'headers' => ['角色', '姓名'], 'rows' => [['测试人', ''], ['审核人', ''], ['批准人', '']]];
        $appendices = [];
        foreach (['pdf_gonio' => '附录 A. 光强分布测试报告', 'pdf_sphere' => '附录 B. 积分球测试报告'] as $collection => $title) {
            if ($media = $report->getFirstMedia($collection)) {
                $appendices[] = ['title' => $title, 'pdf' => base64_encode(file_get_contents($media->getPath()))];
            }
        }

        return [
            'documentInfo' => config('pdf_service.report_layout'),
            'reportNumber' => $report->report_number, 'labName' => $v['lab_name'], 'labAddress' => $v['lab_address'],
            'productName' => $v['product_name'], 'model' => $v['model'], 'applicant' => $v['applicant'],
            'receivedDate' => $v['test_date'], 'issuedDate' => $v['issue_date'], 'sections' => $sections,
            'photos' => $report->getMedia('photos')->map(fn ($m) => base64_encode(file_get_contents($m->getPath())))->all(),
            'spectrum' => $report->spectrumPoints->map(fn ($p) => [$p->wavelength, $p->relative_power])->all(), 'appendices' => $appendices,
        ];
    }

    private function displayValue(string $field, string $value): string
    {
        if (trim($value) === '') {
            return '—';
        }
        if ($field === 'cri_r1_r15') {
            $parts = array_map('trim', explode(',', $value));
            if (count(array_filter($parts, fn ($part) => is_numeric($part) && is_finite((float) $part))) === count($parts)) {
                return implode(', ', array_map(fn ($part) => number_format((float) $part, 0, '.', ''), $parts));
            }
        }
        if (in_array($field, ['peak_wl', 'fwhm'], true) && preg_match('/^([\d.]+)\s*nm$/i', trim($value), $match) && is_numeric($match[1])) {
            return number_format((float) $match[1], $field === 'peak_wl' ? 0 : 1, '.', '').' nm';
        }
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return $value;
        }
        if ($field === 'power') {
            return sprintf('%.5g', (float) $value);
        }
        if ($field === 'duv') {
            return preg_replace('/e([+-])(\d)$/', 'e${1}0${2}', sprintf('%.2e', (float) $value));
        }
        $decimals = match ($field) {
            'voltage', 'frequency', 'total_flux', 'efficacy' => 2,
            'current', 'power_factor', 'cx', 'cy', 'cu', 'cv' => 4,
            'cct', 'cri_r9', 'tm30_rf', 'tm30_rg' => 0,
            'cri_ra', 'sdcm', 'beam_angle' => 1,
            default => null,
        };
        if ($decimals !== null) {
            return number_format((float) $value, $decimals, '.', '');
        }
        if ($field === 'peak_intensity' || str_starts_with($field, 'zonal_')) {
            return sprintf('%.4g', (float) $value);
        }

        return $value;
    }

    public function spectrum(string $text): array
    {
        $points = [];
        foreach (preg_split('/\R/', trim($text)) as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }
            $parts = preg_split('/[\s,;]+/', trim($line));
            if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])
                || ! is_finite((float) $parts[0]) || ! is_finite((float) $parts[1]) || (float) $parts[1] < 0) {
                throw ValidationException::withMessages(['values.spectrum_data' => ['光谱数据每行需要波长和非负相对强度两个数字。']]);
            }
            if ((float) $parts[0] <= 0) {
                throw ValidationException::withMessages(['values.spectrum_data' => ['波长必须大于 0。']]);
            }
            $points[] = array_map('floatval', $parts);
            if (count($points) > 10000) {
                throw ValidationException::withMessages(['values.spectrum_data' => ['光谱数据最多 10000 个测量点。']]);
            }
        }
        usort($points, fn ($a, $b) => $a[0] <=> $b[0]);
        if (trim($text) !== '' && (count($points) < 2 || $points[0][0] === end($points)[0] || max(array_column($points, 1)) <= 0)) {
            throw ValidationException::withMessages(['values.spectrum_data' => ['需要至少两个不同波长及大于 0 的光谱强度。']]);
        }

        return $points;
    }
}
