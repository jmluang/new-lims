<?php

namespace Tests\Unit\Reports;

use App\Models\Lm79Report;
use App\Models\Lm79SpectrumPoint;
use App\Services\Reports\Lm79Fields;
use App\Services\Reports\Lm79Payload;
use Illuminate\Database\Eloquent\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class Lm79PayloadTest extends TestCase
{
    public function test_unsigned_report_leaves_names_to_the_signing_workflow(): void
    {
        $payload = (new Lm79Payload)->build($this->report());
        $signatures = collect($payload['sections'])->firstWhere('layout', 'signatures');
        $this->assertSame([['测试人', ''], ['审核人', ''], ['批准人', '']], $signatures['rows']);
    }

    public function test_report_values_use_reference_precision_without_mutating_measurements(): void
    {
        $report = $this->report();
        $data = $report->data;
        $data['values'] = array_replace($data['values'], ['voltage' => '220.10699463', 'current' => '0.140646',
            'power' => '30.15740013', 'power_factor' => '0.974163', 'frequency' => '50',
            'total_flux' => '2463.12884', 'efficacy' => '81.675769', 'peak_intensity' => '5958.3', 'beam_angle' => '36.19569075']);
        $report->data = $data;
        $rows = collect((new Lm79Payload)->build($report)['sections'])->flatMap(fn ($s) => $s['rows'])->mapWithKeys(fn ($row) => [$row[0] => $row[1]]);
        foreach (['输入电压 (V)' => '220.11', '输入电流 (A)' => '0.1406', '输入功率 (W)' => '30.157',
            '功率因数 (PF)' => '0.9742', '频率 (Hz)' => '50.00', '总光通量 (lm)' => '2463.13',
            '光效 (lm/W)' => '81.68', '峰值光强 (cd)' => '5958', '光束角 C0/180 (50%) (°)' => '36.2'] as $label => $value) {
            $this->assertSame($value, $rows[$label], $label);
        }
        $this->assertSame('30.15740013', $report->data['values']['power']);
    }

    public function test_power_presentation_uses_five_significant_digits_for_both_reference_ranges(): void
    {
        foreach (['30.15740013' => '30.157', '100.7559967' => '100.76', '6.18601942' => '6.186'] as $raw => $expected) {
            $report = $this->report();
            $data = $report->data;
            $data['values']['power'] = $raw;
            $report->data = $data;
            $rows = collect((new Lm79Payload)->build($report)['sections'])->flatMap(fn ($s) => $s['rows']);
            $this->assertSame($expected, $rows->first(fn ($row) => $row[0] === '输入功率 (W)')[1]);
            $this->assertSame($raw, $report->data['values']['power']);
        }
    }

    public function test_duv_presentation_matches_the_instrument_scientific_notation(): void
    {
        foreach (['-0.00124459' => '-1.24e-03', '0.00762676' => '7.63e-03'] as $raw => $expected) {
            $report = $this->report();
            $data = $report->data;
            $data['values']['duv'] = $raw;
            $report->data = $data;
            $rows = collect((new Lm79Payload)->build($report)['sections'])->flatMap(fn ($s) => $s['rows']);
            $this->assertSame($expected, $rows->first(fn ($row) => $row[0] === 'Duv')[1]);
        }
    }

    public function test_layout_metadata_preserves_scalar_fields_and_separates_spectrum_input(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $report = $this->report();
        $payload = (new Lm79Payload)->build($report);
        $sections = collect($payload['sections'])->keyBy('title');
        $this->assertSame('sample', $sections['样品资料']['layout']);
        $this->assertSame('color', $sections['色度参数']['layout']);
        $this->assertSame('conditions', $sections['测试条件']['layout']);
        $this->assertSame('electrical', $sections['电气参数']['layout']);
        $this->assertSame('photometric', $sections['光度参数']['layout']);
        $this->assertCount(3, $sections['光度参数']['rows']);
        $this->assertSame('distribution', $sections['光强分布测试数据']['layout']);
        $this->assertCount(9, $sections['光强分布测试数据']['rows']);
        $this->assertSame('standards', $sections['引用标准']['layout']);
        $this->assertSame('equipment', $sections['测试设备及校准溯源']['layout']);
        $this->assertSame('signatures', $sections['签署人员']['layout']);
        $this->assertArrayNotHasKey('声明', $sections->all());
        $this->assertSame('申请人', $sections['样品资料']['rows'][0][0]);
        $labels = collect($payload['sections'])->flatMap(fn ($s) => array_column($s['rows'], 0))->all();
        foreach (Lm79Fields::GROUPS as $group) {
            foreach ($group['fields'] as $field) {
                if (in_array($field['name'], ['spectrum_data', 'candela_data', 'test_person', 'review_person', 'approve_person'], true)) {
                    continue;
                }
                $label = $field['name'] === 'cri_r1_r15' ? 'CRI R1–R15' : preg_replace('/\s*\[[^]]*\]/u', '', $field['label']);
                $this->assertContains($label, $labels, $field['name']);
            }
        }
        $this->assertCount(5, $payload['spectrum']);
        $this->assertSame(['1', 'IEC 60598-1:2024'], $sections['引用标准']['rows'][0]);
        $this->assertStringNotContainsString('[可选]', json_encode($payload['sections'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('V1.0', $payload['documentInfo']['version']);
        if ($target = getenv('LM79_PAYLOAD_FIXTURE')) {
            file_put_contents($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
    }

    private function report(): Lm79Report
    {
        config(['pdf_service.report_layout' => ['fileNumber' => 'FO-22-03-2402', 'version' => 'V1.0',
            'website' => 'http://www.xinpuda.net/', 'email' => 'service@xinpuda.net']]);
        $values = Lm79Fields::defaults();
        foreach (Lm79Fields::GROUPS as $group) {
            foreach ($group['fields'] as $field) {
                if ($field['numeric'] && $values[$field['name']] === '') {
                    $values[$field['name']] = '1.25';
                }
            }
        }
        $values = array_replace($values, [
            'lab_name' => '中山市鑫普达检测有限公司', 'lab_address' => '中山市古镇镇东兴东路33号7栋1楼', 'accreditation' => '未提供',
            'product_name' => 'LED投光灯', 'model' => 'TDLED-300-50W-6500K', 'applicant' => '中山市XXXX照明科技有限公司',
            'applicant_address' => "10th Floor, No. 167 North Chang'an Road, Henglan Town, Zhongshan City, Guangdong Province, China",
            'manufacturer' => 'Zhongshan sunray Lighting Co., Ltd.',
            'manufacturer_address' => "10th Floor, No. 167 North Chang'an Road, Henglan Town, Zhongshan City, Guangdong Province, China",
            'brand' => '/', 'serial_no' => 'SN-20260101-001', 'rated_voltage' => 'AC 220V', 'rated_power' => '12',
            'rated_flux' => '14554', 'rated_cct' => '4000', 'rated_cri' => '80', 'led_driver' => 'DRIVER-001', 'led_module' => 'MODULE-001',
            'test_date' => '2026-09-14', 'issue_date' => '2026-09-30', 'test_duration' => '2026-09-14 to 2026-09-16',
            'test_person' => 'Dingmin Zhang', 'review_person' => 'Xue Li', 'approve_person' => 'Tom Wu',
            'voltage' => '220.1', 'current' => '0.1832', 'power' => '40.15', 'power_factor' => '0.985', 'frequency' => '50',
            'cct' => '4025', 'duv' => '0.0023', 'cri_ra' => '83.2', 'cri_r9' => '12.5',
            'cri_r1_r15' => '84.2, 82.1, 80.4, 85.3, 81.6, 83.5, 82.8, 86.1, 12.5, 78.3, 81.4, 79.2, 84.7, 88.6, 80.9',
            'cx' => '0.3821', 'cy' => '0.3795', 'cu' => '0.2254', 'cv' => '0.5032', 'tm30_rf' => '82.5', 'tm30_rg' => '98',
            'total_flux' => '14554', 'efficacy' => '362.4907', 'peak_intensity' => '4623.5', 'center_intensity' => '4410.2',
            'beam_angle' => '36.5', 'peak_wl' => '450 nm', 'fwhm' => '24 nm', 'u_flux' => '2.5',
        ]);
        $report = new class extends Lm79Report
        {
            public function getFirstMedia(string $collectionName = 'default', $filters = []): ?Media
            {
                return null;
            }

            public function getMedia(string $collectionName = 'default', array|callable $filters = []): MediaCollection
            {
                return new MediaCollection;
            }
        };
        $report->forceFill(['report_number' => 'REPORT-001', 'sample_snapshot' => ['sample_no' => 'SAMPLE-001'],
            'data' => ['values' => $values, 'standards' => ['IEC 60598-1:2024', 'ANSI/IES LM-79-19'],
                'equipment' => [
                    ['name' => '分布光度计', 'model' => 'GO-5000', 'serial' => 'EQ-001', 'cal_cert' => 'CAL-001', 'cal_org' => 'Calibration Laboratory', 'cal_due' => '2027-09-30'],
                    ['name' => '积分球光谱仪', 'model' => 'HAAS-2000', 'serial' => 'EQ-002', 'cal_cert' => 'CAL-002', 'cal_org' => 'Calibration Laboratory', 'cal_due' => '2027-09-30'],
                ]]]);
        $report->setRelation('spectrumPoints', new Collection(array_map(fn ($p) => new Lm79SpectrumPoint([
            'wavelength' => $p[0], 'relative_power' => $p[1],
        ]), [[380, 0.1], [450, 1], [550, 0.65], [650, 0.25], [780, 0.05]])));

        return $report;
    }
}
