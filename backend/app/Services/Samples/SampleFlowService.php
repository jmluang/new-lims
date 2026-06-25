<?php

namespace App\Services\Samples;

use App\Models\Sample;
use App\Models\SampleFlow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SampleFlowService
{
    /**
     * @param  array{action_type:string, holder_to?:?string, location_to?:?string, remark?:?string}  $data
     */
    public function record(User $user, Sample $sample, array $data): SampleFlow
    {
        $this->ensureActionAvailable($sample, $data['action_type']);
        $data = $this->normalizeActionData($user, $data);

        return DB::transaction(function () use ($user, $sample, $data): SampleFlow {
            $beforeHolder = $sample->current_holder;
            $beforeLocation = $sample->current_location;

            $sample->update([
                ...$this->updatesFor($sample, $data),
                'updated_by' => $user->id,
            ]);

            return $sample->flows()->create([
                'action_type' => $data['action_type'],
                'action_by' => $user->id,
                'action_time' => now(),
                'holder_from' => $beforeHolder,
                'holder_to' => $sample->current_holder,
                'location_from' => $beforeLocation,
                'location_to' => $sample->current_location,
                'remark' => $data['remark'] ?? null,
            ]);
        });
    }

    /**
     * @param  array{action_type:string, holder_to?:?string, location_to?:?string}  $data
     * @return array<string, string|null>
     */
    private function normalizeActionData(User $user, array $data): array
    {
        if (in_array($data['action_type'], ['lend', 'transfer'], true)) {
            $data['holder_to'] = $user->name;
        }

        return $data;
    }

    /**
     * @param  array{action_type:string, holder_to?:?string, location_to?:?string}  $data
     * @return array<string, string|null>
     */
    private function updatesFor(Sample $sample, array $data): array
    {
        return match ($data['action_type']) {
            'lend' => [
                'status' => 'testing',
                'current_holder' => $this->requiredText($data['holder_to'] ?? null, 'holder_to'),
                'current_location' => $this->text($data['location_to'] ?? null) ?? $sample->current_location,
            ],
            'transfer' => [
                'current_holder' => $this->requiredText($data['holder_to'] ?? null, 'holder_to'),
                'current_location' => $this->text($data['location_to'] ?? null) ?? $sample->current_location,
            ],
            'return_room' => [
                'status' => 'pending',
                'current_holder' => '样品室',
                'current_location' => $this->text($data['location_to'] ?? null) ?? $sample->current_location,
            ],
            'send_out' => [
                'status' => 'outsourced',
                'current_holder' => $this->requiredText($data['holder_to'] ?? null, 'holder_to'),
                'current_location' => $this->text($data['location_to'] ?? null) ?? $sample->current_location,
            ],
            'receive_back' => [
                'status' => 'outsource_returned',
                'current_holder' => '样品室',
                'current_location' => $this->text($data['location_to'] ?? null) ?? $sample->current_location,
            ],
            'return_client' => [
                'status' => 'returned',
                'current_holder' => $this->text($data['holder_to'] ?? null) ?? '客户',
                'current_location' => $this->text($data['location_to'] ?? null) ?? $sample->current_location,
            ],
            'scrap' => [
                'status' => 'scrapped',
                'current_location' => $this->text($data['location_to'] ?? null) ?? $sample->current_location,
            ],
            'position_change' => [
                'current_location' => $this->requiredText($data['location_to'] ?? null, 'location_to'),
            ],
            default => throw ValidationException::withMessages(['action_type' => ['invalid_sample_flow_action']]),
        };
    }

    private function ensureActionAvailable(Sample $sample, string $actionType): void
    {
        if (! in_array($actionType, $this->availableActions($sample), true)) {
            throw ValidationException::withMessages(['action_type' => ['sample_flow_action_not_available']]);
        }
    }

    /**
     * @return list<string>
     */
    private function availableActions(Sample $sample): array
    {
        $actions = [];

        if ($sample->status === 'pending' && $sample->current_holder === '样品室') {
            $actions[] = 'lend';
        }

        if ($sample->status === 'testing' && $sample->current_holder !== '样品室') {
            $actions[] = 'transfer';
            $actions[] = 'return_room';
        }

        if ($sample->status === 'outsourced') {
            $actions[] = 'receive_back';
        }

        if (! in_array($sample->status, ['returned', 'scrapped'], true)) {
            $actions[] = 'send_out';
            $actions[] = 'return_client';
            $actions[] = 'scrap';
            $actions[] = 'position_change';
        }

        return $actions;
    }

    private function requiredText(?string $value, string $field): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            throw ValidationException::withMessages([$field => ["{$field}_required"]]);
        }

        return $text;
    }

    private function text(?string $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
