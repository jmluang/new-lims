<?php

namespace App\Services\Reports;

/** The LM-79 form contract, shared with the editor through form-options. */
final class Lm79Fields
{
    public const GROUPS = [
        ['title' => '实验室与日期', 'fields' => [
            ['name' => 'lab_name', 'label' => '实验室名称', 'default' => '中山市鑫普达检测有限公司', 'numeric' => false, 'type' => 'text'],
            ['name' => 'lab_address', 'label' => '实验室地址', 'default' => '广东省中山市古镇镇东兴东路33号7栋1楼部分', 'numeric' => false, 'type' => 'text'],
            ['name' => 'accreditation', 'label' => 'CNAS 认可号', 'default' => 'CNAS L12345', 'numeric' => false, 'type' => 'text'],
            ['name' => 'test_date', 'label' => '样品接收日期', 'default' => '', 'numeric' => false, 'type' => 'date'],
            ['name' => 'issue_date', 'label' => '签发日期', 'default' => '', 'numeric' => false, 'type' => 'date'],
        ]],
        ['title' => '样品资料', 'fields' => [
            ['name' => 'product_name', 'label' => '产品名称', 'default' => '', 'numeric' => false, 'type' => 'text'],
            ['name' => 'model', 'label' => '型号', 'default' => '', 'numeric' => false, 'type' => 'text'],
            ['name' => 'manufacturer', 'label' => '制造商', 'default' => '', 'numeric' => false, 'type' => 'text'],
            ['name' => 'manufacturer_address', 'label' => '制造商地址', 'default' => '', 'numeric' => false, 'type' => 'text'],
            ['name' => 'applicant', 'label' => '申请人', 'default' => '', 'numeric' => false, 'type' => 'text'],
            ['name' => 'applicant_address', 'label' => '申请人地址', 'default' => '', 'numeric' => false, 'type' => 'text'],
            ['name' => 'brand', 'label' => '品牌', 'default' => '', 'numeric' => false, 'type' => 'text'],
            ['name' => 'serial_no', 'label' => '序列号', 'default' => '', 'numeric' => false, 'type' => 'text'],
            ['name' => 'rated_voltage', 'label' => '额定电压', 'default' => '', 'numeric' => false, 'type' => 'text'],
            ['name' => 'rated_power', 'label' => '额定功率 (W)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'rated_flux', 'label' => '标称光通量 (lm)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'rated_cct', 'label' => '标称色温 (K)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'rated_cri', 'label' => '标称显色指数', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'led_driver', 'label' => 'LED 驱动器型号', 'default' => '', 'numeric' => false, 'type' => 'text'],
            ['name' => 'led_module', 'label' => 'LED 模组型号', 'default' => '', 'numeric' => false, 'type' => 'text'],
        ]],
        ['title' => '测试条件', 'fields' => [
            ['name' => 'ambient_temp', 'label' => '环境温度 (°C) [25±1.2]', 'default' => '25.3', 'numeric' => true, 'type' => 'text'],
            ['name' => 'ambient_temp_tol', 'label' => '温度容差 (±°C)', 'default' => '1.0', 'numeric' => true, 'type' => 'text'],
            ['name' => 'humidity', 'label' => '相对湿度 (%) [10-65]', 'default' => '65.0', 'numeric' => true, 'type' => 'text'],
            ['name' => 'air_flow', 'label' => '气流条件', 'default' => '0.2 m/s', 'numeric' => false, 'type' => 'text'],
            ['name' => 'orientation', 'label' => '测试方向', 'default' => 'Base-Down', 'numeric' => false, 'type' => 'text'],
            ['name' => 'stabilization_time', 'label' => '稳定时间 (min) [≥30]', 'default' => '30', 'numeric' => true, 'type' => 'text'],
            ['name' => 'seasoning_time', 'label' => '老化时间 (h) [可选]', 'default' => '0', 'numeric' => true, 'type' => 'text'],
            ['name' => 'test_duration', 'label' => '测试周期', 'default' => '', 'numeric' => false, 'type' => 'text'],
        ]],
        ['title' => '电气参数', 'fields' => [
            ['name' => 'voltage', 'label' => '输入电压 (V)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'current', 'label' => '输入电流 (A)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'power', 'label' => '输入功率 (W)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'power_factor', 'label' => '功率因数 (PF)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'current_thd', 'label' => '电流谐波失真 THD (%)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'frequency', 'label' => '频率 (Hz) [50±2]', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'displacement_factor', 'label' => '位移因数 (DF) [可选]', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'voltage_regulation', 'label' => '电压调节率 (%) [±0.2]', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'waveform_thd', 'label' => '电源波形 THD (%) [<3]', 'default' => '', 'numeric' => true, 'type' => 'text'],
        ]],
        ['title' => '色度参数', 'fields' => [
            ['name' => 'cct', 'label' => '相关色温 CCT (K)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'duv', 'label' => 'Duv', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'cri_ra', 'label' => '显色指数 Ra', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'cri_r9', 'label' => '显色指数 R9', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'cri_r1_r15', 'label' => 'CRI R1-R15（逗号分隔）', 'default' => '', 'numeric' => false, 'type' => 'textarea'],
            ['name' => 'cx', 'label' => '色坐标 x', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'cy', 'label' => '色坐标 y', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'cu', 'label' => '色坐标 u\'', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'cv', 'label' => '色坐标 v\'', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'tm30_rf', 'label' => 'TM-30 Rf', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'tm30_rg', 'label' => 'TM-30 Rg', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'sdcm', 'label' => '色容差 SDCM', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'sdcm_target', 'label' => 'SDCM 目标色点', 'default' => 'ANSI F3000 (3000K)', 'numeric' => false, 'type' => 'text'],
            ['name' => 'peak_wl', 'label' => '峰值波长', 'default' => '', 'numeric' => false, 'type' => 'text'],
            ['name' => 'fwhm', 'label' => 'FWHM', 'default' => '', 'numeric' => false, 'type' => 'text'],
        ]],
        ['title' => '光度参数', 'fields' => [
            ['name' => 'total_flux', 'label' => '总光通量 (lm)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'efficacy', 'label' => '光效 (lm/W)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'peak_intensity', 'label' => '峰值光强 (cd)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'center_intensity', 'label' => '中心光强 (cd)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'beam_angle', 'label' => '光束角 C0/180 (50%) (°)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'zonal_0_30', 'label' => '区域光通量 0–30° (lm)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'zonal_0_60', 'label' => '区域光通量 0–60° (lm)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'zonal_0_90', 'label' => '区域光通量 0–90° (lm)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'zonal_0_120', 'label' => '区域光通量 0–120° (lm)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'zonal_0_180', 'label' => '区域光通量 0–180° (lm)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'lor', 'label' => '光输出比 LOR (%)', 'default' => '100.0', 'numeric' => true, 'type' => 'text'],
            ['name' => 'shielding_angle', 'label' => '遮光角', 'default' => '', 'numeric' => true, 'type' => 'text'],
        ]],
        ['title' => '分布光度计设置', 'fields' => [
            ['name' => 'c_step', 'label' => 'C 角度间隔 (°)', 'default' => '15', 'numeric' => true, 'type' => 'text'],
            ['name' => 'g_step', 'label' => 'γ 角度间隔 (°)', 'default' => '1', 'numeric' => true, 'type' => 'text'],
            ['name' => 'c_range', 'label' => 'C 测试范围', 'default' => '0-345', 'numeric' => false, 'type' => 'text'],
            ['name' => 'g_range', 'label' => 'γ 测试范围', 'default' => '0-90', 'numeric' => false, 'type' => 'text'],
            ['name' => 'measure_distance', 'label' => '测量距离 (m)', 'default' => '9.0', 'numeric' => true, 'type' => 'text'],
            ['name' => 'angular_accuracy', 'label' => '角度精度 (°)', 'default' => '0.1', 'numeric' => true, 'type' => 'text'],
            ['name' => 'c_plane_count', 'label' => 'C 平面数', 'default' => '24', 'numeric' => true, 'type' => 'text'],
            ['name' => 'g_point_count', 'label' => 'γ 点数', 'default' => '91', 'numeric' => true, 'type' => 'text'],
        ]],
        ['title' => '不确定度分量', 'fields' => [
            ['name' => 'u_std_lamp', 'label' => '标准灯/校准灯 扩展U (%, k=2)', 'default' => '1.5', 'numeric' => true, 'type' => 'text'],
            ['name' => 'u_power_meter', 'label' => '功率计校准 扩展U (%, k=2)', 'default' => '0.5', 'numeric' => true, 'type' => 'text'],
            ['name' => 'u_spec_mismatch', 'label' => '光谱失配修正 扩展U (%, k=2)', 'default' => '0.8', 'numeric' => true, 'type' => 'text'],
            ['name' => 'u_temp_half', 'label' => '温度影响 半宽 (lm)', 'default' => '0.2', 'numeric' => true, 'type' => 'text'],
            ['name' => 'u_repeatability', 'label' => '测量重复性 标准u (%)', 'default' => '0.3', 'numeric' => true, 'type' => 'text'],
            ['name' => 'u_gonio_dist', 'label' => '距离/杂散光 标准u (%)', 'default' => '0.5', 'numeric' => true, 'type' => 'text'],
            ['name' => 'u_angular', 'label' => '角度定位 标准u (%)', 'default' => '0.3', 'numeric' => true, 'type' => 'text'],
            ['name' => 'u_cct', 'label' => 'CCT U (K)', 'default' => '50', 'numeric' => true, 'type' => 'text'],
            ['name' => 'u_cx', 'label' => '色坐标 x U', 'default' => '0.0015', 'numeric' => true, 'type' => 'text'],
            ['name' => 'u_cy', 'label' => '色坐标 y U', 'default' => '0.0015', 'numeric' => true, 'type' => 'text'],
            ['name' => 'u_cri', 'label' => '显色指数 Ra U', 'default' => '1.0', 'numeric' => true, 'type' => 'text'],
            ['name' => 'u_flux', 'label' => '总光通量扩展U (%)', 'default' => '', 'numeric' => true, 'type' => 'text'],
            ['name' => 'k_factor', 'label' => '包含因子 k', 'default' => '2', 'numeric' => true, 'type' => 'text'],
            ['name' => 'u_voltage_ac', 'label' => '电压扩展U (%) [≤0.4]', 'default' => '0.2', 'numeric' => true, 'type' => 'text'],
            ['name' => 'u_current_ac', 'label' => '电流扩展U (%) [≤0.6]', 'default' => '0.3', 'numeric' => true, 'type' => 'text'],
            ['name' => 'u_power_ac', 'label' => '功率扩展U (%) [≤1.0]', 'default' => '0.5', 'numeric' => true, 'type' => 'text'],
        ]],
        ['title' => '光谱与配光数据', 'fields' => [
            ['name' => 'spectrum_range', 'label' => '波长范围', 'default' => '380-780 nm', 'numeric' => false, 'type' => 'text'],
            ['name' => 'spectrum_interval', 'label' => '扫描间隔 (nm)', 'default' => '1', 'numeric' => true, 'type' => 'text'],
            ['name' => 'wavelength_accuracy', 'label' => '波长精度 (nm) [≤0.5]', 'default' => '0.5', 'numeric' => true, 'type' => 'text'],
            ['name' => 'spectrum_data', 'label' => '光谱数据（每行：波长 相对强度）', 'default' => '', 'numeric' => false, 'type' => 'textarea'],
            ['name' => 'candela_data', 'label' => '配光数据（每行：C角 γ角 光强cd）', 'default' => '', 'numeric' => false, 'type' => 'textarea'],
        ]],
    ];

    public static function defaults(): array
    {
        $values = [];
        foreach (self::GROUPS as $group) {
            foreach ($group['fields'] as $field) {
                $values[$field['name']] = $field['default'];
            }
        }
        $values['issue_date'] = now()->toDateString();

        return $values;
    }

    public static function rules(): array
    {
        $rules = ['values' => ['required', 'array:'.implode(',', array_keys(self::defaults()))]];
        foreach (self::GROUPS as $group) {
            foreach ($group['fields'] as $field) {
                $rules['values.'.$field['name']] = ['nullable', 'string', 'max:'.(in_array($field['name'], ['spectrum_data', 'candela_data'], true) ? 4000000 : 1000)];
            }
        }

        return $rules;
    }
}
