<?php

namespace App\Services\Samples;

use App\Models\Sample;
use App\Models\TestOrder;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveSamples
{
    public function __construct(
        private readonly SampleNumberService $sampleNumberService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{samples: array<int, Sample>, delivery_received_count: int, rejected_count: int}
     */
    public function receive(User $actor, TestOrder $testOrder, array $payload): array
    {
        $rows = collect($payload['samples'] ?? []);
        $acceptedRows = $rows
            ->filter(fn (array $row): bool => trim((string) ($row['reject_reason'] ?? '')) === '')
            ->values();
        $rejectedRows = $rows
            ->filter(fn (array $row): bool => trim((string) ($row['reject_reason'] ?? '')) !== '')
            ->values();

        if ($acceptedRows->isEmpty()) {
            throw ValidationException::withMessages([
                'samples' => ['samples_required'],
            ]);
        }

        return DB::transaction(function () use ($actor, $testOrder, $payload, $acceptedRows, $rejectedRows): array {
            $deliverySequence = $this->sampleNumberService->nextDeliverySequence($testOrder);
            $deliveryReceivedCount = $acceptedRows->count();
            $orderSamplesById = $testOrder->samples()->get()->keyBy('id');
            $createdSamples = [];

            foreach ($rejectedRows as $row) {
                $this->auditLogger->record(
                    actor: $actor,
                    action: 'samples.receive.rejected',
                    module: 'samples',
                    subject: $testOrder,
                    after: [
                        'test_order_id' => $testOrder->id,
                        'test_order_sample_id' => $row['test_order_sample_id'] ?? null,
                        'sample_name' => $row['sample_name'] ?? null,
                        'reject_reason' => $row['reject_reason'],
                    ],
                );

                if (! empty($row['test_order_sample_id'])) {
                    $testOrder->samples()
                        ->whereKey($row['test_order_sample_id'])
                        ->update(['status' => 'rejected']);
                }
            }

            foreach ($acceptedRows as $index => $row) {
                $sampleIndex = $index + 1;
                $orderSample = $orderSamplesById->get($row['test_order_sample_id'] ?? null);
                $sample = Sample::query()->create([
                    'test_order_id' => $testOrder->id,
                    'test_order_sample_id' => $row['test_order_sample_id'] ?? null,
                    'delivery_sequence' => $deliverySequence,
                    'sample_no' => $this->sampleNumberService->sampleNo($testOrder, $deliverySequence, $sampleIndex, $deliveryReceivedCount),
                    'sample_name' => $row['sample_name'],
                    'specification' => $row['specification'] ?? null,
                    'model' => $row['model'] ?? null,
                    'input_voltage' => $row['input_voltage'] ?? null,
                    'rated_current' => $row['rated_current'] ?? null,
                    'power' => $row['power'] ?? null,
                    'rated_frequency' => $row['rated_frequency'] ?? null,
                    'quantity' => 1,
                    'status' => 'pending',
                    'current_holder' => '样品室',
                    'current_location' => $payload['current_location'] ?? null,
                    'storage_condition' => $payload['storage_condition'] ?? null,
                    'received_date' => $payload['received_date'] ?? now()->toDateString(),
                    'appearance_check' => $row['appearance_check'] ?? null,
                    'remark' => array_key_exists('remark', $row) ? $row['remark'] : $orderSample?->remark,
                    'batch_no' => $payload['batch_no'] ?? null,
                    'sort_order' => $sampleIndex,
                    'delivery_received_count' => $deliveryReceivedCount,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $sample->flows()->create([
                    'action_type' => 'receive',
                    'action_by' => $actor->id,
                    'action_time' => now(),
                    'holder_from' => null,
                    'holder_to' => '样品室',
                    'location_from' => null,
                    'location_to' => $payload['current_location'] ?? null,
                    'remark' => '样品接收',
                ]);

                if (! empty($row['test_order_sample_id'])) {
                    $testOrder->samples()
                        ->whereKey($row['test_order_sample_id'])
                        ->update(['status' => 'received']);
                }

                $createdSamples[] = $sample->fresh('flows');
            }

            $testOrder->update(['sample_status' => 'received']);

            return [
                'samples' => $createdSamples,
                'delivery_received_count' => $deliveryReceivedCount,
                'rejected_count' => $rejectedRows->count(),
            ];
        });
    }
}
