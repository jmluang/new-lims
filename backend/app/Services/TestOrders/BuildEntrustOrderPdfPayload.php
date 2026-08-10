<?php

namespace App\Services\TestOrders;

use App\Models\TestOrder;

class BuildEntrustOrderPdfPayload
{
    /**
     * @return array<string, mixed>
     */
    public function build(TestOrder $order): array
    {
        $order->loadMissing(['standards', 'samples']);

        return [
            'base' => [
                'entrust_date' => $order->order_date?->toDateString(),
                'urgency' => $this->enumValue($order->urgency, $this->urgencyLabel($order->urgency)),
                'urgency_options' => [
                    $this->enumValue('normal', '常规'),
                    $this->enumValue('urgent', '加急'),
                    $this->enumValue('critical', '特急'),
                ],
                'planned_end_date' => $order->planned_end_date?->toDateString(),
                'entrust_number' => $order->order_no,
                'contract_number' => $order->contract_no,
            ],
            'client' => $this->party($order, 'client'),
            'manufacturer' => $this->party($order, 'manufacturer'),
            'producer' => $this->party($order, 'maker'),
            'requirements' => [
                'report_forms' => collect($order->report_forms ?? [])
                    ->map(fn (string $value): array => $this->enumValue($value, $this->reportFormLabel($value)))
                    ->values()
                    ->all(),
                'report_form_options' => [
                    $this->enumValue('electronic_report', '电子档'),
                    $this->enumValue('paper_report', '纸本'),
                    $this->enumValue('formal_report', '正式报告'),
                    $this->enumValue('simple_report', '简版报告'),
                    $this->enumValue('english_report', '英文报告'),
                ],
                'sample_return' => $this->enumValue($order->sample_return, $this->sampleReturnLabel($order->sample_return)),
                'sample_return_options' => [
                    $this->enumValue('return', '是'),
                    $this->enumValue('destroy', '否（销毁处理）'),
                ],
                'report_submission' => $this->enumValue($order->delivery_method, $this->deliveryMethodLabel($order->delivery_method)),
                'report_submission_options' => [
                    $this->enumValue('self_pick', '自取'),
                    $this->enumValue('mail', '邮寄'),
                ],
                'allow_subcontract' => $this->enumValue($order->outsourcing_option, $this->outsourcingLabel($order->outsourcing_option)),
                'allow_subcontract_options' => [
                    $this->enumValue('allowed', '允许'),
                    $this->enumValue('not_allowed', '不允许'),
                ],
                'remarks' => $order->remark,
                'standards' => $order->standards
                    ->map(fn ($standard, int $index): array => [
                        'standard_code' => trim($standard->standard_code.' '.$standard->standard_name),
                        'qualification_requirement' => collect($standard->qualifications ?? [])->filter()->implode(','),
                        'report_language' => $this->reportLanguageLabel($standard->report_language),
                        'notes' => null,
                        'position' => $standard->sort_order ?? $index,
                    ])
                    ->values()
                    ->all(),
            ],
            'samples' => $samples = $order->samples
                ->map(fn ($sample): array => [
                    'name' => $sample->sample_name,
                    'model' => $sample->model,
                    'voltage' => $sample->input_voltage,
                    'current' => $sample->rated_current,
                    'power' => $sample->power,
                    'frequency' => $sample->rated_frequency,
                    'quantity' => $sample->quantity,
                    'quantity_unit' => $sample->quantity_unit,
                    'condition' => $this->enumValue($sample->sample_condition, $this->sampleConditionLabel($sample->sample_condition)),
                    'condition_note' => $sample->sample_condition_note,
                    'remarks' => $sample->remark,
                ])
                ->values()
                ->all(),
            // Current PDF renderers use `samples`; older deployed renderers use
            // the singular `sample`. Send both so every renderer receives the
            // actual first sample instead of falling back to template data.
            'sample' => $samples[0] ?? null,
            'logistics' => [
                'laboratory_name' => $order->address_lab_name ?: '中山市鑫普达检测有限公司',
                'laboratory_address' => $order->address_detail ?: '广东省中山市古镇镇东兴东路33号7栋1层之一',
                'laboratory_contact' => $order->address_contact ?: '鑫普达检测',
                'laboratory_phone' => $order->address_phone,
                'shipping_notes' => $order->shipping_notes,
            ],
            'signatures' => [
                'client_signature_name' => $order->client_signature,
                'client_signed_at' => $order->client_sign_date?->toDateString(),
                'lab_resource_confirmed_by' => $order->dept_confirm,
                'lab_resource_confirmed_at' => $order->dept_confirm_date?->toDateString(),
                'lab_reviewed_by' => $order->lab_confirm,
                'lab_reviewed_at' => $order->lab_confirm_date?->toDateString(),
            ],
            'meta' => [
                'status' => $this->enumValue($order->sample_status, $order->sample_status),
                'generated_at' => now()->toDateTimeString(),
            ],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function party(TestOrder $order, string $prefix): array
    {
        return [
            'company_name' => $order->getAttribute("{$prefix}_company"),
            'contact' => $order->getAttribute("{$prefix}_contact"),
            'phone' => $order->getAttribute("{$prefix}_phone"),
            'address' => $order->getAttribute("{$prefix}_address"),
            'email' => $order->getAttribute("{$prefix}_email"),
        ];
    }

    /**
     * @return array{value: string, label: string}|null
     */
    private function enumValue(?string $value, ?string $label): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ['value' => $value, 'label' => $label ?: $value];
    }

    private function urgencyLabel(?string $value): ?string
    {
        return match ($value) {
            'normal' => '常规',
            'urgent' => '加急',
            'critical' => '特急',
            default => $value,
        };
    }

    private function reportFormLabel(?string $value): ?string
    {
        return match ($value) {
            'electronic', 'electronic_report' => '电子档',
            'paper', 'paper_report' => '纸本',
            'formal_report' => '正式报告',
            'simple_report' => '简版报告',
            'english_report' => '英文报告',
            default => $value,
        };
    }

    private function sampleReturnLabel(?string $value): ?string
    {
        return match ($value) {
            'return' => '是',
            'destroy' => '否（销毁处理）',
            default => $value,
        };
    }

    private function deliveryMethodLabel(?string $value): ?string
    {
        return match ($value) {
            'self_pick' => '自取',
            'mail' => '邮寄',
            default => $value,
        };
    }

    private function outsourcingLabel(?string $value): ?string
    {
        return match ($value) {
            'allowed' => '允许',
            'not_allowed' => '不允许',
            default => $value,
        };
    }

    private function reportLanguageLabel(?string $value): ?string
    {
        return match ($value) {
            'zh' => '中文',
            'en' => '英文',
            default => $value,
        };
    }

    private function sampleConditionLabel(?string $value): ?string
    {
        return match ($value) {
            'good' => '完好',
            'abnormal' => '异常',
            default => $value,
        };
    }
}
