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
            foreach ($group['fields'] as $field) {
                if ($field['type'] === 'textarea' || in_array($field['name'], ['test_person', 'review_person', 'approve_person'])) {
                    continue;
                }
                $rows[] = [$field['label'], (string) (($v[$field['name']] ?? '') !== '' ? $v[$field['name']] : '—')];
            }
            if ($group['title'] === '样品资料') {
                $rows[] = ['系统样品编号', $report->sample_snapshot['sample_no']];
                $rows[] = ['本次检测样品数量', '1'];
            }
            if ($group['title'] === '色度参数' && trim($v['cri_r1_r15'] ?? '') !== '') {
                $rows[] = ['CRI R1–R15', $v['cri_r1_r15']];
            }
            $sections[] = ['title' => $group['title'], 'headers' => ['项目', '结果 / 信息'], 'rows' => $rows];
        }
        $sections[] = ['title' => '引用标准', 'headers' => ['序号', '标准'], 'rows' => array_map(fn ($s, $i) => [(string) ($i + 1), $s], $data['standards'], array_keys($data['standards']))];
        $sections[] = ['title' => '测试设备及校准溯源', 'headers' => ['名称 / 型号', '序列号', '校准证书编号', '校准机构', '校准有效期'], 'rows' => array_map(fn ($e) => [trim($e['name'].' / '.$e['model']), $e['serial'], $e['cal_cert'], $e['cal_org'], $e['cal_due']], $data['equipment'])];
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
            $sections[] = ['title' => '不确定度计算结果', 'headers' => ['分量 / 测量量', '相对标准或扩展不确定度 (%)'], 'rows' => $rows];
        }
        $sections[] = ['title' => '签署人员', 'headers' => ['角色', '姓名'], 'rows' => [['测试人', $v['test_person']], ['审核人', $v['review_person']], ['批准人', $v['approve_person']]]];
        $sections[] = ['title' => '声明', 'headers' => ['序号', '内容'], 'rows' => [
            ['1', '本报告未加盖检测专用章无效。'], ['2', '本报告部分复制无效。'],
            ['3', '本报告涂改或缺页无效。'], ['4', '如对测试结果有异议，请于收到报告后 5 个工作日内与实验室联系。'],
            ['5', '委托测试结果仅对被测样品负责。'], ['6', '如本报告未加盖资质认定标志章，则仅用于科研、教学、内部质量控制等目的。'],
            ['7', '光通量数据以本报告所选用的分布光度计结果为准，积分球数据仅作辅助参考。'],
        ]];
        $appendices = [];
        foreach (['pdf_gonio' => '附录 A. 光强分布测试报告', 'pdf_sphere' => '附录 B. 积分球测试报告'] as $collection => $title) {
            if ($media = $report->getFirstMedia($collection)) {
                $appendices[] = ['title' => $title, 'pdf' => base64_encode(file_get_contents($media->getPath()))];
            }
        }

        return [
            'reportNumber' => $report->report_number, 'labName' => $v['lab_name'], 'labAddress' => $v['lab_address'],
            'productName' => $v['product_name'], 'model' => $v['model'], 'applicant' => $v['applicant'],
            'receivedDate' => $v['test_date'], 'issuedDate' => $v['issue_date'], 'sections' => $sections,
            'photos' => $report->getMedia('photos')->map(fn ($m) => base64_encode(file_get_contents($m->getPath())))->all(),
            'spectrum' => $report->spectrumPoints->map(fn ($p) => [$p->wavelength, $p->relative_power])->all(), 'appendices' => $appendices,
        ];
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
